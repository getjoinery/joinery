# Inbound email — inline images in the message reader

**Status:** Implemented — code landed and browser-tested end-to-end (admin single-message reader only).
**Plugin:** `inbound_email`
**Depends on:** `specs/implemented/inbound_email_attachment_storage.md` — inline parts are stored as
`File` objects on the manifest (`ima_fil_file_id`, `ima_content_id`, `ima_is_inline`),
gated by `fil_private` (owner-or-admin). That extraction must land first; this
spec only *renders* what it stored.

## The problem

A received HTML email often embeds images by reference: the body contains
`<img src="cid:logo123">` and the bytes ride along as an inline MIME part tagged
`Content-ID: <logo123>`. In the admin message reader those images render **broken** —
the body is dropped verbatim into a sandboxed iframe and `cid:` is never resolved, so
nothing points the `<img>` at the stored bytes.

The attachment-storage spec already pulls each inline part into its own `File` and
records its Content-ID (`ima_content_id`). What's missing is the last step: turn each
`cid:` reference into a URL the reader can load. That's this spec.

## In plain terms

Make embedded images actually show up when an admin reads a received message — by
pointing each `cid:` reference at the stored image's gated URL — instead of leaving
every inline image broken.

## Why this is small now

The reader is authenticated, so it resolves `cid:` to a **URL**, not embedded bytes —
the image streams on demand from its `File`. And crucially, **the authorization is
already correct on the existing path**: an attachment `File` is gated by
`fil_private` (owner-or-admin), so serve.php's `/uploads/*` stream — which gates on
`File::is_viewable()` — admits the owner or admin viewing the reader. No new serving
endpoint, no second auth check: the inline image is just the attachment `File`'s gated URL.

serve.php's private branch already serves with the file's real content-type and
`X-Content-Type-Options: nosniff`, so an image renders inline and a part mislabeled as
an image cannot be sniffed into active content.

(This is the opposite of forwarding, which must *embed* bytes because its recipient is
external/unauthenticated. The reader viewer is authenticated, so a URL is right.)

## What already exists (and is reused)

- **The manifest** — `ima_content_id`, `ima_fil_file_id` per part. The `cid:` → bytes
  mapping is a lookup: `ima_content_id` (this message) → its `File`.
- **The `File` gated URL** — `File::get_url()` returns the local `/uploads/...` path for
  a private file; serve.php serves it gated by `is_viewable()` (the `fil_private`
  owner-or-admin check). Reused as-is.
- **The message reader** — `admin/admin_inbound_email_message.php`, which renders
  `iem_body_html` inside a `sandbox=""` `srcdoc` iframe. The body string is rewritten
  before it goes into the iframe.

## What to build

### 1. Rewrite `cid:` in the body before rendering

In the reader logic, before the HTML body enters the iframe, replace each `cid:<id>`
(in `src`/`href`) with the gated `File` URL of the manifest row whose `ima_content_id`
matches `<id>` **in this message** (scoped to the message so a Content-ID can never
reach another message's parts). Content-IDs match as bare tokens (manifests store them
trimmed of `<>`). Unmatched `cid:` references are left as-is (they simply stay broken,
as today — no worse).

### 2. Reader rendering

The body keeps rendering in the `sandbox=""` iframe (no scripts). Sandboxed iframes
still load image subresources, so rewritten `<img>` URLs render. The inline image GET
is a subresource from the sandboxed (opaque-origin) `srcdoc` iframe, and the admin
session cookie rides along so `is_viewable()` passes: `SameSite=Lax` is evaluated on the
request URL's registrable domain against the top-level site — the iframe's opaque origin
doesn't enter into it — and the GET is same-site, so the cookie is sent. (If testing ever
shows cookies somehow not flowing, revisit with a session-independent serve path; not
expected, not designed up front.)

## Security

- **One authorization algorithm.** Inline images authorize through `is_viewable()`
  (owner-or-admin), identical to attachment download and every other file — no
  reader-specific gate.
- **Per-message resolution.** A `cid:` resolves only against its own message's manifest;
  no cross-message reach.
- **nosniff + real content-type.** serve.php serves the stored content-type with
  `nosniff`, so a part mislabeled as an image can't be sniffed into an active document;
  a non-image `cid:` simply fails to render as an `<img>`, harmlessly.
- **No new bytes.** Images stream from the existing `File`; nothing is duplicated.

## Out of scope

- **Forwarding** — re-embedding inline parts on a forwarded message is the
  attachment-storage spec's job (it embeds bytes; this spec resolves URLs).
- **IMAP (`remote`) messages** — covered only if their inline parts are file-backed; for
  purely on-demand IMAP parts, inline rendering would fetch from IMAP, a later
  extension. Not built here.

## Implementation outline (provisional)

1. Reader logic: rewrite `cid:<id>` → the matching attachment `File`'s gated URL, scoped
   to the message, before the body enters the iframe.
2. Browser-test inline rendering in the `sandbox=""` iframe; confirm the session cookie
   reaches serve.php so `is_viewable()` passes (expected — same-site `SameSite=Lax` GET).
3. `php -l` + `validate_php_file.php` on every modified file; bump the `inbound_email`
   plugin version.

## Docs

On implementation, update `plugins/inbound_email/docs/overview.md`: the reader resolves
`cid:` inline images to the attachment `File`'s gated URL, authorized by the same
`is_viewable()` (owner-or-admin) check as download. Cross-reference
`inbound_email_attachment_storage.md`.
