# Native Mobile Email — Remaining Work (Android Client, iOS Gap, Test Hardening)

**Status:** Draft
**Depends on:** `specs/implemented/mobile_native_email_server_api_and_ios.md`
— the sessioned mailbox API and the iOS `JoineryMailKit` client are already
built and verified. This spec covers only what's left: the Android client,
one missing iOS screen, and test-gate hardening. Build against the API as
documented there; don't re-derive it.

## What's left

1. **Android client** — the entire native mail surface for Android does not
   exist yet. No `joinery-android-mail` module (or equivalent) is declared in
   `android/settings.gradle`; nothing in the Android tree registers a
   `"mailbox"` native screen.
2. **iOS gap** — `JoineryMailKit` is missing the label/move picker with
   create-folder (screen 5 below). The backend already supports it
   (`set_membership`/`create_folder` in
   `plugins/inbound_email/logic/thread_action_logic.php`); only the iOS UI
   needs building.
3. **Test-gate hardening** — no test directly exercises the `_api()` action
   layer itself (signed-URL expiry, cross-viewer denial for the native
   transport specifically); coverage today proves scoping one layer down, at
   `MailboxService`. No test asserts a mobile-originated send's sent copy is
   visible in the web reader (the "round-trip" acceptance item).

## Android client work

Mirror `JoineryMailKit` exactly — same API, same screens, same UX model,
just Kotlin instead of Swift (the platforms share no client code by prior
decision, `specs/implemented/android_app_platform.md`). A new
**`joinery-android-mail`** Kotlin module, added the way `joinery-android`
apps add any layered module; it registers the native screen name `mailbox`
with `NativeScreenRegistry` so the server's existing `nativeScreen: "mailbox"`
route flip (already live, see the implemented spec) lights it up
automatically — no server change needed for Android specifically.

Screen inventory (identical to iOS's, which is the reference implementation
— when in doubt about behavior, match what `JoineryMailKit` already does):

1. **Mailbox home** — switcher across granted mailboxes; views (Inbox, All
   Mail, Spam) and folders/labels; unread badges.
2. **Thread list** — paged, pull-to-refresh, search, swipe actions (archive,
   read/unread).
3. **Thread view** — collapsed/expanded messages; HTML bodies rendered in a
   sandboxed embedded web widget (Android WebView: JavaScript off, link taps
   open externally); inline images via the signed URLs; attachments open into
   the system viewer/share sheet.
4. **Compose** — reply/reply-all/forward/new-message with quoted body (where
   applicable) and attachments from the system picker, mirroring
   `ComposeSheet.swift`.
5. **Label/move picker** with create-folder.

**The v1 line:** everything a mailbox user can do in the web reader — views,
search, the full action set, labels, send. Not native in v1 (the web reader
remains for them): filter management and Gmail filter import, spam settings,
and admin oversight surfaces. Freshness is pull-to-refresh plus
refresh-on-foreground; push notification for new mail is a future spec.

## iOS gap: label/move picker

Add the missing screen to `JoineryMailKit` — a Move/Labels control on the
open thread, matching the web reader's exclusive-vs-non-exclusive folder
model (`plugins/inbound_email/assets/mailbox_reader.js`'s
`buildFolderControl()` is the reference behavior: single-pick "Move" for an
exclusive feed, checkbox "Labels" for a non-exclusive one, plus
create-folder). Wired to the existing `thread_action` API action's
`set_membership`/`create_folder` — no server change needed.

## Test-gate hardening

- Add functional tests that call the `_api()` action layer directly (not
  just `MailboxService`): a granted key's `thread`/`thread_action`/`send`
  succeed only within its aliases; a grantless key gets empty/denied;
  signed URLs from the `thread` action expire and are denied for a viewer
  outside the granting alias.
- Add a send round-trip test: a mobile-originated `send` (reply or new
  message, with an attachment) stores the outbound row and manifest, and the
  web reader's thread view renders the same sent copy with the same
  attachment.

## Delivery gate (Android)

**Gate:** UI test suite (Simulator/emulator against `dev.getjoinery.com`,
seeding mail via `*@inbox.dev.getjoinery.com`) — read a seeded thread; every
thread action reflects in the web reader and vice versa; search and paging
parity; attachments open and inline images render; a reply with an
attachment round-trips (visible in the web reader's sent state); grantless
account sees the empty state; after the route flip (already live
server-side), a build without the mail module still lands on the web
fallback.

## Acceptance checklist (remaining)

1. A granted member reads, triages (full action set including labels and
   create-folder), searches, and replies natively on **both** platforms —
   not yet true: Android has no mail surface, and iOS is missing labels/
   create-folder.
2. Sending as the mailbox works from **both** apps with attachments; the
   sent copy appears in the web reader, proven by a test — Android sender
   doesn't exist; no round-trip test exists yet on either platform.
3. `joinery-android-mail` builds standalone with no brand imports; the
   Android member app consumes it unchanged (mirroring the already-met iOS
   half of this item).

## Out of scope (future specs)

- Push notifications for new mail (rides a future platform push spec).
- Filter management, Gmail import, spam settings in-app — web reader.
- Offline cache and draft sync.
- Attachment upload size/type policy changes (server policy is unchanged).

## Versioning

- Bump `@version` on each modified/created file.
- Android: new module gets an initial version per that platform's convention.

## Documentation deliverables (on implementation)

- `docs/mobile_apps.md` — extend the mail-module section to cover Android
  once it exists, and document the iOS label/move picker.
