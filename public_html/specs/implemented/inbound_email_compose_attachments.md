# Compose Attachments: Web UX, API Uploads, Native iOS

**Status:** Implemented. `MailboxSender::collectUploads()` /
`persistOutboundUploads()` are the one shared implementation used by the web
reader (`ajax/mailbox_send.php`) and the API action (`send_logic.php`); the
web compose panel got the paperclip/chips/drag-and-drop UX; iOS
`ComposeSheet` gained a Photo Library / Files attach menu and multipart send.
Verified live on dev.getjoinery.com (reply with a text-file attachment: sent
correctly with a working manifest-backed download, bytes round-tripped
exactly) and via `xcodebuild test -scheme JoineryKit-Package` (all existing
suites still pass — no `JoineryMailKitTests` were added since the touched
surface is UI/networking glue over already-tested models).
**Plugin:** inbound_email (server + web reader), JoineryMailKit (iOS)
**Pattern source:** the Joinery AI chat attachment flow
(`specs/implemented/joinery_ai_file_uploads.md`,
`specs/implemented/ios_ai_chat_file_uploads.md`)

## Current state

The picture is uneven, and the spec's shape follows from it:

- **Web compose already sends uploads.** The shared reader mount (member
  `/profile/inbound_email/mailbox` and the admin reader use the same form)
  renders a plain FormWriter multi-file input
  (`includes/mailbox_reader_mount.php:95`), the JS submits the whole form as
  multipart `FormData` to `/ajax/mailbox_send`, and
  `MailboxSender::attachUploads()` attaches the bytes at the MIME level with
  server-side caps. This shipped with
  `specs/implemented/outbound_reply_forward.md`. What's missing on web is the
  experience: a bare file-input row instead of the AI chat's paperclip button,
  pending-file chips with remove, and drag-and-drop.
- **The API action drops files.** `POST /api/v1/action/inbound_email/send`
  (`logic/send_logic.php`) is JSON-only: it never reads `$_FILES` and calls
  `$sender->send($params)` without the files argument (line 46). So no native
  client can attach anything today.
- **iOS has compose but no picker.** `ComposeSheet.swift` collects
  To/Cc/Subject/body only; `MailAPI.send()` posts a JSON body. The native
  email spec (`specs/mobile_native_email.md`) already promised "attachments
  from the system picker" and set the acceptance gate "a reply with an
  attachment round-trips (visible in the web reader's sent copy)" — neither
  is met.
- **Sent copies hide their attachments.** `storeOutboundRow()` persists the
  outbound message body but writes no attachment manifest rows, so the sent
  copy in every thread view (web, admin, iOS) shows nothing attached — even
  though the recipient got the files. To the user this reads as "attachments
  don't exist here."

## Goals

1. Web compose attachment UX matches the AI chat page: paperclip button,
   pending chips with remove, drag-and-drop onto the compose panel.
2. The `inbound_email/send` API action accepts multipart uploads, exactly
   like `joinery_ai/chat_send` does.
3. iOS compose gains Photo Library and Files pickers and sends attachments
   multipart.
4. Sent copies show what was attached: uploads are persisted as private
   Files with `ima_` manifest rows on the outbound message, so the existing
   thread/attachment plumbing renders and serves them with no new code.

## Non-goals

- No change to upload policy. The existing `MailboxSender` caps stay the
  rule: 10 files, 10 MB per file, 25 MB total including forwarded originals
  (`MAX_UPLOAD_FILES` / `MAX_UPLOAD_BYTES` / `MAX_TOTAL_BYTES`). No MIME
  allowlist is added — email legitimately carries arbitrary file types,
  unlike AI chat where the model can only consume specific ones.
- Forwarded originals are not copied into the outbound manifest. They remain
  visible on the source message in the same thread; only user uploads
  persist on the sent copy.
- No endpoint migration. The web reader keeps posting to its existing
  `/ajax/mailbox_send` endpoint, which already handles multipart, CSRF, and
  the caps correctly and needs no changes for this feature. The web work
  here is JS/UX only, so the endpoint is not being touched; it migrates to
  the API action whenever its server side genuinely changes. (Migrating only
  the send would also leave the reader split across two auth schemes, since
  list/thread/action stay on `/ajax` with the persistent token.)
- No standalone new-message compose (the mailbox remains
  reply / reply-all / forward), and no Android work (tracked by the Android
  app spec).

## Design

### 1. Uploads on the API send action

`send_logic.php` gains upload handling — the only server-side change to the
send path. A multipart POST to an `ApiLogicEndpoint` action already works
mechanically: `php://input` is empty for multipart, so the dispatcher falls
back to `$_POST` and PHP populates `$_FILES` natively —
`joinery_ai/chat_send` is the shipped precedent.

- Move the `$_FILES['attachments']` normalization currently inlined in
  `ajax/mailbox_send.php` (single-vs-array shape, upload-error mapping) into
  a static helper on `MailboxSender` (e.g. `MailboxSender::collectUploads()`),
  mirroring `ChatAttachmentIngest::collectUploads()`. Both the ajax endpoint
  and `send_logic()` call it, so the two surfaces cannot drift.
- `send_logic()` passes the result through: `$sender->send($params, $files)`.
- Error contract: a cap breach fails the whole send with the
  `MailboxSenderException` message — there is no per-file silent drop
  (unlike AI chat's `attachment_warning`), because a partially-attached
  email is worse than a retried one.

### 2. Persist the outbound manifest

After the transport send succeeds and `storeOutboundRow()` creates the
outbound `iem_` row, `MailboxSender` persists each accepted upload:

- `File::createFromBytes($bytes, $safe_name, $detected_mime, $owner_id,
  ['fil_private' => true, 'fil_source' => File::SOURCE_EMAIL_ATTACHMENT])`.
  The owner is the sending session user — unlike inbound mail (where the
  router guesses an owner from grants), here we know exactly who uploaded
  the file. Ownership is bookkeeping only: serving authorizes via mailbox
  grants (below), not File ownership.
- MIME comes from magic-byte detection (`File::detect_mime_bytes()` with the
  client type as fallback label), the same fail-honest rule the AI chat
  ingest uses. The bytes are read once and shared between
  `EmailMessage::attachData()` and `createFromBytes()`.
- One `InboundMessageAttachment` row per upload on the outbound message:
  `ima_filename`, `ima_content_type`, `ima_size_bytes`,
  `ima_is_inline = false`, `ima_fil_file_id`. Message deletion already
  cascades to manifest rows.
- Persistence failure after a successful send must not fail the send — the
  message is already on the wire. Log it and store the row; the sent copy
  degrades to showing no attachments, which is the current behavior.
- The Gmail `pending_sent_ingest` case (no local row stored; the sent copy
  arrives later through the normal inbound pipeline) needs nothing: that
  pipeline already extracts attachments into Files and manifest rows on
  ingest.

### 3. Rendering and serving — no new code

This is why the manifest is the whole answer to "show sent attachments":

- Thread payloads enrich attachments purely by message id
  (`MailboxService::attachmentsForMessages()`, and
  `withSignedTransport()` for native clients mints signed URLs), so
  outbound rows with manifest rows display automatically in the web reader,
  the admin message page, and the iOS thread view.
- Downloads authorize via mailbox grants: `profile_attachment_logic.php`
  gates on the attachment's message's alias, and `storeOutboundRow()`
  already sets `iem_iea_inbound_email_alias_id` on outbound rows. A grantee
  can fetch a sent attachment; anyone else gets the same 404 as any other
  mail attachment.

### 4. Web compose UX (AI chat pattern)

Rework the compose panel's attachment row, following
`chat_view_body.php` / the AI chat JS:

- Hidden `<input type="file" multiple>` plus a paperclip button in the
  compose footer next to Send. No `accept` filter (any file type).
- A pending-chip strip above the buttons: one chip per file showing name and
  human size, each with a `×` remove control. Because removal must be
  possible, the JS builds the `FormData` manually — form fields appended
  first, then the kept `File` objects as `attachments[]` — rather than
  serializing the form's own file input (the AI chat `send()` shape).
- Drag-and-drop onto the open compose panel adds files, with a dragover
  affordance class.
- Client-side checks are count and total-size preflight only, for a fast
  friendly message; the server stays the authority. The mount's
  `window.MAILBOX_READER` config gains `max_files`, `max_file_bytes`, and
  `max_total_bytes` sourced from the `MailboxSender` constants so the
  numbers can never drift from the real policy.
- Server rejections render inline in `#mbx-compose-error` with the draft and
  chips intact.

### 5. iOS (JoineryMailKit)

Mirror the chat implementation in `ChatThreadView.swift` /
`ChatAPI.swift`:

- `ComposeSheet.swift` gains an attach `Menu` with **Photo Library**
  (`PhotosPicker`, image types, HEIC transcoded to JPEG — reuse the chat's
  transcode approach, hoisting the helper into shared `JoineryKit` if
  practical) and **Files** (`fileImporter` with `[.item]` — any file type,
  matching the server's no-allowlist policy). Selected files render as
  removable chips above the body field.
- `MailAPI.send()` gains an attachments parameter: empty → the existing JSON
  `submitAction` path; non-empty → `APIClient.submitMultipart`
  (`inbound_email/send`, text fields plus one `MultipartFile` per attachment
  on field `attachments[]`) — the exact `ChatAPI.send()` shape.
- Client-side preflight mirrors the web: count and total-size against
  constants matching the server caps; the server remains the authority.
- Failure UX: the whole send fails with the server's message shown in the
  sheet; draft and attachments stay. No per-file drop semantics.
- Rendering needs nothing: `ThreadDetailView` already renders attachment
  lists from the thread payload's signed URLs, so sent copies appear as soon
  as the manifest persists.

## Implementation notes

Concrete file-level guidance. Line numbers are as of this writing — verify
before editing.

### Server

**`MailboxSender.php`** (all send-path changes live here, below both
endpoints):

- Add `public static function collectUploads(): array` — move the
  `$_FILES['attachments']` normalization verbatim from
  `ajax/mailbox_send.php:54-68`, plus a tolerance branch for the
  single-file (non-array `['name']`) shape, mirroring
  `ChatAttachmentIngest::collectUploads()`
  (`plugins/joinery_ai/includes/ChatAttachmentIngest.php:177-226`). Update
  `ajax/mailbox_send.php` to call it.
- `attachUploads()` (lines 409-441) already reads each file's bytes
  (`file_get_contents` at 433). Change its return type from `void` to the
  accepted-uploads list: one entry per attached file with `bytes`,
  `name` (already passed through `safeFilename()`), and `size`. The wire
  attach (`$email->attachData(...)`) is unchanged.
- In `send()`: capture the return at line 129
  (`$accepted = $this->attachUploads($email, $files, $total);`). After
  `storeOutboundRow()` returns the new row id (line 162), call a new
  private `persistOutboundUploads(int $message_id, array $accepted)`. The
  Gmail early return at line 156 stores no row and therefore persists
  nothing — correct, because that sent copy arrives later through the
  inbound ingest pipeline, which builds its own manifest.
- `persistOutboundUploads()`: wrap the entire body in
  `try/catch (Throwable)` → `error_log`, never throw (the message is
  already on the wire). Per file:
  `File::createFromBytes($bytes, $name, File::detect_mime_bytes($bytes),
  $this->viewer->getUserId(), ['fil_private' => true,
  'fil_source' => File::SOURCE_EMAIL_ATTACHMENT])`, then
  `InboundMessageAttachment::CreateEntry()` with
  `ima_iem_inbound_email_message_id`, `ima_filename`,
  `ima_content_type` (read `fil_type` back from the saved File — save
  re-detects from bytes), `ima_size_bytes`, `ima_is_inline => false`,
  `ima_fil_file_id`. Model reference for `createFromBytes`:
  `InboundEmailRouter.php:490-500`.
- Do not be misled by `InboundMessageAttachment::authenticate_write()`
  requiring permission 5: that hook is enforced only on the REST API
  surface, not by direct `save()`. Direct `CreateEntry()` from a
  member-permission session works — the webhook-context ingest already
  relies on this.

**`logic/send_logic.php`**: `$files = MailboxSender::collectUploads();`
then `$sender->send($params, $files);` (line 46). Rewrite the docblock
sentence claiming JSON-only transport. Nothing changes in
`ApiLogicEndpoint` — a multipart POST leaves `php://input` empty, the
dispatcher falls back to `$_POST`, and PHP fills `$_FILES` natively
(`joinery_ai/chat_send` is the shipped precedent).

### Web reader

**`includes/mailbox_reader_mount.php`**: replace the visible
`$compose->fileinput('attachments[]', ...)` row (line 95) with a hidden
`<input type="file" id="mbx_attachments" multiple>`, a paperclip button
(`#mbx-attach-btn`) beside Send, and an empty chip strip
(`#mbx-attach-strip`). Add the caps to the emitted `window.MAILBOX_READER`
config (lines 36-47): `max_files`, `max_file_bytes`, `max_total_bytes`
read from the `MailboxSender` constants (require the class in the mount)
so the numbers cannot drift.

**`assets/mailbox_reader.js`**: hold a `pendingFiles` array instead of
relying on the form's file input.

- Pattern source for all of this is the chat composer in
  `plugins/joinery_ai/includes/chat_view_body.php`: strip render 418-442,
  `addFiles()` count check 444-453, drag-and-drop wiring 464-481,
  `FormData` file append at 639.
- `openCompose()` (771-813) resets `pendingFiles` (replacing the
  input-clear at 804-805). Wire paperclip click → `input.click()`, input
  `change` → `addFiles()`, and `dragover`/`drop` on the open
  `#mbx-compose` panel.
- `addFiles()` preflights count and running total against the config caps
  and shows failures in `#mbx-compose-error`; the server remains the
  authority.
- `submitCompose()` (840-872) builds the `FormData` manually: append
  `mode`, `source_id`, `_csrf_token`, `to`, `cc`, `subject`, `body` from
  the form fields, then
  `pendingFiles.forEach(f => fd.append('attachments[]', f, f.name))`.
  Endpoint (`CFG.sendUrl`), `X-CSRF-Token` header, and the success/error
  handling are unchanged; clear `pendingFiles` on success.

**`assets/mailbox_reader.css`**: chip styles (name + size + `×`), a
dragover affordance class on the compose panel.

### iOS

Files to change: `ios/joinery-kit/Sources/JoineryMailKit/`
`ComposeSheet.swift`, `MailAPI.swift`, `MailModels.swift`. Pattern
sources: `JoineryAIChatKit/ChatThreadView.swift` (attach `Menu` 181-201,
HEIC→JPEG `loadPhoto()` 257-269, security-scoped `loadFiles()` 271-282,
chips 203-229), `JoineryAIChatKit/ChatAPI.swift` (`send()` branching
42-77), `JoineryKit/APIClient.swift` (`submitMultipart` 154-175,
`MultipartFile` 244-258).

- `MailModels.swift`: add `MailOutgoingAttachment` (id, filename,
  mimeType, data) mirroring `ChatOutgoingAttachment`
  (`ChatModels.swift:212-224`). Do **not** import `JoineryAIChatKit` into
  `JoineryMailKit` — duplicate the small type (hoisting shared picker
  helpers into `JoineryKit` is optional, not required).
- `ComposeSheet.swift`: `@State private var attachments:
  [MailOutgoingAttachment]`; an attach `Menu` offering **Photo Library**
  (`PhotosPicker`, `matching: .images`,
  `maxSelectionCount: 10 - attachments.count`, HEIC transcoded to JPEG)
  and **Files** (`.fileImporter(allowsMultipleSelection: true,
  allowedContentTypes: [.item])` — any type, matching the server's
  no-allowlist policy; remember
  `startAccessingSecurityScopedResource()`). Removable chips in a Form
  section above the error section. Preflight constants matching the
  server: 10 files, 10485760 bytes per file, 26214400 total. Pass
  `attachments` to `api.send(...)`; on failure (line 137's existing
  handling) the draft and attachments stay.
- `MailAPI.swift`: `send(...)` (115-131) gains
  `attachments: [MailOutgoingAttachment] = []`. Empty → the existing JSON
  `submitAction` call unchanged. Non-empty →
  `client.submitMultipart("inbound_email/send", fields: [...], files:
  attachments.map { MultipartFile(field: "attachments[]", ...) })`. All
  six fields go as text parts (stringify `source_id` — the server
  `intval()`s it).
- `ThreadDetailView` needs no changes: sent-copy attachments arrive in the
  thread payload once the server persists manifests.

### Build and verify

- Server: `php -l` and
  `php maintenance_scripts/dev_tools/validate_php_file.php` on every
  touched PHP file; run the existing inbound_email functional suites; live
  test on dev.getjoinery.com (send from the reader at
  `/profile/inbound_email/mailbox`; delivered mail lands in
  `iem_inbound_email_messages` — see CLAUDE.md's inbound email testing
  notes).
- iOS: the source of truth is `{repo root}/ios/joinery-kit` on this dev
  box — never edit the Mac mini's `~/dev/joinery-ios` copy (it is a
  disposable rsync target, clobbered on every sync). Build and test over
  `ssh macmini` after rsyncing `ios/` there (the gate scripts under
  `tests/functional/ios/` show the rsync invocation). Unit tests:
  `xcodebuild test -scheme JoineryKit-Package -destination
  "platform=iOS Simulator,name=iPhone 16"` (runs `JoineryMailKitTests`).
  Simulator screenshots via `xcrun simctl io booted screenshot` for visual
  checks.
- Version bumps: docblock versions in every touched PHP/JS/CSS file and
  `plugins/inbound_email/plugin.json`.

## Documentation updates (part of implementation)

- `plugins/inbound_email/docs/overview.md` — add a compose/sending
  attachments statement: uploads on all surfaces, the caps, outbound
  manifest persistence, grant-gated serving.
- `docs/mobile_apps.md` — extend the native attachment-flow section to cover
  mail compose (multipart `attachments[]`, server-authoritative validation).
- `docs/api.md` — note that `inbound_email/send` accepts multipart with
  `attachments[]`, alongside the existing `joinery_ai/chat_send` precedent
  if listed.
- Version bumps in every touched file docblock and
  `plugins/inbound_email/plugin.json`.

## Acceptance

1. Web: reply with two files attached via the paperclip → recipient receives
   both; the sent copy in the thread lists both; a chip removed before send
   is not sent; an 11th file or oversize total is rejected inline with the
   draft intact.
2. API: multipart POST to `/api/v1/action/inbound_email/send` with
   `attachments[]` sends and persists the manifest; the JSON-only call shape
   continues to work unchanged. Web sends through `/ajax/mailbox_send`
   persist the manifest identically (it lives in `MailboxSender`, below the
   endpoints).
3. iOS: attach one photo (HEIC source) and one PDF to a reply → recipient
   receives a JPEG and the PDF; both are visible in the web reader's sent
   copy (closes the `specs/mobile_native_email.md` acceptance gate) and in
   the iOS thread view.
4. Authorization: a mailbox grantee can download a sent attachment; a
   non-grantee gets 404.
5. Existing reader test suites pass.
