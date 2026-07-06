# Mobile App Platform

The server-side foundation for native apps (iOS and Android) on any Joinery
deployment. An app is a native shell — login, account forms, navigation,
settings — around the authenticated web surface: every `/profile` page renders
inside the app through a chrome-less webview, and any surface can later be
promoted to a native screen without auth or navigation rework.

Three server pieces make this work, all client-agnostic:

1. **Navigation endpoint** — `GET /api/v1/app/navigation` returns the user's
   menu as a routing table for the app's tab bar and More list.
2. **Web-session bridge** — `POST /api/v1/auth/web_session` + `/app_bridge`
   turn the app's API session key into a web session for the webview, with no
   second login.
3. **App display mode** — bridged web sessions render pages without site
   chrome (header, navigation, footer).

Everything else an app consumes is the standard API surface: session keys
(`docs/api.md` § Auth Endpoints), actions, and form JSON
(`docs/formwriter.md` § JSON Output Mode).

## Navigation endpoint

`GET /api/v1/app/navigation` — requires an app **session key** (machine keys
and browser sessions get 403). Served by `includes/ApiAppEndpoint.php`.

The source is the seeded profile menu store (`amu_admin_menus`, location
`user_dropdown`) — the same store every web theme renders — so adding a core
entry in `admin_menus.json` or a plugin `profileMenu` entry makes it appear in
every shipped app with no release. Standard store filters apply: the entry's
`permission` vs the user's, `visibility` (app users are always signed in),
`settingActivate`, and `disable`. Entries the native shell owns (sign in/out,
sign up, forgot password) are excluded.

Response shape:

```json
{
    "data": {
        "tabs": ["core-profile", "core-calendar", "mailbox"],
        "entries": [
            {
                "slug": "core-profile",
                "title": "My Profile",
                "icon": "user",
                "order": 50,
                "destination": { "type": "web", "url": "/profile" }
            }
        ]
    }
}
```

- **`entries`** — every menu entry this user gets, ordered. Each has a
  `destination` the client resolves version-safely: `{type: "web", url}`
  renders in the authenticated webview; `{type: "native", screen,
  fallback_url}` renders the named native screen when the shipped client
  recognizes `screen`, otherwise loads `fallback_url`. Promoting a surface to
  native is a destination change — old app versions keep working through the
  fallback.

  A menu entry names its native screen with a `nativeScreen` key on the
  entry in `admin_menus.json` or the plugin's `plugin.json` `profileMenu`
  (stored as `amu_native_screen` by the menu sync). A non-empty value flips
  that entry's destination to `{type: "native"}` with the entry's URL as the
  fallback. Worked examples: the mailbox plugin's mailbox entry
  declares `"nativeScreen": "mailbox"` (rendered by JoineryMailKit), the core
  `core-calendar` entry in `admin_menus.json` declares `"nativeScreen":
  "calendar"` (rendered by JoineryCalendarKit), and the joinery_ai plugin's AI
  Chat entry declares `"nativeScreen": "ai_chat"` (rendered by
  JoineryAIChatKit); every other client keeps the web page.
- **`tabs`** — the slugs pinned to this app's tab bar, in order; everything
  else belongs in the More list. Configured by the `app_navigation` setting: a
  JSON map of `client_app` (the client header value) → ordered slug list, with
  a `default` key for apps without their own entry. Slugs the user did not
  receive are dropped.

## Web-session bridge

The app authenticates once (`POST /api/v1/auth/login`) and holds a session
key. When a webview needs the web surface, the app derives a web session from
that key — the user never sees a web login page.

**Minting:** `POST /api/v1/auth/web_session` with the session key. Body:
`{"target": "/profile/calendar"}` — a same-origin relative path (absolute
URLs, protocol-relative `//host` forms, backslashes, and raw whitespace are
rejected 400; omitted defaults to `/`). Session keys only: browser sessions
already are a web session (403), and machine keys are integration
credentials, not devices (403). Returns:

```json
{ "data": { "bridge_url": "/app_bridge?token=…64 hex…", "expires_in": 60 } }
```

**Consumption:** the webview loads `bridge_url`. `/app_bridge`
(`logic/app_bridge_logic.php`) claims the token atomically — single-use by
construction — verifies the originating key is still live, starts a normal
web session for the key's user (session id regenerated), marks it
**app-context** with the originating `apk_api_keys` id and `client_app`, and
302s to the target. Invalid, used, or expired tokens render an "expired link"
page (HTTP 410); the app's recovery is minting a fresh token.

Tokens live in `abt_app_bridge_tokens` (`data/app_bridge_tokens_class.php`):
60-second TTL, stored as SHA-256 hashes, purged opportunistically on mint.
The plaintext exists only in the mint response and is never logged.

**Lifetime coupling:** an app-context web session is valid only while its
originating API key is. `SessionControl` re-verifies the key (throttled to
`app_bridge_key_check_seconds`, default 60; `0` = every request) and ends the
session when the key is revoked, expired, or gone. Revoking the key — app
logout, the App Sessions page at `/profile/security`, or a password change —
therefore kills both the app's API access and its webview session in one
gesture.

**App behavior:** bridge on first webview use; the webview cookie store
persists the session across launches; on detecting a logged-out response the
shell silently re-bridges and retries once before surfacing anything.

## App display mode

App-context sessions render pages without the site header, navigation, and
footer — page content and the `jy-ui` design system only. The native shell
supplies titles and back navigation.

The decision lives in one place: `PublicPageBase::show_site_chrome()` (false
for app-context sessions). Every theme wraps its chrome markup in it:

```php
<?php if ($this->show_site_chrome()): ?>
<nav class="site-nav">…</nav>
<?php endif; ?>
```

Only the visual chrome is conditional — stylesheets, scripts, and content
wrappers stay unconditional. `PublicPageBase` also adds a `jy-app-mode` body
class as a CSS hook and suppresses the admin bar for app-context sessions.
Themes must apply the same wrap to their header and footer blocks; the check
is a constant `true` for all ordinary traffic, so wrapped chrome renders
byte-identically outside app mode.

## Settings

| Setting | Default | Purpose |
|---|---|---|
| `app_navigation` | `{"default": ["core-profile", "core-calendar", "mailbox"]}` | Per-app tab-bar pinning (JSON map of `client_app` → ordered slugs) |
| `app_bridge_key_check_seconds` | `60` | How often a bridged session re-verifies its originating key |
| `api_min_client_versions` | `{}` | Per-app minimum client versions (HTTP 426 gate, `docs/api.md` § Client Versioning) |
| `api_session_key_lifetime_days` | `365` | Session key expiry |

## JoineryKit (iOS client core)

The native iOS core is **JoineryKit**, a standalone Swift package with no
brand knowledge — every branded app is a thin target that injects a
`JoineryConfig` and mounts `JoineryAppRoot`. The source lives in this repo
at `{repo root}/ios/` (outside `public_html`, so it is never web-served or
packaged into node upgrades): `ios/joinery-kit` is the package,
`ios/joinery-member-ios` the reference app (XcodeGen `project.yml`,
regenerate with `xcodegen generate`). Builds run on the Mac mini
(`ssh macmini`) against a synced build area at `~/dev/joinery-ios` — the
gate runners rsync `ios/` there before building; the repo checkout on the
dev box is the single source of truth.

What the kit provides:

- **APIClient** — the one HTTP chokepoint: key headers, `client-app` /
  `client-version` headers (hyphen form), automatic `Idempotency-Key` on
  action submits, and error-envelope mapping onto a typed `JoineryAPIError`.
  API JSON is parsed with an order-preserving parser (`JSONValue`) because
  form `options` order is meaningful and Foundation's decoders lose it.
- **SessionController + KeychainStore** — login (`auth/login` with a
  `device_label`), Keychain-only secret storage, launch bootstrap via
  `auth/session`, logout (`auth/logout`), and global handling of two
  cross-cutting signals: any 426 flips the app into the blocking
  `UpgradeRequiredView`, and a 401 on an authenticated call (key revoked —
  App Sessions page or password change) signs the app out.
- **Generic form renderer** — `FormScreen` fetches
  `GET /api/v1/form/{action}`, renders every schema-v1 field type
  (`docs/formwriter.md` § JSON Output Mode) including `visibility_rules`
  with web-identical trigger semantics, submits to
  `POST /api/v1/action/{action}`, and maps 422 field errors back onto
  controls. A definition with an unknown field type or a newer
  `schema_version` renders a per-form "update the app or use the website"
  fallback, so old binaries survive new server-side field types.
- **Navigation shell** — `NavigationShell` renders the signed-in surface
  from `GET /api/v1/app/navigation`: server-pinned entries fill the tab bar
  (at most four; the last slot is always More), everything else plus the
  native Settings screen lands in the More list. Destinations resolve
  version-safely (`NavDestination`): `web` renders in the authenticated
  webview; `native` renders the named screen when the build recognizes it
  and its `fallback_url` otherwise. Screen names resolve against the kit's
  own screens first (`settings`), then `NativeScreenRegistry` — the table
  layered modules add their screens to at app launch
  (`JoineryMail.registerScreens()` registers `mailbox`). Server icon names
  map onto SF Symbols with a neutral fallback. The shell refreshes the user
  summary and the navigation table on every foreground, so menu changes
  appear and a session revoked from the web signs the app out without a
  relaunch.
- **Webview component** — `WebScreen` is how every web destination renders,
  implementing the whole webview contract in one place
  (`WebSessionCoordinator` + `WebScreen`): first use mints a bridge URL
  (`POST /api/v1/auth/web_session`) and drives the webview through it; the
  bridged session lives in the shared persistent cookie store, so later
  screens and later launches load their targets directly. A `/login`
  redirect (bridged session gone) or an expired bridge token (410) triggers
  one silent re-bridge back to the intended path; a dead API key makes the
  re-bridge mint 401, which signs out the whole app. Link policy: same-origin
  navigations stay in-webview, off-site links and non-web schemes leave the
  app (Safari, Mail), subframes are untouched. The native bar shows the page
  title and a history-back chevron; pull-to-refresh, file uploads (WKWebView
  native pickers), downloads (share-sheet hand-off), JS alert/confirm/prompt
  as native panels, and loading/error/offline-retry states are built in.
  The webview stays inside the safe area — extending under the native tab
  bar would leave fixed-bottom web UI (cookie consent, sticky action bars)
  visible but untappable behind it.
  Sign-out clears the site's cookies from the shared store (the
  `SessionController.onSignOut` hook).
- **Native screens** — `LoginView`, the two-step native password-reset flow
  (`password_reset_1`, code entry from the emailed link, `password_reset_2`
  with the code round-tripped via query context), `SettingsView` (user/tier
  summary from `auth/session`, the account forms, the App Sessions page as a
  webview destination, sign out), and `UpgradeRequiredView`.

Brand configuration surface (`JoineryConfig`): `baseURL`, `clientApp`,
`clientVersion`, `appName`, `appStoreURL`, `registrationEnabled` (off by
default — enabling in-app registration triggers Apple's account-deletion
requirement), `accentColor`.

Control accessibility identifiers are stable API for UI tests: form controls
use their server field names, and screens use prefixed ids
(`login_*`, `settings_*`, `form_*`, `reset_*`, `upgrade_*`, `nav_*`,
`web_*`, `mail_*`, and `more_{slug}` for More-list rows).

## JoineryMailKit (native mail module)

**JoineryMailKit** is the first native content surface — the same package
repo, second product (`ios/joinery-kit/Sources/JoineryMailKit`, depends on
JoineryKit). An app adds the product and calls `JoineryMail.registerScreens()`
in its init; the server's `mailbox` navigation destination then renders these
screens, and builds without the module keep the web reader via the fallback
URL.

The screens consume the `mailbox/*` actions
(`plugins/mailbox/docs/overview.md` § API Surface) — the same
`MailboxService` brain as the web reader, so every read/star/archive/spam
change is immediately visible in both:

- **Mailbox screen** (`MailboxScreen` + `MailboxStore`) — Gmail-style thread
  list: colored-initial avatars, bold unread rows, star toggles, snippet
  lines; views (Inbox / Starred / All Mail / Spam) and a mailbox switcher in
  the toolbar menu; server-side search (`.searchable`, submit-to-search);
  swipe actions (archive full-swipe, read/unread toggle); pull-to-refresh
  and page-on-scroll (50/page). A toolbar compose button
  (`square.and.pencil`, disabled unless `can_compose`) opens `ComposeSheet`
  in new-message mode.
- **Thread view** (`ThreadDetailView` + `MessageCardView`) — subject header,
  message cards with older messages collapsed; opening marks the thread read
  (the reader's explicit `mark_read`); HTML bodies render in a sandboxed
  `WKWebView` (content JavaScript off, link taps open externally, height
  self-measured); plain bodies render as native text; inline images arrive
  as signed URLs already rewritten server-side; attachment chips download
  via their signed URL into the share sheet. Toolbar: archive, mark unread,
  spam/not-spam, delete.
- **Compose** (`ComposeSheet`) — reply / reply-all / forward / new message
  with To/Cc/Subject and body. Deliberately lean: for reply/forward the
  server quotes the original, normalizes the subject, applies threading
  headers, and resolves the sending identity from the source message; for a
  new message there is no source, so the sheet shows a From `Picker` over
  the granted mailboxes (`MailboxStore.home.mailboxes`, preselecting
  `selectedAlias`) and the server takes the picked `alias_id` as the
  identity instead, with no quoted-original footer text. An attach menu
  offers Photo Library (HEIC transcoded to JPEG) and Files (any type, no
  allowlist — matching the server's policy); picked files show as removable
  chips. An attachments-only send goes out as `multipart/form-data` to
  `mailbox/send` (`APIClient.submitMultipart`, field
  `attachments[]`), otherwise the plain JSON action — the same
  `ChatAPI.send()` shape the AI chat composer uses. Client-side preflight
  mirrors the server's caps (10 files, 10 MB per file, 25 MB total) but the
  server is the authority; a rejected send keeps the draft and attachments in
  the sheet. Forwards still re-attach the original's attachments
  server-side; a sent copy with new uploads shows them in the thread view
  once the manifest persists — no separate rendering path.

Not in the module (the web reader remains for them): labels/move and
create-folder, filter management, spam settings.

## JoineryCalendarKit (native calendar module)

**JoineryCalendarKit** is the native personal-calendar surface — same
package repo, third product (`ios/joinery-kit/Sources/JoineryCalendarKit`,
depends on JoineryKit). An app adds the product and calls
`JoineryCalendar.registerScreens()` in its init; the server's `calendar`
navigation destination then renders these screens.

The screens consume the core calendar actions (`docs/calendar.md` § API
surface) — the same aggregation and write path as `/profile/calendar`, so
every entry is immediately visible on both:

- **Calendar screen** (`CalendarScreen` + `CalendarStore`) — a month grid
  (leading-blank cells honoring the device's first weekday, colored event
  dots per day, today ring, selected-day fill) over a selected-day agenda;
  chevrons and horizontal swipe change months, "Today" returns; each month
  fetches its window padded a week per side. Items group onto local days in
  the device timezone (`CalDisplay.dayKeys`), all-day items first.
- **Agenda rows** — native entries open the entry editor; projected items
  (events, bookings) show their type chip and push their web page in the
  authenticated webview (`WebScreen`) — the calendar never edits what it
  doesn't own.
- **Entry editor** (`EntryEditorSheet`) — title, date, all-day or timed,
  block-availability toggle, and the full recurrence surface (frequency,
  interval, weekly day chips, monthly same-day / ordinal-weekday, ends
  never / on date / after N). Recurring edits and deletes ask for scope
  (this / future / all) exactly like the web modals; a "this occurrence
  only" save sends no recurrence (the series keeps its own). Wall-clock
  values go up with the entry's stored timezone on edit, the device zone on
  create; the server does all timezone math.

Accessibility ids (`cal_*`) are the stable UI-test API: `cal_loading`,
`cal_error`, `cal_retry`, `cal_grid`, `cal_month_title`, `cal_prev_month`,
`cal_next_month`, `cal_today`, `cal_add`, `cal_agenda`, `cal_selected_day`,
`cal_agenda_empty`, and in the editor `cal_entry_title`, `cal_entry_date`,
`cal_entry_allday`, `cal_entry_start`, `cal_entry_end`, `cal_entry_blocks`,
`cal_entry_repeats`, `cal_entry_save`, `cal_entry_cancel`,
`cal_entry_delete`, `cal_entry_error`. (JoineryMailKit's `mail_*` ids follow
the same convention.)

Not in the module (the web calendar remains for them): `.ics` import and
the quick-entry popover's time-prefix parsing.

## JoineryAIChatKit (native AI chat module)

**JoineryAIChatKit** is the native AI-assistant surface — same package repo,
fourth product (`ios/joinery-kit/Sources/JoineryAIChatKit`, depends on
JoineryKit). An app adds the product and calls
`JoineryAIChat.registerScreens()` in its init; the server's `ai_chat`
navigation destination then renders these screens, and builds without the
module keep the web chat via the fallback URL.

The screens consume the `joinery_ai/chat_*` actions
(`plugins/joinery_ai/docs/overview.md` § Chat API surface) — the same
conversation store and turn engine as the web chat, so a
conversation opened natively and one opened on the web are the same thread:

- **Conversation list** (`ChatScreen` + `ChatListStore`) — the caller's
  conversations pinned-first then newest, with server-side search, swipe and
  context-menu pin / rename / delete, and a compose button that opens a new
  chat. Reaching the native screen is proof the server flipped the entry to
  `{type: "native", screen: "ai_chat"}`; an older build loads the web chat.
- **Thread + composer** (`ChatThreadView` + `ChatThreadStore`) — the turns in
  a scroll (user bubbles right, assistant markdown left), a bottom composer,
  and per-turn / conversation token-cost labels. A running turn streams via
  the same send-then-poll transport the web reader uses: `chat_send` returns a
  poll handle, the store polls `chat_poll` (~600ms) folding partial text into
  the row until it settles; the poll's `activity` + `running_seconds` render
  as a live status line on the running turn ("Waiting for glm-5p2… · 2m 40s").
  A turn that proposes a mutating action shows an
  inline Confirm / Cancel card resolved through `chat_confirm`. Turn delete
  and a lightweight block-markdown renderer (headings, bullets, rules, inline
  emphasis) round it out.
- **Settings sheet** (`ChatSettingsSheet`, gear toolbar button) — the per-chat
  controls: a model picker (catalog from `chat_controls`, with a not-private
  warning), Data access / Web search toggles, a thinking-level picker, and the
  sampling fields (temperature, top-p, max tokens) plus custom instructions.
  Picker and toggle edits persist immediately via `chat_set_capabilities`; a
  new chat carries its control values as seed fields on the first `chat_send`.
  A new chat defaults Data access on so the assistant is useful out of the box;
  the rest take the server's new-chat defaults.
- **Attachments** (`ChatThreadView` picker + `AttachmentsView`) — the composer's
  attach button offers Photo Library (`PhotosPicker`) and Files
  (`.fileImporter`, accepting PDF, text, CSV, JSON, Markdown, and images). Picked
  files show as removable chips and send as `multipart/form-data` to `chat_send`
  (`APIClient.submitMultipart`, field `attachments[]`); an attachments-only turn
  needs no message text. HEIC photos transcode to JPEG client-side so the
  server's byte-detected type lands in its allowed set. The server owns all
  validation — type, size, and the model's vision/document capability — and is
  the sole authority: a rejection surfaces its message, and a file dropped at
  commit for type drift comes back as `attachment_warning`, shown above the
  composer. Sent and historical attachments render on the user bubble — images
  as a thumbnail from the signed `image_url` the serializer mints
  (`File::mintSignedUrl`, 5-minute TTL, so a cookieless native client loads it),
  everything else as a labeled file chip.

Accessibility ids (`chat_*`) are the stable UI-test API: `chat_loading`,
`chat_error`, `chat_retry`, `chat_list`, `chat_empty`, `chat_new`,
`chat_thread_loading`, `chat_thread_empty`, `chat_transcript`,
`chat_user_message`, `chat_assistant_message`, `chat_typing`,
`chat_activity`, `chat_confirm_card`, `chat_confirm_yes`, `chat_confirm_no`, `chat_composer`,
`chat_send`, `chat_attach`, `chat_attachment_strip`, `chat_attachment_remove`,
`chat_attachment_notice`, `chat_attachment_image`, `chat_attachment_chip`,
`chat_settings`, `chat_set_model`, `chat_set_data_access`,
`chat_set_web_search`, `chat_set_thinking`, `chat_settings_done`.

Not in the module yet (the web chat remains for it): thread export.

## joinery-android (Android client core)

The native Android core is **joinery-android**, a Kotlin + Jetpack Compose
library with no brand knowledge — every branded app is a thin module that
injects a `JoineryConfig` and mounts `JoineryAppRoot`. It is the Android
counterpart to JoineryKit against the same client-agnostic server surface
(navigation endpoint, web-session bridge, app display mode); the reusable
boundary between the platforms is that server API, not shared client code.
The source lives in this repo at `{repo root}/android/` (outside
`public_html`, so it is never web-served or packaged into node upgrades):
`android/joinery-android` is the library module, `android/joinery-member-android`
the reference app. Builds run on the Mac mini (`ssh macmini`) against a synced
build area at `~/dev/joinery-android` — `./gradlew`/`gradle` with the SDK from
`~/.android-env`, headless emulator, screenshots via
`adb exec-out screencap`; the repo checkout on the dev box is the single
source of truth.

What the library provides:

- **ApiClient** — the one HTTP chokepoint (OkHttp): key headers, `client-app` /
  `client-version` headers (hyphen form), automatic `Idempotency-Key` on action
  submits, and error-envelope mapping onto a typed `JoineryApiError`. API JSON is
  parsed with an order-preserving parser (`JsonValue`) because form `options`
  order is meaningful and stock decoders lose it.
- **SessionController + EncryptedCredentialStore** — login (`auth/login` with a
  `device_label`), Keystore-backed `EncryptedSharedPreferences` secret storage
  (never plain SharedPreferences or files), launch bootstrap via `auth/session`,
  logout (`auth/logout`), and the two cross-cutting signals: any 426 flips the
  app into the blocking upgrade screen, and a 401 on an authenticated call (key
  revoked — App Sessions page or password change) signs the app out.
- **Generic form renderer** — `FormScreen` fetches `GET /api/v1/form/{action}`,
  renders every schema-v1 field type (`docs/formwriter.md` § JSON Output Mode)
  including `visibility_rules` with web-identical trigger semantics, submits to
  `POST /api/v1/action/{action}`, and maps 422 field errors back onto controls.
  A definition with an unknown field type or a newer `schema_version` renders a
  per-form "update the app or use the website" fallback, so old binaries survive
  new server-side field types.
- **Navigation shell** — `NavigationShell` renders the signed-in surface from
  `GET /api/v1/app/navigation`: server-pinned entries fill the bottom bar (at
  most four; the last slot is always More), everything else plus the native
  Settings screen lands in the More list. Destinations resolve version-safely
  (`NavDestination`): `web` renders in the authenticated webview; `native`
  renders the named screen when the build recognizes it and its `fallback_url`
  otherwise. Screen names resolve against the library's own screens first
  (`settings`), then `NativeScreenRegistry` — the table layered modules add
  their screens to at app launch. Server icon names map onto Material icons with
  a neutral fallback. The shell refreshes the user summary and the navigation
  table on every foreground, so menu changes appear and a session revoked from
  the web signs the app out without a relaunch.
- **Webview component** — `WebScreen` is how every web destination renders,
  implementing the whole webview contract in one place (`WebSessionCoordinator`
  + `WebScreen`): first use mints a bridge URL (`POST /api/v1/auth/web_session`)
  and drives the WebView through it; the bridged session lives in the
  process-wide WebView cookie store, so later screens and later launches load
  their targets directly. A `/login` redirect (bridged session gone) or an
  expired bridge token (410) triggers one silent re-bridge back to the intended
  path; a dead API key makes the re-bridge mint 401, which signs out the whole
  app. Link policy: same-origin navigations stay in-webview, off-site links open
  in Custom Tabs and non-web schemes (mailto, tel) leave the app, subframes are
  untouched. The native app bar shows the page title; system back walks webview
  history before the shell's navigation. Pull-to-refresh, file uploads (SAF
  picker), downloads (DownloadManager, carrying the bridged cookie), and
  loading/error/offline-retry states are built in. Sign-out clears the site's
  cookies and web storage (the `SessionController.onSignOut` hook).
- **Native screens** — `LoginScreen`, the two-step native password-reset flow
  (`password_reset_1`, code entry from the emailed link, `password_reset_2` with
  the code round-tripped via query context), `SettingsScreen` (user/tier summary
  from `auth/session`, the account forms, the App Sessions page as a webview
  destination, sign out), and `UpgradeRequiredScreen`.

Brand configuration surface (`JoineryConfig`): `baseUrl`, `clientApp`,
`clientVersion`, `appName`, `playStoreUrl`, `registrationEnabled` (off by
default — enabling in-app registration triggers Google Play's
account-deletion requirement), `accentColor`.

Compose `testTag` values equal the server field names for form controls and use
prefixed ids for screens (`login_*`, `settings_*`, `form_*`, `reset_*`,
`upgrade_*`, `root_*`, `nav_*`, `web_*`, and `more_{slug}` for More-list rows) —
the stable addressing for the Compose UI-test suite.

## joinery-android-mail (native mail module)

**joinery-android-mail** is the Android native mail surface — a second library
module in the Android workspace (`android/joinery-android-mail`, depends on
`:joinery-android`), the platform counterpart to JoineryMailKit. An app adds
the module and calls `JoineryMail.registerScreens()` before mounting the root;
the server's `mailbox` navigation destination then renders these screens, and
builds without the module keep the web reader via the fallback URL.

The screens consume the same `mailbox` API actions as JoineryMailKit
(`plugins/mailbox/docs/overview.md` § API Surface) and mirror its UX;
the platforms share fixtures (the same captured envelopes back both test
suites) but no client code:

- **Mailbox screen** (`MailboxScreen` + `MailboxStore`) — Gmail-style thread
  list: colored-initial avatars, bold unread rows, star toggles, snippet
  lines; a top-bar filter menu with views (Inbox / Starred / All Mail /
  Spam), a mailbox switcher (when more than one grant), and the mailbox's
  folder/label rail (`Mailbox.folders` — selecting one scopes the list via
  `thread_list`'s `folder_id`); a search field (submit-to-search,
  clear-to-reset); swipe actions (`SwipeToDismissBox`: read/unread toggle
  and archive); pull-to-refresh, refresh-on-foreground, and page-on-scroll
  (50/page). A top-bar compose button (disabled unless `can_compose`) opens
  `ComposeSheet` in new-message mode.
- **Thread view** (`ThreadDetailScreen` + `MessageCard`) — subject header,
  message cards with older messages collapsed; opening marks the thread read
  (the reader's explicit `mark_read`); HTML bodies render in a sandboxed
  `WebView` (JavaScript off, link taps open externally, height
  self-measured); plain bodies render as native selectable text; inline
  images arrive as signed URLs already rewritten server-side; attachment
  chips download via their signed URL into the system viewer/share sheet
  (module-declared `FileProvider`, cache-scoped). Top bar: archive, the
  Move/Labels control, mark unread, spam/not-spam, delete.
- **Move/Labels picker** (`FolderSheet`, on the open thread) — the web
  reader's folder control as a bottom sheet, keyed off the thread's own
  mailbox (resolved from the messages' `alias_id`, so it works in the
  all-mailboxes list). An exclusive feed gets single-pick "Move to" radio
  rows (choosing one relocates the thread and closes it); a non-exclusive
  feed (Gmail) gets "Labels" checkboxes (toggling calls `set_membership`
  per change). Both end with a create row (`create_folder`, which also
  files the thread into the new folder). Current membership comes from the
  `thread` action's `folders` ids.
- **Compose** (`ComposeSheet`) — reply / reply-all / forward / new message
  with To/Cc/Subject and body, as a full-screen dialog. Same lean contract
  as iOS: the server quotes, normalizes the subject, applies threading
  headers, and resolves the sending identity (source message, or the From
  dropdown's `alias_id` for a new message). The attach menu offers Photos
  (`image/*`) and Files (`*/*` — any type, no allowlist, nothing
  transcoded; the server re-detects types from bytes); picked files show as
  removable rows. An attachments send goes out as `multipart/form-data` to
  `mailbox/send` (`ApiClient.submitMultipart`, field
  `attachments[]`), otherwise the plain JSON action. Client-side preflight
  mirrors the server's caps (10 files, 10 MB per file, 25 MB total) but the
  server is the authority; a rejected send keeps the draft and attachments
  in the sheet.

Compose `testTag` values (`mail_*`) are the stable UI-test addressing:
`mail_loading`, `mail_error`, `mail_retry`, `mail_list`, `mail_row`,
`mail_row_star`, `mail_empty`, `mail_search`, `mail_view_menu`,
`mail_new_message`, `mail_thread_back`, `mail_thread_subject`,
`mail_thread_loading`, `mail_thread_menu`, `mail_archive`, `mail_folders`,
`mail_folder_sheet`, `mail_folder_new`, `mail_folder_create`, `mail_reply`,
`mail_reply_all`, `mail_forward`, `mail_message_{id}`,
`mail_attachment_{id}`, and `mail_compose_*` (`from`, `to`, `cc`, `subject`,
`body`, `attach`, `attachment_remove`, `send`, `cancel`, `error`).

Not in the module (the web reader remains for them): filter management and
Gmail filter import, spam settings, admin oversight surfaces.

## joinery-android-calendar (native calendar module)

**joinery-android-calendar** is the Android native personal-calendar surface
(`android/joinery-android-calendar`, depends on `:joinery-android`), the
platform counterpart to JoineryCalendarKit. An app adds the module and calls
`JoineryCalendar.registerScreens()` before mounting the root; the server's
`calendar` navigation destination then renders these screens.

The screens consume the core calendar actions (`docs/calendar.md` § API
surface) and mirror the iOS module's UX; the fixtures are shared across the
platforms' test suites but no client code is:

- **Calendar screen** (`CalendarScreen` + `CalendarStore`) — a month grid
  (leading-blank cells honoring the device's first weekday, colored event
  dots per day, today ring, selected-day fill) over a selected-day agenda;
  chevrons and horizontal swipe change months, "Today" returns; each month
  fetches its window padded a week per side. Items group onto local days in
  the device timezone (`CalDisplay.dayKeys`), all-day items first. The
  agenda pull-refreshes.
- **Agenda rows** — native entries open the entry editor; projected items
  (events, bookings) show their type chip and push their web page in the
  authenticated webview (`WebScreen`) — the calendar never edits what it
  doesn't own.
- **Entry editor** (`EntryEditorSheet`) — title, date, all-day or timed
  (platform date/time pickers), availability blocking, and the full
  recurrence surface: frequency, interval stepper, weekly day chips
  (turning Repeats on pre-selects the entry date's weekday), monthly
  by-weekday (week-of-month + weekday), and series end (never / on date /
  after N). Editing fetches `calendar_entry` and populates all of it; saves
  go to `calendar_entry_save` with the entry's stored IANA timezone (device
  zone for new entries). Recurring edits and deletes ask for scope
  (this / future / all) exactly like the web form — a "this occurrence
  only" save omits the recurrence payload so the settings stay on the
  series.

Compose `testTag` values (`cal_*`) are the stable UI-test addressing:
`cal_loading`, `cal_error`, `cal_retry`, `cal_grid`, `cal_month_title`,
`cal_prev_month`, `cal_next_month`, `cal_today`, `cal_add`, `cal_agenda`,
`cal_agenda_row`, `cal_agenda_empty`, `cal_selected_day`, and
`cal_entry_*` (`title`, `date`, `allday`, `start`, `end`, `blocks`,
`repeats`, `monthly_weekday`, `ends_date`, `save`, `cancel`, `delete`,
`error`).

## joinery-android-ai-chat (native AI chat module)

**joinery-android-ai-chat** is the Android native AI-assistant surface
(`android/joinery-android-ai-chat`, depends on `:joinery-android`), the
platform counterpart to JoineryAIChatKit. An app adds the module and calls
`JoineryAIChat.registerScreens()` before mounting the root; the server's
`ai_chat` navigation destination then renders these screens.

The screens consume the `joinery_ai/chat_*` actions
(`plugins/joinery_ai/docs/overview.md` § Chat API surface) and mirror the iOS
module's UX; the fixtures are shared across the platforms' test suites but no
client code is:

- **Conversation list** (`ChatScreen` + `ChatListStore`) — the caller's
  conversations pinned-first then newest, with server-side search,
  pull-to-refresh, a long-press menu for pin / rename / delete, and a
  compose button that opens a new chat.
- **Thread + composer** (`ChatThreadView` + `ChatThreadStore`) — the turns
  in a scroll (user bubbles right, assistant markdown left), a bottom
  composer, and per-turn / conversation token-cost labels. A running turn
  streams via the same send-then-poll transport the web reader uses:
  `chat_send` returns a poll handle, the store polls `chat_poll` (~600ms)
  folding partial text into the row until it settles; the poll's `activity`
  + `running_seconds` render as a live status line on the running turn
  ("Waiting for glm-5p2… · 2m 40s"). A turn that proposes
  a mutating action shows an inline Confirm / Cancel card resolved through
  `chat_confirm`. Long-press deletes a turn; a lightweight block-markdown
  renderer (headings, bullets, rules, inline bold/italic/code) rounds it
  out.
- **Settings sheet** (`ChatSettingsSheet`, gear top-bar button) — the
  per-chat controls: a model picker (catalog from `chat_controls`, with a
  not-private warning), Data access / Web search toggles, a thinking-level
  picker, and the sampling fields (temperature, top-p, max tokens) plus
  custom instructions. Picker and toggle edits persist immediately via
  `chat_set_capabilities`; a new chat carries its control values as seed
  fields on the first `chat_send`. A new chat defaults Data access on so
  the assistant is useful out of the box; the rest take the server's
  new-chat defaults.
- **Attachments** (composer attach menu + turn rendering) — Photos
  (`image/*`) and Files (the server's non-image allowed set: PDF, text,
  CSV, JSON, Markdown, and images). Picked files show as removable chips
  and send as `multipart/form-data` to `chat_send`
  (`ApiClient.submitMultipart`, field `attachments[]`); an attachments-only
  turn needs no message text. Images whose byte type isn't in the server's
  allowed set (notably HEIC) transcode to JPEG client-side. The server owns
  all validation and is the sole authority: a rejection surfaces its
  message, and a file dropped at commit for type drift comes back as
  `attachment_warning`, shown above the composer. Sent and historical
  attachments render on the user bubble — images as a thumbnail from the
  signed `image_url` the serializer mints (5-minute TTL, so a cookieless
  native client loads it), everything else as a labeled file chip.

Compose `testTag` values (`chat_*`) are the stable UI-test addressing:
`chat_loading`, `chat_error`, `chat_retry`, `chat_list`, `chat_row`,
`chat_empty`, `chat_search`, `chat_new`, `chat_back`, `chat_thread_loading`,
`chat_thread_error`, `chat_thread_empty`, `chat_transcript`,
`chat_user_message`, `chat_assistant_message`, `chat_typing`,
`chat_activity`, `chat_confirm_card`, `chat_confirm_yes`, `chat_confirm_no`, `chat_composer`,
`chat_send`, `chat_attach`, `chat_attachment_strip`,
`chat_attachment_remove`, `chat_attachment_notice`, `chat_attachment_image`,
`chat_attachment_chip`, `chat_settings`, `chat_settings_loading`,
`chat_set_model`, `chat_set_data_access`, `chat_set_web_search`,
`chat_set_thinking`, `chat_set_instructions`, `chat_settings_done`.

Not in the module (the web chat remains for it): thread export.

## Standing up a new branded app

1. Pick a `client_app` identifier (e.g. `joinery-member-ios`); the app sends
   it with `client-version` on every API request (hyphen header form).
2. Add a tab list for it to the `app_navigation` setting (or rely on
   `default`).
3. Optionally set its minimum version in `api_min_client_versions` once
   shipped.
4. **iOS:** create `ios/<app-name>` in this repo with an XcodeGen `project.yml`
   depending on the local `../joinery-kit` package, and a single app source
   file that builds a `JoineryConfig` and mounts
   `JoineryAppRoot(config:keychainService:)` — see
   `ios/joinery-member-ios` for the reference shape.
5. **Android:** add an application module under `android/<app-name>` depending
   on `:joinery-android` (plus any content modules, e.g. `:joinery-android-mail`),
   with a `MainActivity` that calls each content module's `registerScreens()`,
   builds a `JoineryConfig`, and calls `JoineryAppRoot(config, storeFileName)`
   in `setContent` — see `android/joinery-member-android` for the reference
   shape.

## Tests

- `tests/functional/api/app_platform_test.php` — server platform: navigation
  filtering and tab pinning, bridge minting and target validation,
  single-use and expiry, app display mode, and lifetime coupling (key
  revocation and password change). Runs against dev with curl; see the
  harness header for usage.
- JoineryKit + JoineryMailKit + JoineryCalendarKit unit tests — on the mini
  (after syncing `ios/` to the build area): `cd dev/joinery-ios/joinery-kit
  && xcodebuild test -scheme JoineryKit-Package -destination "platform=iOS
  Simulator,name=iPhone 16"` (JSON parser, form definition and navigation
  parsing, visibility engine, submission bodies, error mapping, mail payload
  parsing, and calendar payload parsing + month math — all against captured
  live fixtures).
- `tests/functional/ios/phase2_gate.sh` — the native-core gate: drives the
  JoineryMember XCUITest suites in the Simulator against dev, orchestrating
  server state per suite (probe field for the no-rebuild form-change proof,
  reset-code extraction from `iem_inbound_email_messages` with outbound mail
  flipped to localhost SMTP for that leg so the locally-hosted fixture inbox
  receives in seconds, 426 via `api_min_client_versions`, failed-auth rate
  limit last — it leaves the runner's IP inside the 15-minute failed-auth
  window). The fixture account (`appdev.phase2@dev.getjoinery.com`, a
  store-mode alias on the live inbound domain) has its credentials in
  `~/.joinery_app_test_creds` on the dev box; the reset suite rotates the
  password every run.
- `tests/functional/ios/phase3_gate.sh` — the navigation + webview gate:
  Phase 2 auth/form suites as regression, tab bar + More from the
  navigation endpoint, an entry create/delete round-trip on the native
  calendar screens (the soft-deleted row verified in `cal_entries`), orders
  and conversations in
  the webview, mailbox read + reply on the native mail screens (fixtures via
  `phase3_fixtures.php`:
  mailbox grant for the fixture user plus the `phase3.sender` store alias
  the reply lands on — arrival verified in `iem_inbound_email_messages`
  with outbound flipped to localhost SMTP for the leg), a plugin
  profileMenu entry appearing with no rebuild (`menu_probe.php` syncs a
  probe entry through `PluginManager::syncMenus`, prune removes it), an
  off-site probe link on `/profile` opening Safari, and — last, because it
  revokes every session key for the fixture user — Revoke All on the App
  Sessions page signing out the webview and native layers in one gesture
  (`app_bridge_key_check_seconds=0` for that leg).
