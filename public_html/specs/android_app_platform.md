# Joinery Android App Platform — Spec

The Android counterpart to `specs/ios_app_platform.md`: a reusable foundation
for shipping branded Android apps on any Joinery deployment. The core
experience is fully native — login, password reset, account forms,
navigation, settings — and every `/profile` page (calendar, mailbox, orders,
conversations, plugin pages) renders inside the app through an authenticated,
chrome-less webview. Any content surface can later be promoted from webview
to native one route at a time, with no auth or navigation rework.

The reference app is the **Joinery member app for Android**: sign in and use
the full `/profile` surface, including calendar and email.
`specs/scrolldaddy_android_app.md` builds its DNS-filtering app on this
foundation.

## Server-side work

**None.** The three server pieces the platform needs — the navigation
endpoint (`GET /api/v1/app/navigation`), the web-session bridge
(`POST /api/v1/auth/web_session`), and app display mode — are specified and
delivered by `specs/ios_app_platform.md`, and are client-agnostic by design.
This spec is pure client work against them, plus the endpoints that already
exist (session-key auth, actions, form JSON — `docs/api.md`).

## Design principles

Identical to the iOS platform, restated as the contract this client honors:

1. **One auth root.** The app logs in once over the API and holds a session
   key; the webview's web session is derived from that key via the bridge —
   never a second login. Native screens and webviews coexist.
2. **Navigation is a routing table.** Each server-supplied entry names a
   `/profile` URL or a native screen; the client renders the native screen
   when it recognizes the name, else loads the fallback URL.
3. **Server-driven where change is frequent.** Forms and menus come from the
   server; app releases are for new native capability only.
4. **The web surface is the permanent fallback.**

**Language decision (carried over, decided once):** native Kotlin + Compose,
no Kotlin Multiplatform sharing with the iOS packages. The reusable boundary
between the platforms is the server API, not shared client code — KMP would
couple the two codebases for little gain at this app size.

## Architecture

| Layer | Module | Reusable for | Contents |
|---|---|---|---|
| Core | **`joinery-android`** (Kotlin library) | any app on any Joinery deployment | API client (base URL + branding injected), session-key auth with Keystore-backed `EncryptedSharedPreferences`, native Compose login screen, generic server-driven form renderer, navigation shell (bottom bar + "More" list fed by the navigation endpoint), authenticated webview component (bridge, chrome-less rendering, link policy), settings screen, 426 upgrade gate |
| Brand | **App module** (one per app) | — | Application ID, deployment base URL, `client_app` id, theme/logo, feature toggles (e.g. registration), Play Store assets |

Development happens on the Mac mini (Android Studio CLI tools + Gradle +
emulator run fine on Apple Silicon; the SSH workflow from
`specs/mac_mini_ios_development_access.md` carries over — `./gradlew build`,
`adb`, headless emulator, screenshots via `adb exec-out screencap`).
`joinery-android` lives in its own repo (`~/dev/joinery-android`); each app
is its own repo consuming it as a Gradle dependency. Reference app repo:
`~/dev/joinery-member-android`.

## Native core (client work, endpoints already exist)

### Login & sessions

`POST /api/v1/auth/login` with email/password and a `device_label` mints a
session key pair; the secret goes to Keystore-backed
`EncryptedSharedPreferences` and every request uses the standard key headers
(`docs/api.md`). `GET /api/v1/auth/session` drives the settings screen;
`POST /api/v1/auth/logout` revokes on sign-out. A password change revokes
all session keys; users revoke individual devices from the App Sessions view
at `/profile/security`.

### Server-driven forms — one renderer, every account form

`GET /api/v1/form/{action}` returns the `FormWriterV2JSON` definition
(`docs/formwriter.md` § JSON Output Mode); `POST /api/v1/action/{action}`
submits; field errors map back onto the Compose controls. One generic
renderer covers `password_reset_1` / `password_reset_2` (fully native
forgot-password), `password_edit`, `account_edit`, `contact_preferences`,
`address_edit`, `phone_numbers_edit`, and `register` — **per-app toggle, off
by default**: enabling in-app registration triggers Google Play's
account-deletion-in-app policy, so an app turns it on only in a release that
also ships deletion. A definition containing an unknown field type falls
back to opening that one form in the webview, so old app versions survive
new server-side field types.

### Settings screen

Subscription/tier status (from `auth/session`), the native account forms,
a link to the App Sessions page (webview), sign out.

### Upgrade gate

Every request sends the `client-app` / `client-version` headers (hyphen
form — see the client headers section of `docs/api.md`). Any 426
`UpgradeRequired` response — including at login — renders as a blocking
upgrade screen with a Play Store deep link. Minimums per app in the
`api_min_client_versions` setting.

## Webview contract (`joinery-android` component)

- In-app WebView with its own cookie store holding only the bridged web
  session; bridge on first use, silent re-bridge on logged-out detection
  (one retry, then surface login). The user never sees a web login page in
  normal use.
- **Scope:** same-origin only; off-site links open in the default browser
  (Custom Tabs); member-surface navigation stays in-webview.
- The native shell owns the app bar title and back handling (system back
  maps to webview history, then to shell navigation).
- File uploads (system picker) and downloads (DownloadManager / share)
  work; pull-to-refresh; standard loading, error, and offline-retry states.
- Sign-out clears the webview cookie store along with revoking the API key.

## Security notes

- Secrets live only in Keystore-backed `EncryptedSharedPreferences` — never
  plain SharedPreferences or files.
- Apps only ever hold session-type keys; the machine-key-only management API
  boundary (`docs/api.md`) is untouched.

## Delivery phases & test gates

Strictly sequential; each later gate re-runs earlier suites as regression.
Every phase runs in the emulator against `dev.getjoinery.com`; a physical
device is needed only for final Play validation.

**Phase 0 (dependency):** the server platform from
`specs/ios_app_platform.md` Phase 1 (navigation endpoint, bridge, app mode)
has shipped through its own gate.

### Phase 1 — `joinery-android` native core

Login screen, encrypted key storage, the generic form renderer, settings
screen, 426 gate.

**Gate:** Compose UI test suite in the emulator — log in/out; complete both
password-reset steps natively (use a `*@inbox.dev.getjoinery.com` account so
the reset email is readable from `iem_inbound_email_messages`); a
server-side form-definition change appears without a rebuild;
invalid-credential, rate-limit, and 426 paths render correctly.

### Phase 2 — Navigation + webviews

Bottom bar and More list from the navigation endpoint; the webview component
with bridging, chrome-less rendering, link policy, and silent re-bridge.

**Gate:** emulator — the calendar is usable in-app; orders and conversations
load; a mailbox-granted user reads and replies to mail (depends on
`specs/implemented/inbound_email_profile_mailbox.md`); a new plugin `profileMenu` entry
appears without a release; revoking the app's session from the web signs out
both layers; external links leave the app; system back behaves correctly
across webview history and shell navigation.

### Phase 3 — Reference app ships

Joinery member app branding, Play Store assets, closed testing track,
review. Login-only (registration toggle off), so Play's account-deletion
policy is not triggered.

**Gate:** on a physical device — install → sign in → every navigation entry
reachable and chrome-less; Play review passes.

## Dependencies

- `specs/ios_app_platform.md` — the server pieces (navigation endpoint,
  web-session bridge, app display mode) and the platform design they encode.
- `specs/implemented/inbound_email_profile_mailbox.md` — email in the app for granted
  users.

## Consumers

- **Joinery member app for Android** — the reference app, delivered by this
  spec.
- **ScrollDaddy Android** (`specs/scrolldaddy_android_app.md`) — adds the
  VpnService DNS-filtering layer on this platform.

## Acceptance checklist

1. Login, logout, and both password-reset steps are fully native — the web
   login page never appears in the app.
2. Account edit and contact preferences render natively from server JSON; a
   field added server-side shows up in the shipped app with no release.
3. The bottom bar and More menu come from the navigation endpoint; a new
   plugin `profileMenu` entry appears with no release; permission and
   setting filtering hold.
4. The calendar and (for a granted user) the mailbox are fully usable inside
   the app with no site header/footer.
5. Webview authentication is invisible: first bridge and every re-bridge are
   silent.
6. Revoking the app's session key — App Sessions page or password change —
   invalidates both the app's API access and its webview session.
7. A 426 response, including at login, renders the blocking upgrade screen.
8. `joinery-android` builds as a standalone library with no brand imports,
   and a second app module consumes it unchanged.
9. With registration toggled off there is no signup path anywhere in-app.

## Out of scope (future specs)

- In-app billing (`specs/mobile_app_billing.md`, Play Billing flavor).
- Push notifications.
- Native screens for content surfaces — each is a small per-surface spec
  that adds a screen and flips one navigation route.
- Offline caching / local data stores.
- Wear OS and tablet-optimized layouts; F-Droid distribution.

## Documentation deliverables (on implementation)

- `docs/mobile_apps.md` — Android section: `joinery-android` integration
  guide alongside JoineryKit (repo location, configuration surface, how to
  stand up a new branded app).
