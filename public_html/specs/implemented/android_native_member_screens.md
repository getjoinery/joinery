# Native Member Screens — Android

**Status:** Active — not yet implemented.

**Depends on (implemented):** `specs/implemented/android_app_platform.md`
(the shell, `NativeScreenRegistry`, `ApiClient`, `FormScreen`, the
web-session bridge) and `specs/implemented/mobile_native_member_screens.md`
(the iOS conversion this spec ports — **including its entire server
surface, which is platform-neutral and already live**).

Converts the remaining member surfaces in the Android app to native
Compose, reaching parity with iOS. **There is no server work in this spec**
beyond one small menu-row cleanup: the nine member API actions, migration
v139's route flips, the corrected menu URLs, and the gate seed scripts all
shipped with the iOS conversion and are client-agnostic. The `nativeScreen`
names the navigation endpoint already emits (`profile`, `orders`,
`subscriptions`, `events`) are platform-neutral — the moment the Android
module registers those names, the flipped entries light up; until then (and
on old builds) every entry falls back to its web URL.

## Disposition of every surface (decided once, up front)

| Surface | iOS today | Android after this spec |
|---|---|---|
| Profile dashboard (`core-profile`) | native `profile` | **native** — same screen name |
| Orders (`core-orders`) | native `orders` | **native** |
| Subscriptions (`core-subscriptions`) | native `subscriptions` | **native** (read + cancel) |
| My Events (`core-events`) | native `events` | **native** |
| Conversations (from dashboard) | native (list + thread) | **native** |
| Security (from Settings) | native (app sessions + TOTP) | **native** |
| Address / phone / account forms | native `FormScreen` rows in Settings | **native** — same `FormScreen` approach |
| Change plan + billing | web bridge | **web bridge** — Google Play's IAP rules for digital subscriptions mirror Apple's; purchase UI belongs to `specs/mobile_app_billing.md` |
| Event session content | web bridge from the events list | **web bridge** — rich embeds/HTML/materials render in the bridged page; promotable later |
| Notifications | web bridge from the dashboard | **web bridge** |
| Passkeys + Sealed Vault management | web-only (stated on the security screen) | same — pending the credentials spec |
| `core-event-sessions` menu entry | present, web | **removed** (migration below) — it errors without an `event_id`; it is a deep link, not a destination |
| DNS filtering screens | web | stays web — `specs/scrolldaddy_android_app.md` owns their native surface |
| Admin entries (permission 5+) | web | stays web |

## Server surface (already live — consume, don't build)

All sessioned `_logic_api()` actions, called as
`client.submitAction("{name}", body)` (flat core names, no plugin prefix):

| Action | Feeds | Notes |
|---|---|---|
| `profile_dashboard` | dashboard | Sections gated by deployment settings arrive as **absent keys**; render strictly from present keys — no client-side settings knowledge |
| `order_list` | orders | `offset` paging, 10/page |
| `subscription_summary` | subscriptions | includes `payment_source` marker driving which manage affordances show |
| `my_events` | events | `status` + `offset`; each row carries the session page's web URL for the bridge link |
| `conversation_list` | inbox | 20/page |
| `conversation_thread` | thread | `conversation_id` or `to` (compose-mode dedup); `before`/`after` cursors, 50/page; marks read server-side |
| `conversation_send` / `conversation_action` | thread mutations | `action` ∈ mute / unmute / delete |
| `security_overview` | security | TOTP status incl. the `otpauth://` URI on enable, app-session list with `is_current`, passkey count, vault flag |
| `orders_recurring_action`, `event_withdraw` | cancel / withdraw confirms | pre-existing mutations |

Payload times are raw UTC `Y-m-d H:i:s` strings; the client formats for the
device timezone (same as mail/calendar). Functional tests for all of the
above exist (`tests/functional/api/member_screens_test.php`); nothing to
add server-side.

### The one server change: drop the dead menu entry (migration v140)

Remove `core-event-sessions` from `admin_menus.json` and ship a migration
deleting the `amu_admin_menus` row (core menu seeding is insert-only and
never prunes, so the JSON edit alone reaches fresh installs only — v136/v139
precedent). The page requires an `event_id`; as a menu destination it renders
an error on every platform. The events flow (web and native) links into it
with an id, which keeps working.

## Android client: `joinery-android-member` module

One new Gradle module (namespace `com.getjoinery.member`), shaped exactly
like `joinery-android-calendar`'s `build.gradle` (Groovy DSL,
`api project(":joinery-android")`, Compose BOM, minSdk 24,
`includeAndroidResources` for fixtures). No manifest additions.

```kotlin
object JoineryMember {
    fun registerScreens() {
        NativeScreenRegistry.register("profile") { ctx -> ProfileScreen(ctx.session.client, ctx.web, ctx.user) }
        NativeScreenRegistry.register("orders") { ctx -> OrdersScreen(ctx.session.client) }
        NativeScreenRegistry.register("subscriptions") { ctx -> SubscriptionsScreen(ctx.session.client, ctx.web) }
        NativeScreenRegistry.register("events") { ctx -> EventsScreen(ctx.session.client, ctx.web) }
        NativeScreenRegistry.register("conversations") { ctx -> ConversationsScreen(ctx.session.client) }
        NativeScreenRegistry.register("security") { ctx -> SecurityScreen(ctx.session.client) }
    }
}
```

`joinery-member-android/MainActivity` adds `JoineryMember.registerScreens()`
alongside the existing three modules, before `setContent`.

### Screens (behavioral reference: the iOS JoineryMemberKit sources)

For every screen below, the shipped iOS implementation in
`ios/joinery-kit/Sources/JoineryMemberKit/` is the behavioral contract —
same payloads, same affordances, same deliberately-web boundaries. Match
it; don't re-derive.

1. **Profile dashboard** — user card, alert row (pending surveys / unread),
   stat tiles, recent lists, mailing lists. Tiles route natively to the
   module's own screens; notifications and anything unconverted open via
   `ctx.web`. Sections render only when present in the payload.
2. **Orders** — paged list, order # / total / date.
3. **Subscriptions** — items with status; cancel with confirm →
   `orders_recurring_action`; Change Plan and Manage Billing rows open the
   web pages via `ctx.web` (billing row only when `payment_source` is
   stripe, matching iOS).
4. **Events** — status filter tabs, paged registrations; rows open the
   event's session page via `ctx.web`; withdraw with the no-refund confirm
   → `event_withdraw`.
5. **Conversations** — inbox list → thread (bubbles, cursor paging,
   mark-read on open, compose bar, mute/unmute/delete menu). No
   new-conversation entry point — the compose/member-picker is parked on a
   product decision (item 2 of
   `specs/implemented/mobile_native_member_screens_followups.md`) on both
   platforms.
6. **Security** — app-session list with per-row revoke and Sign Out All
   Devices (revoking the current key signs the app out via the core 401
   path); TOTP enable/confirm/disable/regenerate — render the QR natively
   from the payload's `otpauth://` URI (ZXing or equivalent tiny encoder;
   the server does not send an image); passkeys/vault row states they are
   managed on the website.
7. **Settings additions** (core library, not this module) — `FormScreen`
   rows for `address_edit` and `phone_numbers_edit`, plus a row into the
   `security` screen — mirroring the iOS Settings screen.

State handling copies the established store conventions (`Phase` sealed
class, `loadGeneration` stale-load guard, ON_RESUME refresh, keep-last-good
on reload) — `MailboxStore.kt` is the reference. Stable `testTag`s use a
`member_*` prefix.

## Implementation notes for the executor

### Kotlin side

- Gradle files are **Groovy DSL** — copy `joinery-android-calendar/build.gradle`
  and change the namespace; versions are pinned and inherited. The core
  dependency is `api project(":joinery-android")` — `api`, not
  `implementation`.
- JSON parsing uses the core **`JsonValue`** parser only. Every model gets
  `companion object { fun from(json: JsonValue?): Model? }`; wrappers read
  `envelope["data"]` and throw `JoineryApiError.Malformed` on null.
  `JsonValue` absorbs the PHP quirk where empty `data` serializes as `[]`.
- Auth headers, `Idempotency-Key`, and 426/401/422 handling live in
  `ApiClient`; a module never re-implements them.
- **Fixtures are the iOS files.** Copy
  `ios/joinery-kit/Tests/JoineryMemberKitTests/Fixtures/*.json`
  (profile_dashboard, order_list, subscription_summary, my_events,
  conversation_list, conversation_thread, security_overview) verbatim into
  `src/test/resources/fixtures/` — the same shared-fixture convention the
  mail/calendar/chat modules already use. Re-capture from dev only if a
  payload has changed since.
- Before building the Settings form rows, verify `address_edit` and
  `phone_numbers_edit` field types against `FormModels.kt`'s
  `FormFieldType` set via `GET /api/v1/form/{action}` on dev; extend
  `FormScreen` schema-safely if needed — never hand-roll a form.

### Build & test loop

- Source of truth is this repo's `android/` directory; the Mac mini's
  `~/dev/joinery-android` is a disposable rsync'd build area — never edit
  there. Build over `ssh macmini` after `source ~/.android-env`; emulator
  AVD is `joinery_test`.
- Do not run the emulator while the mini's Ollama is serving a generation —
  they starve each other.
- Never commit to git; the user commits. New files `chmod 666`.

### Delivery gate (the still-open Android instrumented gate)

This delivers the Android UI-test gate that has been open since the mail
conversion — an instrumented Compose suite on the emulator against
`dev.getjoinery.com`, mirroring `tests/functional/ios/phase3_gate.sh`
(v1.3.1) leg for leg. Build it as a runner script
(`tests/functional/android/member_gate.sh`) that orchestrates server state
exactly as the iOS gate does, **reusing its seed scripts unchanged** —
`phase3_fixtures.php` (mailbox grant + sender alias + base label) and
`phase3_conversation_fixtures.php` (peer user + seeded 1:1 thread) are
platform-neutral.

Legs, with server-side verification where the iOS gate has it:

- Native member screens render with **no webview present** (dashboard,
  orders, subscriptions, events, conversations, security).
- Conversation round-trip: open seeded thread, send timestamped reply,
  verify the row in `msg_messages`.
- Mailbox read + reply (the absorbed mail-gate item) + the folder picker
  filing a dedicated seeded thread, verified in
  `ilm_inbound_label_members`. The picker leg needs its **own** seeded
  message — the reply leg retitles its thread to Re: and breaks exact
  subject lookup (learned on iOS).
- Deliberately-web surfaces load through the bridge from their native
  entry points (change-tier from subscriptions, notifications from the
  dashboard).
- Revoke-all from the native security screen signs the app out.
- A build without the module lands every flipped entry on its web
  fallback.

Gate gotchas carried over from the iOS runs: assert on `testTag`s and row
text, never on styled header text; the revoke-all control sits below the
gate's accumulated session rows — scroll to it; lazy lists don't expose
off-screen nodes.

## Out of scope (future specs)

- Conversation compose / member picker — parked product decision (both
  platforms).
- Native billing / change-plan — `specs/mobile_app_billing.md`.
- Native passkeys / Sealed Vault — the credentials spec.
- Native notifications and event-session content — promotable later; web
  bridge for now.
- DNS filtering screens — `specs/scrolldaddy_android_app.md`.
- Push notifications.

## Documentation deliverables (on implementation)

- `docs/mobile_apps.md` — a `joinery-android-member` module section in the
  established shape (identity + registration, screens, `member_*` testTag
  list, the web-bridge boundaries), alongside the JoineryMemberKit section.
- Move this spec to `specs/implemented/` on completion.
