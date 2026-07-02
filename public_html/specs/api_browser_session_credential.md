# Browser-Session Credential for `/api/v1`

Let page JavaScript call the same `/api/v1` actions the mobile apps use,
authenticated by the web session it already has. One door for web JS and
apps means new features are built once — as API actions — and are
mobile-ready by construction.

**Problem.** Features grow a cookie-authenticated `/ajax/` endpoint first
and an API action later, if ever — the mailbox reader is Exhibit A (five
AJAX files, zero API coverage). Every such feature is web-only until
someone writes wrappers. Shipped phone apps can't wait for wrappers.

## The change

`ApiAuth` accepts a second credential: a logged-in web session cookie
**plus** a CSRF header.

- **Credential:** requests with API key headers authenticate exactly as
  today (keys take precedence). Otherwise, if the request carries a valid
  web session cookie **and** an `X-Joinery-Csrf` header matching the
  session's API CSRF token, it authenticates as that user with the same
  standing as a session-type key.
- **CSRF token:** minted once per web session by `SessionControl`, exposed
  to page JS by `PublicPageBase` (a `<meta name="joinery-api-csrf">` tag).
  This is a session-wide token for API calls — separate from FormWriter's
  per-form tokens, which are unchanged.
- **Boundaries preserved:** the management API remains machine-key-only
  (the existing `requires_machine_key` gate rejects browser sessions the
  same way it rejects session keys — the load-bearing test in
  `tests/functional/api/session_keys_test.php` gains a browser-session
  case). HTTPS enforcement, rate limits, and the 426 handshake apply
  unchanged; browser-session requests carry no `client-app` headers and are
  unaffected by version minimums, like other headerless clients.
- **Forward rule (documented, not retrofitted):** new features expose logic
  via `_api()` actions and web JS calls `/api/v1`. `/ajax/` is legacy — no
  new endpoints. Existing AJAX endpoints keep working; they migrate
  opportunistically when touched.

## Sequencing

Lands before `specs/ios_app_platform.md` Phase 1. Makes the mailbox API in
`specs/mobile_native_email.md` the last big "wrap existing AJAX" job of its
kind.

## Tests

- Browser session + valid token runs a sessioned action as the user.
- Missing/wrong token → 403 even with a valid cookie.
- Key headers still win when both are present.
- Management API rejects browser sessions.
- Anonymous cookie-less requests unchanged.

## Acceptance checklist

1. A page's JavaScript calls a sessioned `/api/v1` action with the CSRF
   meta token and no API key; the same call without the token is refused.
2. The management API refuses browser sessions.
3. Existing `/ajax/` endpoints and FormWriter per-form CSRF behave
   unchanged.

## Out of scope

- Migrating existing `/ajax/` endpoints wholesale (opportunistic only;
  the forward rule stops new growth).

## Versioning

- Bump `@version` on each modified core file (`ApiAuth`, `SessionControl`,
  `PublicPageBase`).

## Documentation deliverables (on implementation)

- `docs/api.md` — the browser-session credential (auth story gains a third
  column).
- The internal CLAUDE.md record (via `/admin/admin_agent_files`) — the
  forward rule: new features expose API actions; web JS calls `/api/v1`;
  no new `/ajax/` endpoints.
