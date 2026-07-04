# Native Mobile Email — Server API & iOS Client

**Status:** Implemented — the server-side mailbox API (Phase 1) and the iOS
`JoineryMailKit` client (Phase 2) from the original two-front
`specs/mobile_native_email.md` plan. Split out once these were verified
complete, so the remaining work (Android client, one missing iOS screen,
API-layer test hardening) has its own focused spec:
`specs/mobile_native_email.md`.
**Plugin:** inbound_email (server), JoineryMailKit (iOS)

## What this covers

The reusable, once-written server API any native client consumes, plus the
first client — iOS — built against it. Android was always meant to consume
the same API and mirror the same screens; it just hasn't been built yet
(tracked separately).

## Server-side: the mailbox API

Every action lives in `plugins/inbound_email/logic/` with a `_logic_api()`
companion, exposed as `POST /api/v1/action/inbound_email/{action}`,
sessioned, building a `MailboxViewer` for the key's user and going through
`MailboxService` — the same shared brain the web AJAX endpoints use, so
scoping, threading, view semantics, and two-way-sync side effects live in
exactly one place.

| Action | Purpose | Verified at |
|---|---|---|
| `mailboxes` | Granted mailboxes with views/folders/unread counts | `mailboxes_logic.php` (`mailboxes_logic_api()`) |
| `thread_list` | Paged threads for a mailbox + view/folder/search | `thread_list_logic.php` (`thread_list_logic_api()`) |
| `thread` | One full thread: messages, bodies, attachment metadata | `thread_logic.php` (`thread_logic_api()`) |
| `thread_action` | mark_read/unread, star/unstar, archive/unarchive, delete, mark_spam/not_spam, set_membership, create_folder | `thread_action_logic.php` (`thread_action_logic_api()`) |
| `send` | Reply/reply-all/forward/new-message as the mailbox, multipart attachments | `send_logic.php` (`send_logic_api()`), via `MailboxSender` |

### Attachments and inline images — signed URLs

`thread_logic.php` calls `MailboxService::withSignedTransport($messages)`,
which mints short-lived signed URLs (`File::mintSignedUrl()` —
`specs/implemented/file_signed_urls.md`) for file-backed attachments and
rewrites `cid:` inline-image references to signed URLs
(`MailboxService::resolveInlineImages()`). Minting happens only after the
viewer-scope check that gated the thread fetch — minting *is* the
authorization statement, and the serving path validates signature + expiry
with no session at all.

## iOS client: JoineryMailKit

`ios/joinery-kit/Sources/JoineryMailKit/` — a standalone Swift package
product with no brand imports (per `Package.swift`), consumed unchanged by
the member app. `JoineryMail.registerScreens()` registers the native screen
name `"mailbox"` (`MailboxScreen.swift`).

Screen inventory built:

1. **Mailbox home** — switcher across granted mailboxes, views (Inbox / All
   Mail / Spam), folders/labels, unread badges (`MailboxScreen.swift`).
2. **Thread list** — paged, pull-to-refresh, search, swipe actions (archive,
   read/unread) (`MailboxScreen.swift`).
3. **Thread view** — collapsed/expanded messages, HTML bodies in a sandboxed
   `WKWebView` (JavaScript off, link taps open externally), inline images via
   signed URLs, attachments open into the share sheet
   (`ThreadDetailView.swift`, `MessageCardView.swift`).
4. **Compose** — reply/reply-all/forward with quoted body, plus new-message
   compose and attachment uploads from the system picker (`ComposeSheet.swift`,
   `specs/implemented/inbound_email_compose_attachments.md`,
   `specs/implemented/inbound_email_new_message_compose.md`).

**Not built (tracked in `specs/mobile_native_email.md`):** screen 5,
label/move picker with create-folder — the backend
(`set_membership`/`create_folder` in `thread_action_logic.php`) already
supports it; the iOS UI for it doesn't exist yet.

## The route flip

`plugins/inbound_email/plugin.json`'s `profileMenu` entry already declares
`"nativeScreen": "mailbox"`, so any build carrying the mail module renders
the native screens; an older build without it falls back to the web reader
via the entry's `fallback_url`.

## Test gates verified

- `plugins/inbound_email/tests/profile_mailbox_test.php` — member/grantless
  viewer scope, `canCompose`, send-scope rejection, attachment access
  (granted vs. non-granted), anonymous-403 on every endpoint.
- `plugins/inbound_email/tests/inbound_email_mailbox_grant_test.php` — grant
  CRUD/cascade.
- `ios/joinery-member-ios/UITests/MailboxUITests.swift` —
  `testReadAndReplyToMail()`, which also asserts `app.webViews.firstMatch`
  does not exist (proving the screen renders natively, not via webview).
- `ios/joinery-member-ios/UITests/MailScreenshotUITests.swift`.

**Known test gap (tracked in `specs/mobile_native_email.md`):** no test
directly exercises the `_api()` action layer itself (signed-URL expiry,
cross-viewer denial for the native transport specifically) — existing
coverage proves scoping at the `MailboxService` level, one layer down.

## Acceptance (from the original spec's checklist)

- Scope holds at the API — **met**: `MailboxViewer`/`MailboxService` scoping,
  exercised by `profile_mailbox_test.php`.
- Attachments/inline images render via signed URLs that expire and never leak
  across viewers — **met**: `mintSignedUrl`/`resolveInlineImages`, iOS renders
  via `MessageCardView.swift`.
- Sending as the mailbox works with attachments; sent copy appears in the web
  reader — **met on iOS**; Android and an explicit round-trip test remain.
- After the navigation flip, versions without the module keep working on the
  web fallback — **met**: `plugin.json`'s `fallback_url`.
- `JoineryMailKit` builds standalone with no brand imports — **met**.

Remaining acceptance items (full triage on **both** platforms, Android
build-standalone, Android round-trip) live in `specs/mobile_native_email.md`.
