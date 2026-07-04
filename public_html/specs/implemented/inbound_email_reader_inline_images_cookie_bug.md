# Bug: Inline email images still broken — gated `File` URLs don't load inside the sandboxed reader iframe

**Status:** Implemented. The cookie analysis below was confirmed but is only half
the root cause — see "Corrected root-cause analysis" at the end for the
authorization-model half this document originally missed, and for the
as-implemented fix (one shared resolver, not two per-site patches).
**Reported:** User sent a real "image test" email (inline JPEG) to `test@dev.getjoinery.com`
and the image did not render in the mailbox reader.
**Related:** [[inbound_email_reader_inline_images]] (the spec this bug lives in — its
implementation is the thing that's broken), `docs/file_signed_urls.md` (the mechanism
this bug's fix reuses).

## Plain-language summary

An inline image in a received email still doesn't show up — not because the `cid:`
rewrite is missing (it runs correctly and produces the right-looking URL), but because
the browser silently refuses to send its login cookie when it fetches that URL from
inside the locked-down (`sandbox=""`) frame the email body renders in. Without the
cookie, the server can't tell who's asking, so it responds exactly as it would to a
stranger: `404 Not Found`. The image request happens, gets rejected, and the browser
shows a broken-image icon — or, for a tiny 1×1 test image, nothing visible at all,
which is why this passed my own testing.

This affects **both** places inline images were wired up:

1. The admin single-message detail page (`admin_inbound_email_message.php`).
2. The two-pane Mailbox Reader (`ajax/mailbox_thread.php` /
   `MailboxService::withInlineImagesResolved()`) — the page both admin and member users
   actually read mail in day to day, and the one the reporting user was using.

## How to reproduce

1. Send any email with an inline image (e.g. paste an image into a Gmail compose, which
   Gmail sends as a `cid:` inline part) to any local alias, e.g. `test@dev.getjoinery.com`.
2. Open the message in either reader (Mailboxes tab, or the single-message detail page)
   while logged in as an admin who can see it.
3. The image renders as a broken-image icon; the underlying request 404s.

Confirmed via the access log for the exact repro message (id 488, `cid:ii_mr6mrl4e0`
→ `File` 649):

```
172.70.230.246 ... "GET /uploads/thursday_zouk_basic_tffq8lc0.jpeg HTTP/1.1" 404 ...  ← from inside the sandboxed iframe
172.70.230.246 ... "GET /uploads/thursday_zouk_basic_tffq8lc0.jpeg HTTP/1.1" 200 ...  ← same URL, direct top-level navigation
```

Same pattern reproduced against the original inline-image test fixture (message 468,
`dot_z6kkh7dm.png`) — the 1×1 test image also 404'd from inside the iframe; its earlier
"pass" was an artifact of the image being too small to visibly show a broken-icon glyph,
not evidence it loaded.

`curl` against the image URL with no cookies at all reproduces the identical 404, and
`cf-cache-status: BYPASS` on that response rules out Cloudflare edge-caching a stale
404 — the origin is genuinely, deterministically rejecting the request as unauthenticated
every time it arrives without the session cookie.

## Cause analysis

### The rewrite itself is correct

Both cid-rewrite implementations do exactly what they're supposed to: they find the
manifest row whose `ima_content_id` matches the `cid:` token **for that message**, load
its `File`, and splice `File::get_url()` into the body in place of `cid:<id>`. Confirmed
directly from the `ajax/mailbox_thread` response body — `body_html` contains
`src="/uploads/thursday_zouk_basic_tffq8lc0.jpeg"`, not the raw `cid:` token. The bug is
entirely in what happens when the browser tries to load that URL.

### `File::get_url()` returns a **session-gated** URL — and the iframe can't present a session

`File::get_url()` for a private local file returns the plain `/uploads/<name>` path.
`serve.php`'s `/uploads/*` route authorizes that path with `File::is_viewable($session)`
— it needs the request to carry the admin's session cookie.

The reader deliberately renders the (fully attacker-controlled) email body inside
`<iframe sandbox="" srcdoc="...">` — sandboxed, and critically **without
`allow-same-origin`**, so that the frame can never execute script or read/write the
parent's cookies. That's the correct security posture for hostile HTML. But it has a
side effect the original spec didn't anticipate: a document with no `allow-same-origin`
is placed in a **unique, opaque origin** by the browser. A subresource request (like the
rewritten `<img src>`) issued from a document with an opaque origin has no comparable
"site" against the top-level page, so Chromium does not attach the site's
`SameSite=Lax` session cookie to it. The request reaches `serve.php` cookie-less,
`is_viewable()` sees no session, and — by design, to avoid confirming a private file's
existence to an unauthenticated caller — the route answers `404` rather than `403`.

This is a structural property of the chosen sandboxing, not a fluke: **every**
session-gated URL embedded in this iframe will 404, regardless of which reader renders
it or who's logged in.

### The codebase had already solved this exact problem — for a different client

`docs/file_signed_urls.md` opens with: *"Short-lived links to private files that work
without a session — for native app clients **and embedded HTML (inline email
images)** that cannot send cookies or custom headers."* `File::mintSignedUrl()` already
exists and is already used for exactly this — `MailboxService::withSignedTransport()`
mints signed inline-image URLs for the sessionless native-mobile mail API
(`thread_logic.php`).

The original inline-images spec reasoned explicitly about this risk and dismissed it:

> the GET is same-site, so the cookie is sent. (If testing ever shows cookies somehow
> not flowing, revisit with a session-independent serve path; not expected, not
> designed up front.)

That assumption is what's wrong. It reasoned about `SameSite=Lax`'s registrable-domain
comparison, which is correct for an ordinary cross-origin iframe — but didn't account
for the sandboxed iframe's document itself having no origin to compare in the first
place. The browser's session-cookie posture, not the email's registrable domain, is
what decides whether the cookie goes out.

### Why my own verification missed it

I verified the rewritten URL by opening it directly (a normal, top-level, cookied
navigation) instead of confirming the request as it actually happens — as an `<img>`
subresource load issued from inside the sandboxed `srcdoc` iframe. The former always
succeeds (it's just an authenticated page view); only the latter exercises the real
bug. The access log is the only place the distinction shows up, and I didn't check it
at the time.

## Blast radius

Everything that embeds a session-gated `File::get_url()` inside this `sandbox=""`
iframe is affected — currently the two inline-image call sites listed above. It does
**not** affect:

- The attachment **download** links/chips (separate `<a href>` navigations the user
  clicks — a normal top-level, cookied request, not a sandboxed-iframe subresource).
- The native mobile mail API (`thread_logic.php`) — already uses signed URLs via
  `withSignedTransport()`, unaffected by any of this.

## Fix

Replace `File::get_url()` with `File::mintSignedUrl()` at both cid-rewrite sites, so
the embedded `<img>` URL authorizes itself via its signed token instead of a cookie —
working identically whether or not the requesting context can present a session:

1. `plugins/inbound_email/logic/admin_inbound_email_message_logic.php` — the single
   inline-image cid-map loop.
2. `plugins/inbound_email/includes/MailboxService.php::withInlineImagesResolved()` —
   same change; note `withSignedTransport()` right above it in the same file is the
   working reference implementation for the identical problem.

### TTL

`docs/file_signed_urls.md`'s stated default (300s) assumes a consumer that re-fetches
its parent resource for fresh links — true for the native app (polls/reopens threads),
not true for either of these readers, which mint once per page/thread load and don't
auto-refresh while the page sits open. Recommend a longer TTL for these two call sites
— **3600s (1 hour)** — long enough to outlast a normal read, short enough to stay
"short-lived" in spirit. A link that does expire mid-view simply goes broken until the
message/thread is reopened (which mints a fresh one); there's no session fallback to
degrade to inside the sandboxed iframe, so this dead-link window is a real, accepted
trade-off, not a bug to chase further.

## Test plan

1. Repro message 488 (`cid:ii_mr6mrl4e0` → `File` 649): open in both readers, confirm
   the access log shows the resulting `/uploads/...?expires=...&sig=...` request
   returning `200`, and the image visibly renders (not a broken-icon glyph).
2. Confirm the same for the original test fixture (message 468, `dot_z6kkh7dm.png`) —
   small enough that a visual check alone isn't proof; check the access log status code.
3. Let a signed link expire (mint with a 1-second TTL in a throwaway test) and confirm
   the request now 404s from the iframe context (expected, documented trade-off) rather
   than silently serving stale bytes.
4. Re-run the two existing test surfaces this bug's original spec touched
   (`plugins/inbound_email/tests/mailbox_reader_test.php`,
   `plugins/inbound_email/tests/profile_mailbox_test.php`) plus a fresh check of the
   admin single-message page, to confirm nothing else regressed.

## Documentation

On implementation, correct `plugins/inbound_email/docs/overview.md`'s inline-images
section (added alongside the original spec) to describe signed, not gated, URLs — and
correct the `withInlineImagesResolved()` docblock I added, which currently claims gated
URLs are the right call ("the browser's session cookie already authorizes the
follow-on GET") — that claim is exactly what this bug disproves.

## Corrected root-cause analysis (added at implementation)

The cookie diagnosis above is real and fully explains the reported repro (an
admin viewing a file he happens to own). But it is only the *transport* half of
the bug. There is a second, independent root cause the analysis above missed:

**Gated `/uploads` URLs authorize with the wrong permission model for mail.**
`File::is_viewable()` on a private file is strictly owner-or-admin, while
mailbox visibility is a *grant* decision (`MailboxViewer`). Inline-image Files
are created owned by the alias's sole grantee — or by `User::USER_SYSTEM`
whenever the alias has multiple grantees (`InboundEmailRouter::attachmentOwnerId()`).
So a member grantee of a shared mailbox passes the reader's scope check but
fails the file gate: the gated URL 404s for them **even with the session cookie
flowing perfectly**. Any cookie-side fix (relaxing the sandbox, `SameSite=None`)
would have "fixed" admins while leaving members silently broken. The original
spec's claim that inline images authorize "identical to attachment download"
was wrong when written — the member attachment endpoint
(`profile_attachment_logic.php`) deliberately gates on the mailbox grant, not
`is_viewable()` ("the grant is the rule").

Signed URLs are therefore not a workaround for the sandbox: they are the only
transport that carries the grant decision (minting after the scope check IS the
authorization statement), which is why the native path already used them.

### As implemented

Not the two per-site `get_url()` → `mintSignedUrl()` swaps proposed above.
There were three near-duplicate cid-rewrite implementations
(`withSignedTransport()`, `withInlineImagesResolved()`, and a hand-rolled loop
in `admin_inbound_email_message_logic.php`) with quietly divergent behavior
(URL-decoding, `fil_delete_time` checks, regex vs `str_ireplace`). They were
consolidated into one shared static resolver,
`MailboxService::resolveInlineImages()` (signed URLs, 3600s default TTL for the
web readers via `MailboxService::INLINE_IMAGE_TTL`):

- `ajax/mailbox_thread.php` calls it directly (`MailboxViewer` scope is the
  authorization statement).
- `admin_inbound_email_message_logic.php` calls it with a one-row payload (the
  permission-5 gate is the authorization statement).
- `withSignedTransport()` delegates cid rewriting to it and keeps only the
  per-attachment `url` enrichment; **`withInlineImagesResolved()` was deleted.**

Verified live on both repro messages: message 488 and the 1×1 fixture
(message 468) both load from inside the `sandbox=""` iframe in both readers —
access log shows the signed `/uploads/...?expires=...&sig=...` GETs returning
200, and in-frame inspection shows the images decoded at natural size. Expiry
behavior is covered by `tests/functional/files/signed_urls_test.php`; the
mailbox suites (`mailbox_reader_test.php` 37 pass, `profile_mailbox_test.php`
28 pass) confirm no regression.
