# Mobile Native Member Screens — iOS

**Created:** 2026-07-07

**Status:** Active — not yet implemented.

**Purpose:** Convert the remaining member-facing web screens in the iOS app
to native, following the pattern proven by mail, calendar, and AI chat:
server actions as `_logic_api()` opt-ins sharing the web code path, a
declarative route flip (`nativeScreen` + web `fallback_url`), a layered
Swift kit module registering through `NativeScreenRegistry`, unit tests
over live-captured fixture envelopes, and UI-test gates with a no-webview
assertion. This spec also absorbs the two open iOS items left over from
`specs/mobile_native_email.md` (the label/move picker and the mail API
test hardening).

**Depends on (implemented):** `specs/implemented/ios_app_platform.md`
(JoineryKit, navigation routing table, native-screen promotion),
`specs/implemented/mobile_native_email_server_api_and_ios.md` (the
per-surface conversion pattern).

**Pre-launch note:** The platform has no production users. No
data-preservation migrations are required; menu entries and API payload
shapes may change freely.

---

## Decision inventory (every remaining surface, decided once)

Already native and out of scope: calendar, mailbox, AI chat, login/reset,
Settings (which already renders `account_edit`, `contact_preferences`, and
`password_edit` natively through the generic form renderer).

| Surface | Web view | Treatment |
|---|---|---|
| Member dashboard | `views/profile/profile.php` | **Native** — `profile` screen, new `profile_dashboard` read action |
| Order history | `views/profile/orders.php` | **Native** — `orders` screen, new `order_list` read action |
| Recurring-order cancel | `orders_recurring_action.php` | **Native** — confirm alert + existing `orders_recurring_action` action; no server work |
| Subscriptions | `views/profile/subscriptions.php` | **Native (read + cancel only)** — `subscriptions` screen, new `subscription_summary` read action |
| Change tier | `views/profile/change-tier.php` | **Stays web** — see "Deliberately web" below (IAP policy) |
| Billing | `views/profile/billing.php` | **Stays web** — Stripe Billing Portal is a hosted web flow |
| My Events | `views/profile/events.php` | **Native** — `events` screen, new `my_events` read action |
| Event withdraw | `event_withdraw.php` | **Native** — confirm alert + existing `event_withdraw` action; no server work |
| Event session content | `event_sessions.php`, `event_sessions_course.php` | **Stays web** — rich-media content pages, opened from the native events list via `context.web` |
| Conversations inbox | `views/profile/conversations.php` | **Native** — `conversations` screen, new `conversation_list` read action |
| Conversation thread | `views/profile/conversation.php` | **Native** — thread view inside the `conversations` screen; mutations migrate from legacy `/ajax/` to API actions |
| Security: app sessions + TOTP status | `views/profile/security.php` | **Native** — `security` screen, new `security_overview` read action + existing `security` action mutations |
| Security: passkey + vault management | `views/profile/security.php` | **Deferred to its own spec** — needs native WebAuthn (see below) |
| Address / phone forms | `address_edit.php`, `phone_numbers_edit.php` | **Native** — two new `FormScreen` rows in the existing Settings screen; no server work (actions + forms already exposed) |
| Notifications | `views/notifications.php` (linked from dashboard) | **Stays web** — opened from the native dashboard via `context.web`; flip later if wanted |
| Bookings (`my_bookings`, `availability`) | `plugins/bookings/views/profile/` | **Out of scope** — not in the app navigation today (no `profileMenu` entries); native treatment belongs to the `specs/native_booking_flow.md` family |
| Mail label/move picker | (JoineryMailKit gap) | **Native** — screen 5 from the mail spec, built here |

### Deliberately web — the rationale, recorded once

- **Change tier + billing.** Selling digital subscriptions inside an iOS
  app must go through Apple IAP (`specs/mobile_app_billing.md` owns that).
  Until that spec lands, tier purchase/upgrade UI must not be re-built
  natively around Stripe. The native subscriptions screen is read-only
  plus cancel (cancellation of an existing subscription is fine); its
  "manage billing" affordances open the web pages through `context.web`,
  and the Stripe Billing Portal redirect is inherently a hosted web flow
  regardless.
- **Event session content.** These pages are rich CMS-style content —
  video embeds (HTML iframes), arbitrary HTML blocks, private file
  downloads. A native rendering would embed a web view for the video
  anyway. The native events list links each registration to its session
  page through `context.web`; the content pages already render cleanly in
  app display mode.
- **Passkey + Sealed Vault management.** WKWebView does not expose
  WebAuthn platform authenticators to ordinary apps, so these panels
  cannot work in the in-app webview either — the only in-app path is
  native `ASAuthorizationPlatformPublicKeyCredential` (with PRF for the
  vault, iOS 18+). That work also requires a policy decision:
  `passkey_register_options`, `passkey_rename`, and `passkey_revoke`
  deliberately require the browser-session credential today
  (`docs/api.md` § Two authorization axes). Both belong in a dedicated
  `mobile_native_security_credentials` spec. Until then the native
  security screen states that passkeys and the vault are managed on the
  website. Pre-launch, this is acceptable.

---

## Server work

### New read actions (purpose-built `data` payloads)

The existing page logic returns `page_vars` holding live PHP objects,
which serialize as `{"key": N}` husks over the API — not a contract
(`docs/api.md` § What is not contract). Each new action therefore returns
a purpose-built `data` payload, sharing the web page's query path (same
models, same filters) the way `calendar_feed` shares the calendar's.

All are core logic files with `_logic_api()` (`requires_session`,
capability `read`), exposed as `POST /api/v1/action/{name}`:

| Action | Logic file | Payload |
|---|---|---|
| `profile_dashboard` | `logic/profile_dashboard_logic.php` | User card (name, email, avatar URL, address string), counts (unread conversations, unread notifications, upcoming events, active subscriptions), recent lists (upcoming events ×3, conversations ×3, orders ×3, subscriptions ×5), pending event surveys, mailing-list names. Sections gated by the same `messaging_active` / `products_active` / `subscriptions_active` settings the web page uses — omitted sections are omitted keys, so the client renders only what the deployment has. |
| `order_list` | `logic/order_list_logic.php` | Paginated orders (`offset` input, 10/page to match web): order id, number, total, currency, date, item summaries. |
| `subscription_summary` | `logic/subscription_summary_logic.php` | Active and cancelled subscriptions (order-item id, tier/product name, price, period, status, renewal/end date, `can_cancel`), current tier from the user record, and a `payment_source` marker (`stripe` / `paypal` / none) so the client knows which management affordances to show. |
| `my_events` | `logic/my_events_logic.php` | Status-filtered (`status`, `offset` inputs) registration list matching the web tabs: registrant id, event name, session display type, next-session time, status/expiry badge, and the web URL of the session content page (for the `context.web` link). |
| `conversation_list` | `logic/conversation_list_logic.php` | Paginated inbox: conversation id, other-participant display name, preview, last-message time, unread flag, muted flag. |
| `conversation_thread` | `logic/conversation_thread_logic.php` | One conversation's messages (`conversation_id`, `before`/`after` cursors, 50/page): message id, sender id, body, time, `is_mine`. Marks the conversation read (`cnp_last_read_time`) exactly as the web view does. Also serves compose-mode dedup: given `to` instead of `conversation_id`, returns the existing 1:1 conversation if there is one. |
| `security_overview` | `logic/security_overview_logic.php` | TOTP status (enabled flag, backup-codes-remaining count), app-session list (key id, device label, created, last-used, `is_current`), passkey count, vault status flag. The app-session list is the piece with no clean read today (`ApiKey` has no CRUD exposure; keep it that way — the payload is the read surface). |

### New mutation actions — conversations off legacy `/ajax/`

`views/profile/conversation.php` currently mutates through
`/ajax/conversations_ajax.php`. Per the API endpoint rules, touching it
means migrating it. Two new plugin-free core actions:

| Action | Logic file | Behavior |
|---|---|---|
| `conversation_send` | `logic/conversation_send_logic.php` | Send a message (`conversation_id` or `to` for a new 1:1), returns the created message as data (no server-rendered HTML fragment). |
| `conversation_action` | `logic/conversation_action_logic.php` | `action` ∈ `mute` / `unmute` / `delete` on a conversation the caller participates in. |

The web conversation page switches to calling these via `/api/v1` with the
browser-session credential, and `/ajax/conversations_ajax.php` is deleted.
Participant authorization matches the current ajax checks (sender or
recipient; `ConversationParticipant` ownership).

### Existing actions used as-is (no server work)

`orders_recurring_action`, `event_withdraw`, `event_register`,
`event_waiting_list`, `security` (TOTP ops + `revoke_app_session` /
`revoke_all_app_sessions`), `address_edit` + form, `phone_numbers_edit` +
form, and the mailbox `thread_action` (`set_membership` /
`create_folder`) that backs the label/move picker.

### Mail API test hardening (carried from `specs/mobile_native_email.md`)

- Functional tests that exercise the mailbox `_api()` action layer
  directly (not just `MailboxService`): a granted key's `thread` /
  `thread_action` / `send` succeed only within its aliases; a grantless
  key gets empty/denied; signed URLs from the `thread` action expire and
  are denied to a viewer outside the granting alias.
- A send round-trip test: a mobile-originated `send` (with attachment)
  stores the outbound row and manifest, and the web reader renders the
  same sent copy with the same attachment.

---

## Route flips (`admin_menus.json`)

| Entry | Change |
|---|---|
| `core-profile` | add `"nativeScreen": "profile"` (fallback `/profile`) |
| `core-orders` | fix URL `/profile#orders` → `/profile/orders` (the anchor target no longer exists); add `"nativeScreen": "orders"` |
| `core-subscriptions` | add `"nativeScreen": "subscriptions"` |
| `core-events` | fix URL `/profile#events` → `/profile/events`; add `"nativeScreen": "events"` |
| `core-event-sessions` | unchanged (web) — natively reached from the events screen |

Conversations and Security are not menu entries; they are reached from the
native dashboard and the native Settings screen respectively, so no menu
change. Old app builds keep working through each entry's `fallback_url` —
that is the whole point of the destination mechanism.

---

## iOS work

### JoineryMemberKit — one new layered module

One SPM product (`Sources/JoineryMemberKit`, depends on `JoineryKit`),
following the uniform module anatomy (Models → typed API wrapper over
`APIClient` → ObservableObject stores → screens/sheets), registering via
`JoineryMember.registerScreens()`:

| Registry name | Screen |
|---|---|
| `profile` | Dashboard: user card, alert row (pending surveys / unread), stat tiles, recent-item lists. Tiles and rows navigate to the other native screens; notifications and anything unconverted open through `context.web`. Sections render only when present in the payload (deployment settings gating). |
| `orders` | Paginated order list. |
| `subscriptions` | Subscription list with status; Cancel uses a confirmation alert → `orders_recurring_action`; "Change plan" and "Billing" rows open the web pages via `context.web`. |
| `events` | Status-tabbed registration list; rows open session content via `context.web`; Withdraw uses a confirmation alert → `event_withdraw`. |
| `conversations` | Inbox list → thread view (bubbles, cursor pagination, mark-read on open, compose bar, mute/unmute/delete menu, new-conversation dedup). Closest existing reference: JoineryAIChatKit's list/thread split. |
| `security` | App-session list with per-row and revoke-all actions (`security` action), TOTP status with enable/confirm/disable/regenerate flows, and a passkeys/vault row that states these are managed on the website. The TOTP enable payload from `security_overview`/the `security` action must include the `otpauth://` URI string (the server already has it — it is what the web QR encodes); iOS renders the QR natively with `CIFilter.qrCodeGenerator`, so no SVG handling is needed. Revoking the *current* session key signs the app out — reuse the kit's existing 401 sign-out path. |

Settings screen additions (JoineryKit core, no new module): two
`FormScreen` rows for `address_edit` and `phone_numbers_edit`, matching
the existing `account_edit` row; plus a row navigating to the `security`
screen.

### JoineryMailKit — label/move picker (screen 5 from the mail spec)

A Move/Labels control on the open thread matching the web reader's
exclusive-vs-non-exclusive folder model
(`plugins/mailbox/assets/mailbox_reader.js` `buildFolderControl()` is the
reference behavior): single-pick "Move" for an exclusive feed, checkbox
"Labels" for a non-exclusive one, plus create-folder. Wired to the
existing `thread_action` `set_membership` / `create_folder` — no server
change. The Android picker already ships; match its behavior.

---

## Implementation notes (read before building)

### Build by imitation — the reference-file map

Every piece of this work has a completed sibling in the repo. Do not
invent structure; copy the named reference and adapt:

| Building | Imitate |
|---|---|
| Any new read action | `logic/calendar_feed_logic.php` (a core `_logic_api()` read action with a purpose-built `data` payload) |
| Any new mutation action | `logic/calendar_entry_save_logic.php` and `logic/event_withdraw_logic.php` (descriptor + confirm semantics) |
| Payload data assembly | The corresponding page logic file named in the server-work table — reuse its exact model calls and filters; the new action is a re-presentation of the same query path, not a new query |
| Kit module layout | `ios/joinery-kit/Sources/JoineryCalendarKit/` (smallest complete module: Models → API → Store → Screen) |
| Conversations list→thread split | `ios/joinery-kit/Sources/JoineryAIChatKit/` (ChatListStore / ChatThreadStore / ChatThreadView) |
| Label/move picker behavior | `plugins/mailbox/assets/mailbox_reader.js` `buildFolderControl()` (web) and the Android picker in `android/joinery-android-mail` (already shipped — match it) |
| Unit tests + fixtures | `Tests/JoineryCalendarKitTests/CalendarParsingTests.swift` + its `Fixtures/` (verbatim live-captured envelopes, loaded via `Bundle.module`) |
| UI-test gates | `ios/joinery-member-ios/UITests/MailboxUITests.swift` (including the no-webview assertion) and the screenshot suites |
| Settings form rows | The existing `account_edit` row in `Sources/JoineryKit/SettingsView.swift` |

### Sequencing — four increments, each verified before the next

1. **Server read + mutation actions**, with functional tests. Verified by
   tests alone; no iOS work yet. Includes deleting
   `/ajax/conversations_ajax.php` and switching the web conversation page
   to the new actions — the web page is the first consumer and proves the
   actions before any Swift exists.
2. **Fixture capture**: call each new action against dev with a real
   session key and save the verbatim response envelopes into
   `Tests/JoineryMemberKitTests/Fixtures/`. Fixtures come from the live
   API, never hand-written.
3. **JoineryMemberKit + Settings additions + mail picker**, with unit
   tests over the fixtures. Add the new product and test target to
   `Package.swift` (bump its version comment), call
   `JoineryMember.registerScreens()` at app launch alongside the existing
   `registerScreens()` calls, and re-run `xcodegen generate` after adding
   app-target files.
4. **Route flips + UI-test gates.** Flip `admin_menus.json` last — until
   then every screen is reachable in development via the registry but
   users' menus still resolve to web, so a half-built module never ships
   as a user-facing route.

### Constraints that are not obvious from the pattern

- **Conversations authorization must not loosen.** Before deleting
  `/ajax/conversations_ajax.php`, read its checks and reproduce them
  exactly: only a conversation participant may read, send, mute, or
  delete; message read scope is sender-or-recipient. The
  `ConversationParticipant` row (`cnp_usr_user_id`) is the authorization
  anchor. Write the cross-user denial tests first.
- **`Conversation` CRUD exposure stays as it is** (effectively staff-only
  — it has no owner column). Member access goes through the new actions
  only. Likewise **do not add CRUD exposure to `ApiKey`**; the
  `security_overview` payload is the only read surface for app sessions.
- **Dashboard gating:** every section of the `profile_dashboard` payload
  is keyed off the same settings checks as `logic/profile_logic.php`
  (`messaging_active`, `products_active`, `subscriptions_active`). A
  disabled section is an *absent key*, and the iOS dashboard renders
  strictly from present keys — no client-side settings knowledge.
- **Pagination sizes match the web pages** (orders 10, conversations 20,
  messages 50, events 10) so fixtures and web behavior stay comparable.
- **Times:** payloads carry UTC ISO strings as stored; iOS formats for
  display. No server-side timezone formatting in API payloads.
- **iOS models parse via `JSONValue`**, not `Codable` — option order is
  meaningful and Foundation decoders lose it. Follow the existing model
  files' parsing style.
- **Edit only the repo `ios/` tree.** The Mac mini's
  `~/dev/joinery-ios` is a disposable rsync target clobbered on every
  gate run. Unit tests run on the mini with the `JoineryKit-Package`
  scheme (`platform=iOS Simulator` destination — plain `swift build`
  targets macOS and fails).
- **PHP hygiene per increment:** `php -l` and
  `maintenance_scripts/dev_tools/validate_php_file.php` on every touched
  PHP file; new files get `chmod 666`.

---

## Test gates

Following the established pattern per surface:

- **Server functional tests** for every new action: payload shape,
  pagination, owner scoping (another user's orders/conversations/sessions
  are invisible), settings gating on the dashboard payload, the
  conversation participant checks, and the mail hardening items above.
- **iOS unit tests** — `Tests/JoineryMemberKitTests` with `Fixtures/` of
  verbatim API envelopes captured live from dev (same discipline as the
  other three modules); parsing tests per payload plus pure logic (status
  filtering, cursor math).
- **UI-test gate additions** in `joinery-member-ios/UITests/`: per-screen
  functional tests asserting the native surface renders with **no
  webview present** (dashboard, orders, subscriptions, events,
  conversations round-trip send, security list), the label/move picker
  flow against a fixture thread, and screenshot tests per screen. Runs on
  the Mac mini simulator against dev via the existing gate-runner rsync
  flow.

---

## Documentation deliverables (on implementation)

- `docs/mobile_apps.md` — JoineryMemberKit module section (screens,
  registry names, the Settings additions) alongside the existing module
  sections.
- `docs/social_features.md` — the messaging API actions
  (`conversation_list` / `conversation_thread` / `conversation_send` /
  `conversation_action`) as the messaging surface's API section; note the
  web page consumes the same actions.
- `docs/account_security.md` — the `security_overview` read surface and
  the native app-sessions/TOTP screen; state that passkey/vault
  management is web-only pending the credentials spec.
- `docs/subscription_tiers.md` — the `subscription_summary` read action.
- Move `specs/mobile_native_email.md` to `specs/implemented/` once the
  picker and test hardening land (its Android item already shipped).

---

## Acceptance

1. Signing into the iOS app and touring Profile, Orders, Subscriptions,
   Events, Conversations, and Settings → Security shows native screens —
   no webview — on every one, with data matching the website.
2. A conversation round-trip works natively: open inbox, open thread
   (unread clears on the web too), send a message, mute and delete from
   the menu; the web conversation page performs the same operations
   through the same `/api/v1` actions and `/ajax/conversations_ajax.php`
   is gone.
3. Cancelling a recurring subscription and withdrawing from an event
   each complete from the native screens with a confirmation step.
4. Change-plan, billing, notifications, and event session content open
   in the authenticated webview from their native entry points, chrome-
   less, without a login prompt.
5. Revoking another device's session from the native security screen
   kills it; revoking the current one signs the app out.
6. The mail label/move picker moves and labels threads per the
   exclusive/non-exclusive model, including create-folder, and the change
   is visible in the web reader.
7. Old app builds (without JoineryMemberKit) keep working: every flipped
   menu entry falls back to its web URL.
8. All new server actions have functional tests; JoineryMemberKit unit
   tests pass in the `-Package` scheme; the UI-test gates (including the
   no-webview assertions) pass on the Mac mini.
9. Registration-off, messaging-off, and products-off deployments render
   the dashboard without the corresponding sections or tiles.
