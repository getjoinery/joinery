# Fix: api_guest_credential flakes on the X-Cache HIT assertion

## Status
Implemented.

## Problem

The `api_guest_credential` functional suite
(`tests/functional/api/guest_credential_test.php`) had one check that flaked:

```
✗ an X-Cache HIT was observed within 5 fetches of / (page cache must be live on dev)
```

Section 5 fetches `/` and requires at least one response to carry
`X-Cache: HIT`. Its purpose is real and worth keeping: the anonymous browser
credential distributes the `joinery_api_csrf` mirror cookie *even when a page
is served straight from the static cache*, because the cache-serve path still
runs `SessionControl`. The only honest way to prove that is to observe a
genuine cache HIT and confirm the cookie still lands. A silent skip would leave
the whole reason the mirror cookie exists untested.

The check failed intermittently on dev even though the credential code is
correct. The bug was in the test.

## Root cause

The static cache only *creates* an entry for a request that looks like a real
browser. `StaticPageCache::shouldCache()` (and `shouldIgnore()`) reject any
request whose `User-Agent` contains `curl`, `wget`, `python-requests`, … or is
empty — a deliberate anti-bot/anti-tooling guard.

Every request the suite makes is cURL with the default `curl/x.y` User-Agent.
So the suite can never populate the cache itself; it can only ride an entry
that *real browser traffic* left behind earlier. Serving a HIT does not check
the User-Agent (`checkCache()` is a pure index lookup), which is why the suite
usually saw a HIT — a browser had visited `/` recently.

The flake appears whenever that pre-existing entry is gone at test time:

- a deploy `clearAll`, or
- the 1% serve-time freshness roll in `RouteHelper::route()` invalidating `/`,
  or
- `/` being marked `nostatic` after one uncacheable render.

Once the entry is gone and no browser has repopulated it, every cURL fetch in
the suite misses and the check fails — through no fault of the credential.

## Fix

Warm `/` from the test with one browser-User-Agent GET before observing the
HIT. Because the warming request is served by the origin, Apache writes the
cache entry under its own ownership, and because serving a HIT ignores the
User-Agent, the warmed entry is then served to the suite's ordinary cURL jar.

In section 5, before the HIT loop:

1. Issue one `guest_request('GET', '/', $jar, array($browser_ua))` with a
   realistic desktop-Chrome `User-Agent` header. This passes `shouldCache()`
   and causes the origin to cache `/`.
2. Observe the HIT with the visitor's normal jar (default UA), looping up to 8
   times so the 1% serve-time freshness roll cannot produce an all-miss run
   now that the entry is known to exist.
3. If no HIT is seen even after warming, fail with a message that names the
   real remaining cause — the static cache is disabled on dev
   (`/admin/admin_static_cache`) — instead of the old ambiguous
   no-HIT-within-N-fetches wording.

The downstream assertions are unchanged and still run against a real
HIT-served response: cached HTML carries no `joinery-api-csrf` meta tag, and
the mirror cookie is still valid for the visitor after the HIT.

## Rejected approach: priming the cache from the CLI

An earlier attempt primed the cache directly through `StaticPageCache`
(`invalidateUrl('/')` + a warm GET) since the suite boots the harness on the
origin box. This is wrong on two counts and must not be reintroduced:

- **cURL cannot create the entry.** A warm GET over cURL is refused by
  `shouldCache()` for the same User-Agent reason, so CLI invalidation just
  leaves `/` uncached with nothing able to rebuild it over cURL.
- **CLI writes corrupt the index ownership.** `invalidateUrl()` /
  `setEnabled()` call `saveIndex()`, rewriting `cache/static_pages/index.json`
  as the CLI user. Apache (www-data) then cannot update the index, silently
  freezing the entire page cache until ownership is restored. The cache index
  must only ever be written by the web-server process.

The browser-UA HTTP warm avoids both: it never touches the cache store from the
CLI, and every cache write is performed by Apache under the correct owner.

## Non-goals

- Do not weaken the check into a skip or soft pass. Observing a real HIT is the
  point of the section.
- Do not change `RouteHelper` or `StaticPageCache`. The User-Agent gate and the
  1% freshness roll are correct; only the test's assumption about ambient cache
  state was wrong.

## Acceptance

- `php tests/run.php db --filter=api_guest_credential` passes on repeated runs,
  including immediately after the `/` cache entry has been removed (verified:
  deleting `cache/static_pages/index.html` and running the suite still passes,
  27/27 checks).
- The mirror-cookie-on-HIT assertions still execute against a genuine
  `X-Cache: HIT` response.
- Dev's page cache is left healthy: `index.json` and the `/` entry remain
  owned by www-data after a run.
