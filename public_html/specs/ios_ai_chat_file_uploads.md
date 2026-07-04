# iOS AI Chat — File Uploads — Spec

Brings the file-attachment feature already shipped in the web chat to the
native iOS chat (`JoineryAIChatKit`). A user can attach images, PDFs, and text
files to a chat message from the iOS app; the model receives them exactly as it
does from the web composer, and prior attachments render when a thread is
reopened.

## Current state (what already exists)

The whole server contract is done and surface-agnostic:

- **`chat_send` accepts attachments over the API today.** The action pipeline
  (`ApiLogicEndpoint`) reads `php://input` as the JSON body and falls back to
  `$_POST` when the body isn't JSON. A `multipart/form-data` request leaves
  `php://input` empty, so PHP populates `$_POST` and `$_FILES` natively, and
  `ChatAttachmentIngest` reads `$_FILES` directly. The same ingest,
  validation, storage, and per-file failure reporting back the web view and the
  `/api/v1` action — no server fork.
- **Attachments are already serialized to every client.**
  `ChatSerializer::message()` emits an `attachments` array (from
  `AiMessageAttachment::displayListForMessage()`): `{file_id, name, category,
  image_url}` per file. The native thread/poll/send payloads already carry it;
  the iOS models just don't parse it yet.

The gap is entirely client-side plus one serialization fix:

- **The iOS HTTP client cannot send files at all.** `APIClient` has one request
  path and always sends a JSON body with `Content-Type: application/json`. There
  is no multipart encoder anywhere in `JoineryKit`.
- **The iOS chat models have no attachment type** and the composer has no
  attach control.

## Transport decision (settled here so it isn't rediscovered mid-build)

The app sends attachments as `multipart/form-data` to
`POST /api/v1/action/joinery_ai/chat_send`, with the message and any seed
control fields as form fields and each file as a `attachments[]` part — the same
field shape the web form posts. This rides the existing endpoint with **no
server change to the action pipeline**.

One caveat to accept, not fix: the idempotency key is hashed over the raw
`php://input` body, which is empty for a multipart request. The
`Idempotency-Key` header still dedupes retries of the same logical send; the
body hash simply doesn't contribute for multipart sends. That is acceptable —
do not add a body-hash workaround for it.

## The one real design fork: private image display

`displayListForMessage()` returns `image_url` as the file's plain private URL
(`$file->get_url()`), which is served only behind the session-cookie/ownership
gate. The iOS app authenticates with API-key headers and sends no cookies, so
that URL 401s in a native `AsyncImage`.

**Resolution:** `displayListForMessage()` mints a short-lived signed URL for
image attachments via `File::mintSignedUrl('original', 300)` instead of the raw
private URL. Signed URLs work with no session (see
`docs/file_signed_urls.md`), so a single code path serves both the web chat and
the native app — the web renderer accepts the signed URL unchanged. Minting here
is the authorization statement, and it is correct: the list is only built for a
thread the caller already owns (`chat_thread`/`chat_poll` are owner-scoped). No
client-side signing round-trip is needed; the client re-fetches the thread for
fresh links, matching the 5-minute TTL convention.

This is the only server-side change in the feature.

## Client work items (decide the set once)

1. **`APIClient` multipart path.** Add a request path that sends
   `multipart/form-data` while reusing the single chokepoint's existing
   behavior — session-key headers, `client-app`/`client-version`, the
   `Idempotency-Key`, the 401/426 handlers, and the error-envelope mapping.
   Fields carry as form parts alongside the file parts; no JSON `Content-Type`.
2. **`ChatAPI.send()` attachments parameter.** Extend `send(...)` to take an
   optional list of picked files (bytes + filename + MIME) and route through the
   new multipart path when any are present, the JSON path otherwise. `seed`
   control fields still ride as form fields for a new conversation.
3. **Native picker.** A photo source (`PhotosPicker`) for images and a document
   source (`.fileImporter`) for PDFs and text files, filtered to the allowed
   uniform types. Read the selected item's bytes and filename for the send.
4. **Composer UI (`ChatThreadView`).** An attach control, selected-file chips
   with a per-chip remove, and allowing an empty message body when at least one
   file is attached (the server already permits attachments-only turns).
5. **`ChatAttachment` model + parsing.** A struct decoded from the serialized
   `attachments` entry (`file_id`, `name`, `category`, `image_url`), added to
   `ChatMessage`.
6. **Render attachments on message bubbles.** Image attachments as a thumbnail
   from the signed `image_url`; non-image attachments (`pdf`, `text`) as a
   labeled file chip with the filename. Applies to freshly sent turns and to
   history on thread reopen.
7. **Surface `attachment_warning`.** The send/poll payload may include
   `attachment_warning` (a file dropped at commit for server-side type drift).
   Show it inline the way the web reader does, so a dropped file is never
   silent.

## Validation authority

The server is the sole authority on type, size, count, and model capability
(`ChatAttachmentIngest::prepare()` → `AiAttachment::validateRaw()`). The client
does **not** re-implement those rules. The picker filters to the allowed types
for a good first-pass experience — images (`png`, `jpeg`, `gif`, `webp`,
`avif`), `application/pdf`, and text (`plain`, `markdown`, `csv`, `json`) — but
any rejection (oversize file, image on a non-vision model, secured PDF on a
non-document model) surfaces the server's user-facing error verbatim. No
hardcoded limits in the app.

## Docs

Update `docs/mobile_apps.md` with the native attachment flow: the multipart
transport to `chat_send`, the picker sources and allowed types, that the server
owns validation, and that image attachments render from signed URLs minted by
the serializer. Do not narrate the change as new — describe the end state.

## Testing gate

On the iOS simulator against `dev.getjoinery.com`:

- Attach and send a PDF whose text extracts; confirm the model answers from its
  contents and the file chip renders on the user turn.
- Attach and send an image on a vision-capable model; confirm the thumbnail
  renders and the model sees the image.
- Reopen the thread; confirm both attachments still render (signed-URL image
  load succeeds without a session).
- Attach an image on a non-vision model; confirm the server rejection shows and
  nothing is persisted.
