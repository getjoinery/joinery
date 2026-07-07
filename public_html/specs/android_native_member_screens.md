# Native Member Screens — Android (Profile, Orders, Subscriptions, Events)

**Status:** Draft
**Depends on:** `specs/implemented/android_app_platform.md` (the shell,
`NativeScreenRegistry`, `ApiClient`, `FormScreen`, the web-session bridge),
`specs/implemented/mobile_native_email_server_api_and_ios.md` (the
conversion pattern this spec repeats: sessioned `_logic_api()` actions +
a standalone Kotlin module + a server-side `nativeScreen` flip).

Converts the rest of the member app's navigation to native Compose. After
this spec, every profile-menu entry a stock member sees on Android renders
natively; the webview remains only for surfaces that are web by design
(listed in the disposition table below).

## Disposition of every app navigation entry (decided once, up front)

The app's menu is server-driven from the `user_dropdown` store. Full
inventory and what happens to each entry:

| Entry (slug) | Today | This spec |
|---|---|---|
| `core-profile` (My Profile) | web `/profile` | **native** screen `profile` |
| `core-orders` (Orders) | web, stale URL `/profile#orders` | **native** screen `orders`; URL fixed to `/profile/orders` |
| `core-subscriptions` (Subscriptions) | web `/profile/subscriptions` | **native** screen `subscriptions` |
| `core-events` (My Events) | web, stale URL `/profile#events` | **native** screen `events`; URL fixed to `/profile/events` |
| `core-event-sessions` (Event Sessions) | web `/profile/event_sessions` | **menu entry removed** — the page errors without an `event_id` (it is a deep link, not a destination); sessions become the detail screen inside the native events flow, and the web events list already links into it |
| `core-calendar`, `mailbox`, `joinery-ai-member-chat` | native | unchanged |
| `core-home` (Home) | web `/` | stays web — the public homepage buys nothing native for a signed-in member |
| `dns-filtering`, `dns-filtering-devices` | web | stays web here — their native surface belongs to `specs/scrolldaddy_android_app.md` (the VpnService app owns the filter editor; a shared module can be extracted there) |
| `core-admin-*` (permission 5+) | web | stays web, consistent with the mail spec's decision to leave admin oversight surfaces in the webview |

The two stale anchor URLs matter beyond cosmetics: an entry's URL becomes
the native destination's `fallback_url`, so builds without the module (and
iOS, until it catches up) must land on the real pages.

**Account sub-pages** (reached from the native profile screen, not menu
entries):

- **Native via the core `FormScreen`** (no bespoke UI — these are already
  API forms): `account_edit`, `password_edit`, `address_edit`,
  `phone_numbers_edit`, `contact_preferences`. Each has `_logic_api()` +
  `_logic_form()` today; the generic form renderer is the whole
  implementation.
- **Web bridge** (browser-session by design or inherently web):
  `/profile/security` (passkeys and Sealed Vault actions declare
  `requires_browser_session`; splitting 2FA out native would fragment one
  hub), `/profile/billing` (Stripe billing portal is an external redirect;
  invoice PDFs are Stripe URLs), photo/avatar management (PhotoHelper's
  upload surface on `/profile/account_edit`), `/profile/conversations` and
  `/notifications` (not menu entries; dashboard cards open them bridged).

## Server-side: member API actions

Four page-logic files gain `_logic_api()` companions, exposed as
`POST /api/v1/action/{name}`, sessioned (app session key, simulated as the
key's user), same pattern as the mailbox actions. Each returns exactly what
its web view renders — the web page and the native screen keep one brain.

| Action | Backing logic | Payload |
|---|---|---|
| `profile` | `logic/profile_logic.php` | Dashboard aggregate: user card (name, email, avatar URL, address string), stat counts (upcoming events, unread messages, notifications, active subscriptions) with per-card feature-gate flags (`messaging_active`, `products_active`, `subscriptions_active`), pending-survey count, recent events (3), notifications (5), messages (3), orders (3), subscription summary, mailing-list names |
| `orders_profile` | `logic/orders_profile_logic.php` | Paged order history (order #, total, date), total count, `offset` paging matching the web page |
| `events_profile` | `logic/events_profile_logic.php` | Paged registrations with `status` filter (all/active/completed/expired/canceled), per row: event name, session display type (list vs course — picks the detail screen), next-session time, status badge, expiry date |
| `subscriptions` | `logic/subscriptions_logic.php` | Active + cancelled subscription items (price, period, status, cancel date), recent orders; gated by `products_active` && `subscriptions_active` like the page |

Already `_logic_api()`-enabled and reused: `event_withdraw`, `change_tier`,
`orders_recurring_action` (mutations — their success/error result is the
payload), and the five account forms (`account_edit`, `password_edit`,
`address_edit`, `phone_numbers_edit`, `contact_preferences` — consumed
through their `_logic_form()` faces by the generic form renderer).

**`event_sessions` and `event_sessions_course` are API-declared but not
API-usable.** They return `LogicResult::render($page_vars)` full of model
objects (`Event`, `EventSession`, `Pager`), and model classes are not
`JsonSerializable` — `json_encode` reduces them to `{"key": N}` husks. Both
gain an API-context branch (see the dual-use pattern below) that returns a
real JSON payload: event header, sessions with titles/times/content, video
embed HTML, signed material URLs, and course-navigation state. Their web
render path stays byte-identical.

### The dual-use pattern (all six read actions)

`POST /api/v1/action/{name}` runs the same `{name}_logic($input)` the web
page runs, under session simulation (`SessionControl::set_api_user()`), and
the HTTP response body is the `LogicResult`'s `data` passed through
`json_encode` (`api_translate_logic_result()` in `api/apiv1.php`). The
`_logic_api()` function itself is only opt-in metadata:
`['requires_session' => true, 'description' => '...']`.

So each of the six read logics branches once, after the shared queries:

```php
if ($session->is_api_context()) {
    return LogicResult::render($payload);   // scalars and arrays ONLY
}
// ... existing page_vars / view path, unchanged
```

`SessionControl::is_api_context()` is true exactly during API session
simulation. Rules for the API branch:

- **Scalars and arrays only** in the payload — never a model object,
  `Pager`, or the session.
- **Never `LogicResult::redirect()`** — the API translator passes the
  redirect through as a JSON key and the client would silently "succeed".
  The subscriptions feature-gate redirect and the
  `event_sessions` ↔ `event_sessions_course` cross-redirect become
  `LogicResult::error(...)` in API context (for the cross-redirect, the
  error names the correct action; clients already know the display type
  from the `events_profile` payload and should not hit it).
- **Times go out raw** — UTC `Y-m-d H:i:s` strings straight from the DB,
  plus the event's timezone identifier where the web page shows event-local
  times. The client formats for the device timezone (this is what the
  calendar and mail modules already do). Do not `convert_time()` into API
  payloads.

### Files and media over the native transport

The app authenticates with an API key, not the web session cookie, so
session-gated file URLs don't work for it. Payloads that carry files mint
short-lived signed URLs (`File::mintSignedUrl()`,
`specs/implemented/file_signed_urls.md`), exactly as `MailboxService::
withSignedTransport()` does for mail:

- `event_sessions` / `event_sessions_course` API payloads: session
  **materials** (downloadable files) and any session **picture** become
  signed URLs. Minting happens only after the registrant-scope check that
  gates the page — minting is the authorization statement.
- Avatar URLs in the `profile` payload: public rendition links pass
  through unchanged; private ones are signed.
- Session **videos** are embeds (`Video->get_embed()` — external players);
  the payload carries the embed HTML for a sandboxed WebView, mirroring how
  mail renders HTML bodies (JavaScript scoped to the embed, link taps open
  externally).

### Scoping

Every new action resolves records through the session user — a key's user
sees only their own registrations, orders, and subscription items; another
user's ids return not-found, and an anonymous call gets 403. Same standard
the mailbox actions set; the functional tests below prove it at the
`_api()` layer (not one layer down).

## Menu store changes

In `admin_menus.json` (`user_dropdown` section):

- `core-profile` — add `"nativeScreen": "profile"`.
- `core-orders` — URL `/profile#orders` → `/profile/orders`; add
  `"nativeScreen": "orders"`.
- `core-subscriptions` — add `"nativeScreen": "subscriptions"`.
- `core-events` — URL `/profile#events` → `/profile/events`; add
  `"nativeScreen": "events"`.
- `core-event-sessions` — remove the entry.

**The JSON edit alone reaches fresh installs only.** Core menus sync with
`overwrite=false, prune=false` (`PluginManager::syncMenus()` — existing
rows are skipped to preserve admin customizations, and removed entries are
never pruned). On any deployment that already has these rows — including
dev — the changes must ship as a **data migration** in `/migrations/`
(executed by `update_database` from admin utilities; syntax per
`docs/deploy_and_upgrade.md`): `UPDATE amu_admin_menus` setting
`amu_defaultpage` / `amu_native_screen` for the four slugs, and `DELETE`
the `core-event-sessions` row. Ship both the JSON edit and the migration.

The flip is version-skew-safe by construction: builds (and platforms)
without the module fall back to the entry's URL in the authenticated
webview.

## Android client: `joinery-android-profile` module

One module, not four — the screens share models (orders appear on the
dashboard, the orders screen, and the subscriptions screen) and all live
under `/profile`. New Gradle module **`joinery-android-profile`**
(namespace `com.getjoinery.profile`), added to `settings.gradle`, shaped
exactly like `joinery-android-calendar`'s `build.gradle`
(`api project(":joinery-android")`, Compose BOM, minSdk 24, Java 17,
`includeAndroidResources` for fixtures). No manifest — nothing here hands
files to the OS directly; materials open via the system downloader/viewer
from their signed URLs.

Registration follows the entry-object pattern:

```kotlin
object JoineryProfile {
    fun registerScreens() {
        NativeScreenRegistry.register("profile") { ctx -> ProfileScreen(ctx.session.client, ctx.web, ctx.user) }
        NativeScreenRegistry.register("orders") { ctx -> OrdersScreen(ctx.session.client) }
        NativeScreenRegistry.register("subscriptions") { ctx -> SubscriptionsScreen(ctx.session.client, ctx.web) }
        NativeScreenRegistry.register("events") { ctx -> EventsScreen(ctx.session.client) }
    }
}
```

`joinery-member-android/MainActivity` adds `JoineryProfile.registerScreens()`
alongside the existing three modules.

### Screens

1. **Profile dashboard** (`ProfileScreen` + `ProfileStore`) — user card
   with avatar; the action banner (pending surveys, unread messages, new
   notifications); stat cards; recent events / notifications / messages /
   orders; subscription summary; mailing lists. Taps route natively when
   the target is in this module (orders, subscriptions, events list and
   event detail) and through `ctx.web` when it isn't (conversations,
   notifications, surveys). "Edit Account" opens the account settings list
   (screen 5).
2. **Orders** (`OrdersScreen` + `OrdersStore`) — paged list, order # /
   total / date, pull-to-refresh. Parity with the web page — no more, no
   less (there is no order-detail page on the web either; see out of
   scope).
3. **Subscriptions** (`SubscriptionsScreen` + `SubscriptionsStore`) —
   subscription items with status; cancel with confirm
   (`orders_recurring_action`); **Change plan** as a native tier-card
   surface driving the existing `change_tier` action
   (upgrade / downgrade / cancel / reactivate, honoring its PayPal-managed
   and scheduled-cancel warnings); **Manage payment method / Billing
   history** opens `/profile/billing` via the web bridge.
4. **Events** (`EventsScreen` + `EventsStore`, `EventDetailScreen`) —
   status filter tabs, paged registrations; tapping opens the event's
   detail in the variant the event declares: session-list style
   (`event_sessions`: next-session card, paged sessions with time in both
   event and user timezones, video embed, content, materials) or course
   style (`event_sessions_course`: numbered lessons, next/jump navigation,
   materials). Withdraw with the web page's exact confirmation language
   (no-refund warning) via `event_withdraw`. Add-to-calendar links open
   externally. Videos render in a sandboxed WebView; materials open from
   signed URLs into the system viewer.
5. **Account settings** (`AccountSettingsScreen`) — a native list linking
   the five `FormScreen`-rendered forms (account, password, address,
   phone, contact preferences) plus bridged entries for Security and
   photo management. Email change keeps its activation-email semantics —
   the form submits `usr_email_new` and the screen surfaces the
   "check your email" result.

State handling copies the established store conventions (`Phase`
sealed class, `loadGeneration` stale-load guard, resume refresh,
keep-last-good-list on reload, optimistic row patch with reload on
failure) — `MailboxStore` is the reference.

Stable `testTag`s use the `profile_*` prefix (one prefix for the module,
listed in the doc deliverable).

## Implementation notes for the executor

Constraints and gotchas that are easy to get wrong. Read before coding;
when in doubt, copy the referenced file, not your instinct.

### Server side (PHP)

- Model constructors require a parameter: `new Event($id, TRUE)` to load,
  `new Event(NULL)` for new. `new Event()` is a fatal error.
- Multi-class filter keys are NOT column names — read the target class's
  `getMultiResults()` for its option keys before filtering
  (`MultiOrder`, `MultiOrderItem`, `MultiEventRegistrant` are the ones
  this spec touches).
- Row ids are `$obj->key` (public property). There is no `get_key()`.
- The existing page logics are the single source of truth — extend them
  in place with the API branch. Do not create parallel `*_api_logic.php`
  files for the four read surfaces, and do not change what the web view
  path returns.
- Signed URLs: `File::mintSignedUrl()` (see
  `plugins/mailbox/includes/MailboxService.php::withSignedTransport()`
  for the worked pattern). Mint only after the registrant/owner check
  has passed.
- No new `/ajax/` endpoints anywhere in this work.
- After every PHP edit: `php -l <file>` and
  `php maintenance_scripts/dev_tools/validate_php_file.php <file>`, and
  bump the file's `@version`.
- Reference for the API-layer functional tests:
  `plugins/mailbox/tests/profile_mailbox_test.php` (how to build users,
  keys, and call the action layer).

### Android side (Kotlin)

- Gradle files are **Groovy DSL** (`build.gradle`), not `.kts`. Copy
  `joinery-android-calendar/build.gradle` verbatim and change the
  namespace — versions are pinned and inherited (Kotlin 1.9.24, Compose
  compiler 1.5.14, BOM 2024.06.00, compileSdk 35, minSdk 24). The core
  dependency is `api project(":joinery-android")` — `api`, not
  `implementation`.
- JSON parsing uses the core **`JsonValue`** parser only — not
  kotlinx.serialization, not `org.json`. Every model gets a
  `companion object { fun from(json: JsonValue?): Model? }`; module API
  wrappers read `envelope["data"]` and throw `JoineryApiError.Malformed`
  on null. `JsonValue` already absorbs the PHP quirk where an empty
  `data` serializes as `[]`.
- Call actions through `client.submitAction("profile", body)` etc. —
  core action names are flat (no `plugin/` prefix). Auth headers,
  `Idempotency-Key`, and the 426/401/422 handling are all central in
  `ApiClient`; a module never re-implements them. Surface errors via
  `(e as? JoineryApiError)?.displayMessage ?: e.message`.
- Store classes are plain `remember {}`-constructed classes (no
  ViewModel), `mutableStateOf` with `private set` — copy
  `MailboxStore.kt` for the `Phase` sealed class, `loadGeneration`
  stale-load guard, ON_RESUME refresh (skipping the first resume), and
  keep-last-good-on-reload behavior.
- Before building `AccountSettingsScreen`, verify each of the five forms'
  field types against `FormModels.kt`'s `FormFieldType` set by fetching
  `GET /api/v1/form/{action}` from dev. If a form uses an unsupported
  type, extend `FormScreen` in the core library (schema-version-safely) —
  do not hand-roll a bespoke form. The photo grid is not part of the
  `account_edit` form definition; photos stay on the bridged web page.
- Video embeds differ from mail HTML bodies: mail renders untrusted HTML
  with JavaScript off, but session videos are third-party player iframes
  that need JavaScript to play. Enable JS only inside the embed's own
  WebView, block navigation (taps open externally), and never load an
  API-credentialed URL in it.
- Register screens in `joinery-member-android/MainActivity.onCreate`
  **before** `setContent`, alongside the existing three modules.

### Build & test loop

- The Android source of truth is this repo's `android/` directory on the
  dev box. The Mac mini's `~/dev/joinery-android` is a disposable rsync'd
  build area — never edit files there. Build over `ssh macmini` after
  `source ~/.android-env`; the emulator AVD is `joinery_test`.
- Do not run the emulator while the mini's local LLM (Ollama) is serving
  a generation — they starve each other. Stop the emulator for long
  local-model work, or point test chats at a hosted model.
- Fixtures are **verbatim captured envelopes** from `dev.getjoinery.com`
  — call each new action with a real app session key (mint one via
  `POST /api/v1/auth/login`) and save the raw response body unedited to
  `src/test/resources/fixtures/`. Seed the dev account with at least one
  order, an active subscription, and a registration for each event
  display type (list and course) first, so fixtures exercise real shapes.

### Process

- Database READs on dev are fine without asking; any manual WRITE
  (including seeding test data with SQL) needs explicit user confirmation
  first. Prefer seeding through the admin UI or existing test helpers.
- Never commit to git; the user commits.
- New files: `chmod 666` (dirs `777`).
- The doc deliverables describe the end state only — no "now", "instead
  of", or migration narration.
- On completion, move this spec to `specs/implemented/`, and update
  `specs/mobile_native_email.md`'s status if its absorbed gate items are
  delivered by the same run.

### Suggested build order

1. Server: the four new `_logic_api()` opt-ins + API-context payload
   branches, then the same branch for `event_sessions` /
   `event_sessions_course` (with signed URLs). PHP functional tests as
   each lands.
2. Menu migration + `admin_menus.json` edit; run `update_database` on dev
   and confirm `GET /api/v1/app/navigation` shows the native destinations
   and corrected fallbacks.
3. Capture fixtures from dev.
4. The `joinery-android-profile` module: models + parsing tests first
   (against fixtures), then stores/screens, then MainActivity wiring.
5. Emulator gate (including the absorbed mail-module gate items), then
   docs.

## Tests

**Server (PHP functional, `tests/functional/`)** — at the `_api()` action
layer:

- Each new action: owner sees their data; a second user's records are
  invisible (not-found / absent, never leaked); anonymous gets 403.
- Feature gates: `subscriptions` denies when `products_active` /
  `subscriptions_active` are off; dashboard cards carry the gate flags.
- Signed URLs from `event_sessions` payloads expire and are denied for a
  non-registrant viewer.
- `events_profile` status filters and paging match the web page's
  bucketing.

**Client (Kotlin JVM unit tests)** — `*ParsingTest.kt` over fixtures in
`src/test/resources/fixtures/` captured verbatim from
`dev.getjoinery.com` envelopes (`profile.json`, `orders_profile.json`,
`events_profile.json`, `subscriptions.json`, `event_sessions.json`,
`event_sessions_course.json`) — the same capture convention mail and
calendar use, so a future iOS module consumes the identical files.

## Delivery gate

Compose UI suite on the emulator against `dev.getjoinery.com` (Mac mini,
per the platform spec's gate mechanics), asserting per screen that no
webview is present where a native screen is expected:

- Dashboard renders the seeded user's stats and recents; a stat-card tap
  lands on the matching native screen; a conversations tap lands on the
  bridged web page.
- Orders and events lists page and filter correctly against seeded data;
  an event detail shows sessions with materials, and a material opens.
- A subscription cancel reflects on the web subscriptions page (and vice
  versa: a web-side cancel shows in the app after refresh).
- Each `FormScreen` form round-trips: edit → save → value visible on the
  web page.
- A build without the module lands every flipped entry on its (corrected)
  web fallback.

This suite is the same delivery mechanism the mail module's outstanding
UI-test gate (`specs/mobile_native_email.md`) rides on — implement them as
one instrumented run; that spec's gate items are absorbed here rather than
delivered twice.

## Out of scope (future specs)

- iOS counterparts — the API actions from this spec are platform-neutral
  and serve a future `JoineryProfileKit` unchanged; no client code is
  shared by prior decision.
- Order detail / receipts — doesn't exist on the web either; a
  parity-plus feature, not a conversion.
- Native billing, passkeys, Sealed Vault, 2FA UI — web bridge by design
  (browser-session requirement / Stripe portal redirect).
- Native notifications and conversations screens.
- DNS filtering screens — `specs/scrolldaddy_android_app.md`.
- Push notifications.

## Versioning

- Bump `@version` on every modified PHP file; new logic API companions
  bump their file's version.
- New Kotlin module follows the platform convention (no per-module
  version; the app's `clientVersion` governs).

## Documentation deliverables (on implementation)

- `docs/mobile_apps.md` — a `joinery-android-profile` module section in
  the established shape: identity + registration line, API-surface
  sentence, screen bullets with Compose entry points, the `profile_*`
  testTag list, and the "not in the module" line (security, billing,
  photos, conversations — web bridge).
- `docs/api.md` — document the four new actions with their payloads and
  scoping.
