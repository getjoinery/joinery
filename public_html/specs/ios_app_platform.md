# Joinery iOS App Platform — Spec

A reusable foundation for shipping branded iPhone apps on any Joinery
deployment. The core experience is fully native — login, password reset,
account forms, navigation, settings — and every `/profile` page (calendar,
mailbox, orders, conversations, plugin pages) renders inside the app through
an authenticated, chrome-less webview. Any content surface can later be
promoted from webview to native one route at a time, with no auth or
navigation rework.

The first app built on this platform is the **Joinery member app** (this
spec's reference app): sign in and use the full `/profile` surface, including
calendar and email. `specs/scrolldaddy_ios_app.md` builds its DNS-filtering
app on the same foundation.

## Design principles

1. **One auth root.** The app logs in once over the API and holds a session
   key (Keychain). The webview's web session is *derived* from that key via a
   bridge endpoint — never a second login. Native screens (API calls) and
   webviews (bridged session) coexist in one app, which is what makes
   per-surface native migration free of rework.
2. **Navigation is a routing table, not a frame.** The tab bar and menu are
   native; each entry just says what to show — a `/profile` URL today, a
   native screen name once one exists. Flipping a surface to native changes
   one entry.
3. **Server-driven where change is frequent.** Account forms and the
   navigation menu come from the server, so form changes and new profile
   pages appear in shipped apps without a release. App releases are for new
   *native capability* only.
4. **The web surface is the permanent fallback.** There will always be
   long-tail pages not worth building natively; the bridge and app mode stay
   load-bearing forever.

## Architecture

| Layer | Package | Reusable for | Contents |
|---|---|---|---|
| Core | **JoineryKit** (Swift package) | any app on any Joinery deployment | API client (base URL + branding injected), session-key auth + Keychain storage, native login screen, generic server-driven form renderer, navigation shell (tab bar + "More" list fed by the navigation endpoint), authenticated webview component (bridge, chrome-less rendering, link policy), settings screen, 426 upgrade gate |
| Brand | **App target** (one per app) | — | Bundle ID, deployment base URL, `client_app` id, colors/logo, feature toggles (e.g. registration), App Store assets |

Development happens on the Mac mini per
`specs/mac_mini_ios_development_access.md`. JoineryKit lives in its own repo
(`~/dev/joinery-kit`); each app is its own repo (`~/dev/<app-name>`)
consuming JoineryKit as a local Swift package dependency. Reference app repo:
`~/dev/joinery-member-ios`.

## Native core (client work, endpoints already exist)

### Login & sessions

`POST /api/v1/auth/login` with email/password and a `device_label` (e.g.
"Jeremy's iPhone") mints a session key pair; the secret goes to the Keychain
and every request uses the standard key headers (`docs/api.md`).
`GET /api/v1/auth/session` supplies the who-am-I + tier feature flags that
drive the settings screen. `POST /api/v1/auth/logout` revokes the key on
sign-out. A password change revokes all of the user's session keys (the
lost-phone path), and users can revoke individual devices from the App
Sessions view at `/profile/security`.

### Server-driven forms — one renderer, every account form

`GET /api/v1/form/{action}` returns the form's JSON definition
(`FormWriterV2JSON`, `docs/formwriter.md` § JSON Output Mode);
`POST /api/v1/action/{action}` submits it; field-level errors map back onto
the native controls. One generic SwiftUI renderer covers, with no per-form
screens:

- `password_reset_1` / `password_reset_2` (fully native forgot-password, the
  reset code round-trips via the form's query-context — `docs/api.md`)
- `password_edit`, `account_edit`, `contact_preferences`, `address_edit`,
  `phone_numbers_edit`
- `register` — **per-app toggle, off by default.** Enabling in-app
  registration triggers Apple's in-app account-deletion requirement, so an
  app turns it on only in a release that also ships deletion (see the
  consuming app's billing/account phasing, e.g. `specs/mobile_app_billing.md`).

The renderer implements the JSON mode's field types and `visibility_rules`.
A definition containing a field type the shipped renderer doesn't know falls
back to opening that one form in the webview — old app versions survive new
server-side field types.

### Settings screen

Subscription/tier status (from `auth/session`), the native account forms
above, a link to the App Sessions page (webview), sign out.

### Upgrade gate

Every API request sends the `client-app` / `client-version` headers (hyphen
form — underscore header names are dropped by proxy_fcgi stacks; see the
client headers section of `docs/api.md`). Any 426 `UpgradeRequired` response
— including at login — renders as a blocking upgrade screen with an App
Store deep link. Minimum versions are set per app in the
`api_min_client_versions` setting.

## Server-side work (the three new pieces)

Everything else the app consumes already exists (session-key auth, actions,
form JSON). These are the platform additions, and all three are
client-agnostic — a future Android app reuses them unchanged.

### 1. Navigation endpoint

`GET /api/v1/app/navigation` — session-key authenticated.

- **Source:** the seeded profile menu (core entries from `admin_menus.json`
  `profileMenu`, plugin entries from each plugin's `plugin.json`
  `profileMenu`). Adding a plugin profile page with a menu entry makes it
  appear in every shipped app with no release.
- **Filtering:** entry `permission` vs the user's, `visibility` (app users
  are always signed in, so `out`-only entries are excluded), and
  `settingActivate`. Entries that are native shell concerns (sign in/out,
  forgot password) are excluded — the shell owns those.
- **Entry shape:** `slug`, `title`, `icon`, `order`, and a `destination`:
  `{type: "web", url}` or `{type: "native", screen, fallback_url}`. The
  version-skew rule: a client renders the native screen when it recognizes
  `screen`, otherwise loads `fallback_url`. Flipping a surface to native
  never breaks shipped versions.
- **Tabs vs More:** the response marks which entries are tab-bar pinned,
  from an `app_navigation` setting keyed by `client_app` (ordered tab slugs
  per app; everything else lands in the More list). Factory default in
  `settings.json`.

Note: some themes hardcode their web profile-menu rendering; the seeded menu
store is canonical for apps. Reconciling theme rendering is not this spec.

### 2. Web-session bridge

`POST /api/v1/auth/web_session` — session-key authenticated. Body: a
same-origin relative target path. Returns a single-use bridge URL with a
short-TTL token (~60s).

- The webview loads the bridge URL; the server validates the token (unused,
  unexpired), starts a normal web session for the key's user, marks the
  session **app-context** (recording the originating `apk_api_keys` id and
  `client_app`), and 302s to the target path.
- **Lifetime coupling:** a bridged web session is valid only while its
  originating API key is. Revoking the key — app logout, the App Sessions
  page, or a password change — invalidates the bridged web session at its
  next check. "Revoke that phone" is one gesture and kills both layers.
- **App behavior:** bridge on first webview use; the webview cookie store
  persists the web session across launches; on detecting a logged-out
  response the shell silently re-bridges and retries once before surfacing
  anything. The user never sees a web login page in normal use.
- **Security:** tokens are single-use, short-lived, HTTPS-only, never
  logged; absolute or off-site targets are rejected.

### 3. App display mode (chrome-less pages)

App-context web sessions render pages without the site header, navigation,
and footer — page content and the `jy-ui` design system only, with a
`jy-app-mode` body class as a CSS hook. Implemented once in the shared page
chrome (`includes/PublicPageBase.php` header/footer chain) so every theme
inherits it with no per-theme work. The native shell supplies titles and
back navigation.

## Webview contract (JoineryKit component)

- **Scope:** same-origin only. Off-site links (and site pages outside the
  member surface) open in Safari; member-surface navigation stays
  in-webview.
- The native shell owns the title bar and back gesture; webview history maps
  onto them.
- File uploads (photo pickers) and downloads (share sheet hand-off) work.
- Pull-to-refresh; standard loading, error, and offline-retry states.
- Logged-out detection → silent re-bridge (see above).

## Security notes

- Secrets live only in the Keychain — never UserDefaults or plists.
- The webview cookie store holds only the bridged session; sign-out clears
  it along with revoking the API key.
- Apps only ever hold session-type keys; the machine-key-only management API
  boundary (`docs/api.md`) is untouched.

## Delivery phases & test gates

Strictly sequential; each later gate re-runs earlier suites as regression.
Phases 1 is pure server work; Phases 2–3 run in the iOS Simulator against
`dev.getjoinery.com`; Phase 4 needs a physical iPhone only for final
App Store validation (no Network Extension entitlements in this spec).

### Phase 1 — Server platform

The navigation endpoint, the web-session bridge, and app display mode.
Testable with curl and a browser — no Xcode dependency.

**Action payload promotion.** For every action the app calls natively
(beyond auth and forms, which are already contract), design and document
its `data` payload in `docs/api.md`, promoting it into the § Contract
surface. Most core actions currently return page variables whose framework
objects serialize as `{"key": N}` husks (see
`specs/api_contract_and_idempotency.md` § Change 1 audit output) — each
such action gets a real client-facing payload here, the way the
dns_filtering actions did for ScrollDaddy. Where the web page wants the
same data, the payload is designed once and the page consumes it too.

**Gate:** functional tests in `/tests/functional/api/` — navigation entries
filtered by permission/visibility/settingActivate and per-app tab pinning;
bridge tokens single-use and expiring; key revocation and password change
each kill the bridged web session; app-mode sessions render without site
chrome while normal sessions are unaffected; off-site bridge targets
rejected.

### Phase 2 — JoineryKit native core

Login screen, Keychain storage, the generic form renderer, settings screen,
426 gate.

**Gate:** XCUITest in the Simulator — log in/out; complete both
password-reset steps natively (on dev, use a `*@inbox.dev.getjoinery.com`
account so the reset email is readable from `iem_inbound_email_messages`);
a server-side form-definition change appears in the app without a rebuild;
invalid-credential, rate-limit, and 426 paths render correctly.

### Phase 3 — Navigation + webviews

Tab bar and More list from the navigation endpoint; the webview component
with bridging, chrome-less rendering, link policy, and silent re-bridge.

**Gate:** XCUITest in the Simulator — the calendar is usable in-app; orders
and conversations load; a mailbox-granted user reads and replies to mail
(depends on `specs/inbound_email_profile_mailbox.md`); adding a plugin
`profileMenu` entry appears in the app without a release; revoking the app's
session from the web signs out both the native and webview layers; external
links open Safari.

### Phase 4 — Reference app ships

Joinery member app branding, App Store assets, TestFlight, review.
Login-only (registration toggle off), so Apple's account-deletion
requirement is not triggered.

**Gate:** on a physical iPhone — install → sign in → every navigation entry
reachable and chrome-less; App Store review passes.

## Dependencies

- Core pre-work specs: `specs/api_browser_session_credential.md`
  (implemented) and
  `specs/profile_menu_single_source.md` land before Phase 1 here (the
  navigation endpoint reads the same menu accessor);
  `specs/api_contract_and_idempotency.md`'s contract audit completes
  before Phase 4's store submission, and app clients send
  `Idempotency-Key` on mutating calls.
- `specs/inbound_email_profile_mailbox.md` — the user-facing mailbox at
  `/profile` that puts email in the app for granted users.
- `specs/mac_mini_ios_development_access.md` — the development environment.

## Consumers

- **Joinery member app** — the reference app, delivered by this spec.
- **ScrollDaddy iOS** (`specs/scrolldaddy_ios_app.md`) — adds the DNS
  filtering and Network Extension layers on top of this platform.
- **Android platform** (`specs/android_app_platform.md`) — the server pieces
  here (navigation, bridge, app mode) are client-agnostic; the Android
  platform consumes them with zero new server work.

## Acceptance checklist

1. Login, logout, and both password-reset steps are fully native — the web
   login page never appears in the app.
2. Account edit and contact preferences render natively from server JSON; a
   field added server-side shows up in the shipped app with no release.
3. The tab bar and More menu come from the navigation endpoint; a new plugin
   `profileMenu` entry appears with no release; permission and setting
   filtering hold.
4. The calendar and (for a granted user) the mailbox are fully usable inside
   the app with no site header/footer.
5. Webview authentication is invisible: first bridge and every re-bridge are
   silent.
6. Revoking the app's session key — App Sessions page or password change —
   invalidates both the app's API access and its webview session.
7. A 426 response, including at login, renders the blocking upgrade screen.
8. JoineryKit builds as a standalone package with no brand imports, and a
   second app target consumes it unchanged.
9. With registration toggled off there is no signup path anywhere in-app.

## Out of scope (future specs)

- In-app billing (`specs/mobile_app_billing.md`).
- Push notifications.
- Native screens for content surfaces (calendar, email, conversations…) —
  each is a small per-surface spec: add API endpoints + a screen, flip one
  navigation route.
- Android (the server-side work here is what makes it cheap later).
- Offline caching / local data stores.
- Data-conditional menu visibility (e.g. hiding Email when the user holds no
  mailbox grant) — pages' empty states cover v1.

## Documentation deliverables (on implementation)

- New `docs/mobile_apps.md` — the platform guide: JoineryKit repo location
  and configuration surface, the navigation endpoint, the web-session
  bridge, app display mode, and how to stand up a new branded app.
- `docs/api.md` — `auth/web_session` and `app/navigation` sections.
