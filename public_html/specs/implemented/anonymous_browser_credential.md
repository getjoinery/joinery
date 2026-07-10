# Anonymous Browser Credential — Guests on /api/v1

## Status: implemented — pending live guest-payment acceptance run

Resolves the guest-checkout fork parked at store extraction
(`specs/implemented/store_and_event_manager_plugin_extraction.md` § the four
deferred checkout actions): **anonymous sessions become a first-class API
credential**, so guest-reachable page JS calls `/api/v1` like everything else
and the legacy `/ajax/checkout_ajax.php` and `/ajax/cookie_consent.php`
endpoints are deleted. This is the platform half; the checkout actions
themselves are already specified in the extraction spec's table (its § "API
actions", `checkout_apply_coupon` / `checkout_remove_coupon` /
`checkout_check_email` / `checkout_submit_survey`) and are built here
unchanged.

## Problem

A visitor checking out without an account has a PHP session (the cart lives in
`$_SESSION` — `ShoppingCart::current()`, plugins/store/includes/ShoppingCart.php:58)
but no login. The API's browser credential requires both: the CSRF meta tag is
emitted only for logged-in users (`PublicPageBase.php:583`) and
`ApiAuth::authenticateBrowserSession()` rejects any session without a user id
(ApiAuth.php:190). So guests cannot call `/api/v1` at all, checkout JS still
posts to `/ajax/checkout_ajax`, and the whole anonymous-caller category
(checkout, cookie consent) is stuck on the legacy transport.

## Design

One new credential kind: **anonymous browser session** — a session cookie plus
a matching `X-Joinery-Csrf` header, with no logged-in user. It authenticates
(proves "same-origin JS running in this visitor's browser") but authorizes
almost nothing: only actions that explicitly declare `allow_guest` accept it.
Fail-closed everywhere else.

### 1 — Token distribution survives the page cache

The CSRF token is already session-scoped, not user-scoped
(`SessionControl::get_api_csrf_token()`, SessionControl.php:735 — mints into
`$_SESSION` on first read). The problem is distribution: anonymous GETs are
served from the static page cache (RouteHelper.php:1038 — cache applies to
non-logged-in users only), and cached HTML is shared across visitors, so a
per-visitor meta tag must never be baked into a cacheable page.

Distribution therefore splits by audience:

- **Logged-in pages** (never cached): the existing meta tag, unchanged.
- **Everyone**: a mirror cookie **`joinery_api_csrf`** — value equal to the
  session's `api_csrf_token`, `Secure`, `SameSite=Lax`, **not** `HttpOnly`
  (JS must read it). Set by `SessionControl` right after `session_start()`
  (constructor, SessionControl.php:90): if headers are not yet sent and the
  cookie is absent or differs from the session value, mint/refresh it. This
  runs on cache HITs too (the cache-serve path has already instantiated
  SessionControl), so a first-time visitor landing on a cached page still
  gets a working token. The cookie is *distribution only* — `ApiAuth`
  continues to validate the header against the raw session value
  (ApiAuth.php:195-198); the cookie is never the trust anchor, so this is not
  a double-submit scheme and cookie tossing by a sibling subdomain buys
  nothing.

**StaticPageCache integration:** the cacheability check vetoes any response
that sets a non-session cookie (StaticPageCache.php:577-584). Add
`joinery_api_csrf` to the session-cookie exemption there — it is per-visitor
transport, like the session cookie itself, and the cached body never contains
it.

JS pattern for guest-reachable pages (documented in docs/api.md; no new shared
asset — callers are few). Cookie first: the cookie tracks the current session
(resynced every response — survives a logout in another tab or session
expiry), while the meta tag is frozen at render and is only the cookie-less
fallback:

```js
var csrf = decodeURIComponent((document.cookie.match(/(?:^|; )joinery_api_csrf=([^;]+)/) || [,''])[1])
        || (document.querySelector('meta[name="joinery-api-csrf"]') || {}).content || '';
```

### 2 — ApiAuth: the anonymous principal

`authenticateBrowserSession()` (ApiAuth.php:176) changes in one place: when
the session has no user but the `X-Joinery-Csrf` header matches the session
token, return an **anonymous principal** instead of failing —
`['api_entry' => null, 'api_user' => null, 'auth_data' => [...]]`. A missing
or wrong header fails exactly as today (the failed-auth `api_auth` rate
bucket, apiv1.php:278-280, still counts it). A session cookie with no CSRF
header also fails as today — behavior for every existing caller is unchanged.

`RequestLogger::set_api_key_type('guest')` (new type string, alongside
`'browser'`) so guest traffic is distinguishable in request logs. Rate
limiting needs nothing: `check_rate_limit()` is IP-keyed
(RequestLogger.php:73), which is the right key for principals with no user.

### 3 — Authorization: deny anonymous by default, everywhere

`ApiAuth::authorize()` (ApiAuth.php:249) is the single gate used by the CRUD
verbs, the logic endpoint, and the management router — so the default lands in
one place: **an anonymous principal (`api_user === null`) is denied with 401
unless the contract sets `'allow_guest' => true`.** Every existing contract
lacks the flag, so every existing endpoint rejects guests with no per-endpoint
audit. The management machine-key gate and the user-floor check reject
anonymous a second time regardless (defense in depth, not the mechanism).

Additionally, `api/apiv1.php` short-circuits anonymous principals for every
route family except action dispatch (CRUD verbs, forms, management, backups)
immediately after `ApiAuth::authenticate()` (:362) — those handlers read
`$api_user->get(...)` unconditionally and must never see a null user.

### 4 — Descriptor vocabulary

The `auth` block gains one flag:

```php
'auth' => [
    'allow_guest'              => true,  // anonymous browser sessions may invoke
    'requires_browser_session' => true,  // refuse API keys (existing flag)
],
```

- `allow_guest` implies nothing about keys; guest-reachable *session-state*
  actions (cart) must also declare `requires_browser_session` because a
  key-authenticated request is session-free and has no cart. The extraction
  spec already specifies exactly this pairing for `checkout_check_email`.
- `requires_session` stays boolean and stays `true` for guest actions — they
  are not sessionless (`dispatchActionPreAuth`, ApiLogicEndpoint.php:122,
  still returns for them; the credential is required, it just may be
  anonymous). `requires_session: false` remains the separate, pre-auth,
  no-credential category.
- In `dispatchActionAuthenticated()` (ApiLogicEndpoint.php:134): the
  user-permission read becomes null-safe
  (`$api_user ? $api_user->get('usr_permission') : 0`), and the acting user
  passed to `executeAction()` may be null for a guest — session simulation
  (`set_api_user`, :210) is skipped and the logic sees the natural anonymous
  session, which is precisely where the cart lives. A logged-in user calling
  a guest-reachable action gets normal session simulation; logic written
  against `SessionControl` works for both without branching.

### 5 — Session writes: the `session_write` flag

`authenticateBrowserSession()` deliberately releases the session lock
immediately after reading identity (`session_write_close()`, ApiAuth.php:188)
so long API calls don't block the user's other page loads. But cart actions
**mutate** `$_SESSION` (`ShoppingCart::persist()`, coupon keys —
ShoppingCart.php:69,170) and those writes would be silently discarded. This
would bite logged-in cart callers too — it is a prerequisite for migrating
checkout at all, not a guest-only concern.

The auth block gains `'session_write' => true`: in `executeAction()`, after
the descriptor is known and before the logic runs, a browser/guest-credential
request with this flag re-opens the session (`session_start()` — headers are
not yet sent; the id cookie already exists) and lets it close at shutdown, so
logic-layer `$_SESSION` writes persist. Key-authenticated requests ignore the
flag (no session). Actions without the flag keep today's early-release
behavior — the flag is per-action opt-in precisely so one slow cart action
doesn't reintroduce lock contention platform-wide.

### 6 — Idempotency: explicitly unsupported for guests

`ApiIdempotencyKey::credential_scope()` returns null with neither key nor
user, and `idempotencyResolve()` already treats that as "no idempotency in
play" (ApiLogicEndpoint.php:319-322). Guests sending `Idempotency-Key` get the
header silently ignored — same as sessionless actions today. Session-id-scoped
idempotency is deliberately out of scope; nothing in the v1 consumer set needs
it (coupon apply/remove are naturally idempotent; consent recording is
last-write-wins).

## Consumers migrated by this spec

1. **The four checkout actions** — exactly as tabled in the extraction spec
   (§ "API actions", including auth posture, inputs, and return shapes):
   `store/checkout_apply_coupon`, `store/checkout_remove_coupon`,
   `store/checkout_check_email`, `event_manager/checkout_submit_survey`.
   The three cart/coupon actions add `allow_guest` + `requires_browser_session`
   + `session_write`; `checkout_check_email` adds `allow_guest` +
   `requires_browser_session` (read-only, no session writes);
   `checkout_submit_survey` stays logged-in-only (registrations require an
   account) and gains nothing. JS callers
   (plugins/store/views/checkout.php:558,578,597;
   plugins/event_manager — cart_confirm.php:184) switch to JSON POST with the
   meta-or-cookie CSRF pattern. Then delete `ajax/checkout_ajax.php`, its
   `validate_section` branch's server side (`validate_checkout_section()` in
   the theme checkout_logic), per the extraction spec's deletion list.
2. **Cookie consent** — new core action `consent_record`
   (`logic/consent_record_logic.php`, descriptor: `allow_guest`,
   `requires_browser_session`, mutates, input `analytics` bool + `marketing`
   bool). Body wraps `ConsentHelper::recordConsent()` with the existing
   visitor-id cookie handling. The banner JS emitted by
   `includes/ConsentHelper.php` switches to the API call. Delete
   `ajax/cookie_consent.php`. (Its hand-rolled Origin/Referer check is
   subsumed by the CSRF credential — strictly stronger.)

`ajax/vs.php` is **not** migrated here: it has no live emitter anywhere in the
codebase and is handled as a delete-after-verification, separate from this
spec.

## Tests

One consolidated suite, house style (`tests/lib/harness.php`, `@joinery-test`
header): **`tests/functional/api/guest_credential_test.php`** (tier `db`,
dev-only, live-site HTTP like `browser_session_test.php`). Covers: mirror
cookie distributed to an anonymous visitor with no meta tag in the HTML;
anonymous + valid CSRF invokes `allow_guest` actions (consent recorded in the
DB); `session_write` persistence across guest requests (coupon applied in
request 1 visible in request 2); `checkout_check_email` guest true/false;
fail-closed negatives (non-guest action 401, CRUD 401, form 401, auth 401,
wrong token 403, headerless 400 unchanged, API key on a
`requires_browser_session` action 403); mirror cookie on X-Cache HIT
responses.

Also verified in a real browser as a guest on dev: coupon apply (invalid →
inline error; valid → applied and surviving reload), coupon remove, the
email-exists prompt, and the consent banner POST.

**Acceptance still open:** one live guest checkout with a real test-mode
payment on `dev.getjoinery.com` — the condition the original parked decision
named. Everything up to payment is exercised; the payment leg is not.

## Docs

On ship (current-state voice): docs/api.md § Authentication — the anonymous
browser credential, the `allow_guest` / `session_write` descriptor flags, the
meta-or-cookie JS pattern, the guest idempotency limitation. docs/routing.md
untouched. plugins/store/docs/overview.md checkout section: callers are
`/api/v1` actions.

## Phases

1. **Platform credential** — cookie distribution + StaticPageCache exemption,
   anonymous principal in ApiAuth, authorize() default-deny + apiv1 route
   guard, descriptor flags, `session_write` reopen, `guest` log type, the
   credential/cache/session-write tests.
2. **Checkout** — the four actions + JS callers, guest checkout suite, live
   payment acceptance run, delete `ajax/checkout_ajax.php`.
3. **Consent** — `consent_record`, banner JS, delete
   `ajax/cookie_consent.php`.

## Decisions resolved at implementation

- The `joinery_api_csrf` cookie is minted for everyone, logged-in included —
  `SessionControl::sync_api_csrf_cookie()` runs at session construction (one
  code path, covers cache HITs); the meta tag remains and wins in the JS
  pattern.
- Anonymous denial is 401 `AuthenticationError` ("requires authentication") —
  indistinguishable from missing-credential, no surface probing.
- `session_write` reopens via `SessionControl::reopen()` —
  `session_start(['use_cookies' => 0])`, reusing the request's session id
  without re-emitting the id cookie.
- `consent_record`'s visitor id follows the platform convention: the
  `visitor_id` cookie (truncated to the column's 20 chars) or the session
  uniqid — the same identity `SessionControl::save_visitor_event()` records
  page views under. (The legacy endpoint's 32-hex fallback exceeded
  `vse_visitor_id varchar(20)` and failed for cookie-less visitors.)

## Found during implementation

`StaticPageCache`'s excluded-URL patterns listed `/checkout/` and `/cart/`
with trailing slashes but matched with a bare `strpos(..., $pattern) === 0`,
so the real `/checkout` and `/cart` URLs were **cacheable**: the first guest
to render an empty-cart checkout page poisoned it for every later guest.
Pre-existing, but armed by guest checkout. Fixed in the same change: patterns
are bare paths matched as exact-or-prefix in one shared
`StaticPageCache::isExcludedPath()`, `/cart_confirm` + `/cart_charge` joined
the list, and the exclusion is enforced on the **serve** side too
(`checkCache()` refuses and drops any indexed entry for an excluded path), so
entries poisoned before the fix self-heal on every deployment without a
manual purge.

Review-pass fixes: session-simulation cleanup moved to a `finally` (an
uncaught PHP Error inside a `session_write` action could otherwise persist
simulated values into the real web session); checkbox-list survey answers
(`name="field[]"`) accumulate into JSON arrays in the cart-confirm JS (the
literal `field[]` key never matched the logic's lookup); every CSRF-token
read is cookie-first (the cookie tracks the current session; the render-time
meta tag is the stale one after a logout in another tab); the anonymous
principal's `auth_data` carries `current_user_permission => null` so the
one anonymity signal is consistent everywhere.

## Accepted limitations (deliberate)

- **Cookie-less visitors' consent choices are not recorded.** The legacy
  endpoint wrote an audit row (under a freshly generated random visitor id)
  even when the browser sent no cookies; the API credential requires the
  session + CSRF cookies, so those POSTs now fail 400. Accepted: a browser
  that blocks cookies also cannot hold the consent cookie itself (the banner
  re-prompts every page and no tracking cookies get set), and the alternative
  — a sessionless consent action — would reopen unauthenticated row-spam.
- **The excluded-path list stays hardcoded in `StaticPageCache`.** A
  page-level "never cache me" declaration (view- or plugin-owned) is the
  right long-term shape; not built here. Follow-up candidate if a plugin
  outside store/core ever ships a session-personalized public page.
