# Mobile Native Member Screens — Follow-ups

**Created:** 2026-07-07

**Status:** Implemented (items 1 and 3; item 2 resolved as out of scope —
see its section). The phase 3 gate runs clean end to end (22/22 checks,
v1.3.1) including the two new legs. The first live run also surfaced two
pre-existing platform bugs, both fixed in migration v139: the core menu
rows never received the `nativeScreen` flips (core seeding is insert-only),
and the `app_navigation` setting still pinned the mailbox tab by its
pre-rename `inbound-email-mailbox` slug, which had silently dropped the
Email tab from every app.

**Purpose:** Close three gaps left open by
`specs/implemented/mobile_native_member_screens.md`: the new iOS UI-test
suites have never actually run against a simulator, one of them can't run
at all because its seed data doesn't exist, and the conversations screen
has no way to start a new conversation.

**Depends on (implemented):** `specs/implemented/mobile_native_member_screens.md`
(JoineryMemberKit, the conversation/orders/events/subscriptions/security
actions, the `phase3_gate.sh` runner and its `phase3_fixtures.php` seed
script).

---

## 1. New UI-test suites have never run live

`ios/joinery-member-ios/UITests/` gained eight new files with the member-screens
work (`ProfileUITests`, `OrdersUITests`, `SubscriptionsUITests`,
`EventsUITests`, `ConversationsUITests`, `SecurityUITests`,
`MailFolderPickerUITests`, `MemberScreenshotUITests`). They compile and
build-for-testing cleanly on the Mac mini, but none has executed against a
booted simulator — the build was run directly on the mini instead of
through the gate runner. (The credentials file the gate reads,
`~/.joinery_app_test_creds`, lives on the **dev box** and is forwarded to
the mini as `TEST_RUNNER_` env vars over ssh; it exists and nothing needs
provisioning on the mini.)

Compiling is not the same as passing. A test can build clean and still
fail the moment it runs — a missing accessibility identifier, a race
between a network fetch and an assertion, a screen that never reaches the
expected state. None of that is caught until the suite actually executes.

**What's needed:** run `tests/functional/ios/phase3_gate.sh` from the dev
box end to end and fix whatever the live run surfaces. This item is a
prerequisite for item 3 below — the two suites added there can't go green
without a working runner pass first.

---

## 2. No way to start a new conversation from the app

The server action `conversation_thread` already supports compose-mode
dedup — call it with `to` (a user id) instead of `conversation_id` and it
either returns the existing 1:1 thread or an empty compose-mode payload
(`specs/implemented/mobile_native_member_screens.md` § `conversation_thread`).
`JoineryMemberKit`'s `ConversationThreadStore`/`ConversationAPI` already
speak this parameter. But nothing in the app can produce a `to` value —
there is no member picker anywhere in the native UI, so the only way to
reach a conversation today is to already be in one (opened from the inbox
list, or from a notification/deep link).

This mirrors a real product gap, not just a UI omission: a member has no
native path to message another member for the first time. The web
conversations page has the same limitation today (`views/profile/conversations.php`
has no "new message" entry point either), so this is not a regression —
but it's an open gap worth closing since the native inbox is now the
primary surface.

**Resolution: out of scope here — this is a product decision, not
follow-through.** The open question is answered: no member-lookup surface
exists server-side (verified — no directory or search action anywhere; the
web compose button in `views/profile/conversations.php` is a hidden stub
pointing at `to=0`). Building one means deciding the platform's
messaging-initiation model — who may message whom, whether discovery is a
global directory search or contextual entry points (a Message button on
event attendee lists, member profiles), and how the block system gates
first contact. Deciding that by accident, as a side effect of feeding a
compose button, is the wrong order. When the model is decided it becomes
its own small spec (server lookup action + iOS picker); until then the
native inbox handles all existing threads and nothing is blocked
pre-launch.

---

## 3. `MailFolderPickerUITests` and `ConversationsUITests` have no seed data

Both suites read required environment variables that nothing produces
today (`ios/joinery-member-ios/UITests/TestSupport.swift`'s `TestEnv.require`
pattern — missing values fail the test immediately, they don't skip).

### `MailFolderPickerUITests`
Needs:
- `JOINERY_MAIL_SUBJECT` — already produced by `phase3_gate.sh`'s existing
  mailbox-seed step (the same message `MailboxUITests` reads), so this
  suite can piggyback on that seed with no new mail infrastructure.
- `JOINERY_MAIL_FOLDER_NAME` — a fresh, unique name for the folder/label
  this run creates. Nothing generates this today; needs a
  `$(date +%s)`-suffixed value the same way `MAIL_REPLY` is generated
  (`phase3_gate.sh` line ~202), passed as a new `TEST_RUNNER_` var.

**What's needed:** in `phase3_gate.sh`, generate the folder name and add a
`run_suite` call for `MailFolderPickerUITests` right after the existing
mailbox leg (it depends on the same seeded message already being in the
inbox). After the suite runs, verify server-side that the folder was
created and the seeded thread was filed into it — member-created folders
are rows in `ilb_inbound_email_labels` with thread membership in
`ilm_inbound_label_members` (`fil_inbound_email_filters` is filter rules,
not folders; the folder is created by `MailboxService::createFolder()` via
`thread_action`'s `create_folder`). Verify the same way the calendar leg
verifies its round-trip against `cal_entries` (`phase3_gate.sh` line ~184).
Both new legs must run **before** the revocation leg, which stays last —
it revokes every fixture session key.

### `ConversationsUITests`
Needs:
- `JOINERY_CONVERSATION_OTHER_NAME` — display name of a second fixture
  user to converse with.
- `JOINERY_CONVERSATION_REPLY_TEXT` — text the test types and sends.

This is the larger gap: there is no second fixture user, no seeded
conversation between it and the main fixture account, and no seeding
script at all. The mailbox leg has `tests/functional/ios/phase3_fixtures.php`
for exactly this kind of idempotent setup (ensures a grant + sender alias
exist, safe to run every invocation); conversations has no equivalent.

**What's needed:**
1. A new idempotent seed script (same shape as `phase3_fixtures.php`) that
   ensures a second fixture user exists (a dedicated, permanent test
   account — not one create/destroyed per run, to keep the display name
   stable across gate runs) and that a conversation exists between it and
   the main fixture account, printing the other user's display name for
   the runner to capture.
2. A new leg in `phase3_gate.sh`: call the seed script, generate a
   timestamped reply text, run `ConversationsUITests`.
3. Server-side verification after the suite runs — query `msg_messages`
   for a row matching the reply text, the same way the mailbox leg
   verifies reply delivery in `iem_inbound_email_messages`
   (`phase3_gate.sh` line ~241).

---

## Sequencing

Item 1 first (get the existing eight suites actually running and green —
this is the baseline item 3 builds on). Item 3 next (the seed scripts +
runner wiring), verified by a live `phase3_gate.sh` pass including both
new legs. Item 2 is not built from this spec — it waits on the
messaging-initiation product decision and then gets its own spec.

## Test gates

- `phase3_gate.sh` runs clean end to end, including the two new legs, with
  server-side verification for each (folder membership row in
  `ilm_inbound_label_members`; reply message row in `msg_messages`).
- For the future item-2 spec: a functional test that `conversation_thread`
  called with a fresh `to` returns compose mode, and that sending from
  compose mode creates exactly one conversation (not a duplicate on a
  second attempt) — `Conversation::get_or_create_conversation` already
  guarantees this server-side; the UI test proves the picker wires it
  correctly.
