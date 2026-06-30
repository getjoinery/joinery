# Inline (embedded) attachments for outgoing email

**Status:** Draft — design in progress
**Layer:** core email — `includes/EmailMessage.php` and the transports in
`includes/email_providers/` (+ `includes/SmtpMailer.php`)
**Depends on:** nothing — this is a foundational email-layer capability.
**Consumed by:** `specs/inbound_email_attachment_storage.md` (forwarding a received
message must re-embed its inline images so they still render). Likely future
consumers: templated mail with an embedded logo, reports with charts, any HTML body
that references an image by `cid:`.

## The problem

An HTML email can show an image two ways: link to it over the web, or **embed the
bytes in the message** and reference them from the HTML with `cid:<id>` (a
Content-ID). Embedding is what makes a logo or a forwarded inline image appear in the
recipient's client with no network fetch and no login.

`EmailMessage` can't express that. An attachment is just `data` + `name` + `type`;
there is no Content-ID and no "inline" disposition, and **none of the transports set
one** — every attachment goes out as a regular file attachment. So any HTML body that
references `cid:` images renders **broken images** at the recipient. For inbound-email
*forwarding* specifically, a message received with inline images cannot be forwarded
faithfully — the images break.

Rewriting `cid:` to a hosted URL is **not** an option for outgoing mail: the recipient
is external and unauthenticated, so a platform URL gives them a broken image or an auth
wall. The bytes must travel inside the message. This spec adds that.

## In plain terms

Let a message carry an image *inside* it, tagged with the id the HTML body uses to
point at it, so embedded images (a logo, a forwarded inline picture) actually show up
in the recipient's email — instead of every image being demoted to a downloadable
attachment.

## The model

- **An attachment can be inline.** An attachment record gains an optional
  **Content-ID** and an **inline** flag. Inline means "render me in the body via my
  `cid:`," not "list me as a downloadable file."
- **The id is a bare token.** Content-IDs are stored and passed **without** angle
  brackets (e.g. `logo123`, not `<logo123>`); the HTML body references `cid:logo123`.
  Each transport formats the wire representation it needs. (Inbound manifests already
  store `ima_content_id` trimmed of `<>`, so they line up directly.)
- **Transports map it to their native mechanism.** Embedding an image is a solved
  problem in every mail library/API — each just spells it differently. This spec adds
  one shared message field and the per-transport mapping.

## What already exists (and is reused)

- **`EmailMessage`** — the transport-agnostic message value object; attachments are a
  flat array consumed by every transport via `getAttachments()`. Extended here.
- **`EmailSender`** facade + provider auto-discovery + transport selection
  (`email_service` / `email_fallback_service`). Unchanged — inline rides the existing
  send path.
- **`SmtpMailer::applyMessage()`** — the single attachment-mapping chokepoint shared
  by `SmtpProvider`, `ConnectedMailboxProvider`, and `SesProvider` (raw-MIME path).
  One edit here covers all three.
- **The HTTP-API providers** (`MailgunProvider`, `SendGridProvider`,
  `PostmarkProvider`, `MailjetProvider`, `ResendProvider`, `BrevoProvider`) — each
  builds its own attachment payload; each gets a localized edit.

## What to build

### 1. `EmailMessage` carries inline attachments

Add an inline counterpart to `attachData()`:

```php
public function attachInlineData(
    string $data,
    string $cid,             // bare Content-ID token, no angle brackets
    string $fileName,
    string $contentType = 'application/octet-stream'
): self
```

It appends an attachment entry with `'cid' => $cid, 'inline' => true` alongside the
existing `data` / `name` / `type`. Regular `attach()` / `attachData()` entries are
unchanged (no `cid`, not inline). `getAttachments()` returns the same array shape with
the two optional keys present on inline entries.

### 2. SMTP chokepoint — one edit, three transports

In `SmtpMailer::applyMessage()`, when an in-memory attachment has a `cid`, embed it
instead of attaching it:

```php
if (!empty($attachment['cid'])) {
    $this->addStringEmbeddedImage(
        $attachment['data'], $attachment['cid'], $attachment['name'],
        PHPMailer::ENCODING_BASE64, $attachment['type'] ?? 'application/octet-stream'
    );
} elseif (isset($attachment['data'])) {
    $this->addStringAttachment(...);   // unchanged
} else {
    $this->addAttachment(...);          // unchanged
}
```

PHPMailer takes an arbitrary Content-ID, so this is exact. This one change serves
`SmtpProvider`, `ConnectedMailboxProvider`, and the `SesProvider` raw-MIME path.

### 3. HTTP-API providers — per-provider inline mapping

Each provider's attachment loop checks for `cid` and uses its native inline field:

| Provider | Inline mechanism | Arbitrary `cid`? |
|---|---|---|
| SendGrid | `setDisposition('inline')` + `setContentId($cid)` | yes |
| Postmark | attachment `ContentID` field | yes |
| Mailjet | `InlinedAttachments` + `ContentID` | yes |
| Mailgun | `inline` param; body references `cid:<filename>` ⇒ send the inline part with **filename = `$cid`** | yes (via filename) |
| Resend | no Content-ID field in API | **no — degrade** |
| Brevo | no Content-ID field in API | **no — degrade** |

**Degrade rule (decided once, here):** a transport that cannot carry a Content-ID
sends the inline part as a **regular attachment** and logs a single distinct marker.
The message still goes; the image shows as a downloadable attachment rather than
embedded. This is an honest provider limitation, not a fallback to paper over — it is
declared up front so no consumer assumes universal embedding.

## Up-front integration inventory

Every place that turns an `EmailMessage` attachment into wire format, with what it
needs:

| Site | Change |
|---|---|
| `EmailMessage` | add `attachInlineData()`; inline entries carry `cid` + `inline` |
| `SmtpMailer::applyMessage()` | embed when `cid` set (covers SMTP/ConnectedMailbox/SES) |
| `SendGridProvider` | `setDisposition('inline')` + `setContentId()` |
| `PostmarkProvider` | attachment `ContentID` |
| `MailjetProvider` | `InlinedAttachments` + `ContentID` |
| `MailgunProvider` | `inline` param, filename = `cid` |
| `ResendProvider` | degrade to attachment + log |
| `BrevoProvider` | degrade to attachment + log |
| `EmailSender` facade / transport selection | none |

## What does NOT change

- **Regular attachments** — entries without `cid` behave exactly as today across every
  transport.
- **The send path** — `EmailSender::send()`, provider discovery, transport selection,
  fallback, batching: untouched. Inline is just a richer attachment entry.
- **`EmailMessage::validate()`** and the fluent builder — untouched.

## Security

- **No new exposure.** Inline bytes are attachment bytes the sender already chose to
  include; embedding changes only the disposition + a Content-ID, not who receives
  them. The image goes to exactly the message's recipients.
- **No platform URLs in outgoing mail.** Embedding is specifically what lets us *avoid*
  putting a gated `/uploads/*` URL in a message bound for an unauthenticated recipient.
- **`cid` is opaque.** A Content-ID is a label inside one message; it carries no
  authority and references nothing server-side.

## Pre-launch / migration

Pure code addition; no schema, no settings, no data. Nothing to migrate.

## Out of scope

- **Reader-side `cid:` handling** — making inline images render in the *admin reader*
  is a different surface (the reader is authenticated, so it rewrites `cid:` → a gated
  `File` URL). That belongs to the inbound-email reader work, not here.
- **Inline from a file path** — only the in-memory `attachInlineData()` is added (the
  consumers hold bytes). A path-based inline variant can be added later if a consumer
  needs it.
- **Adding Content-ID support to Resend/Brevo** — gated on their APIs exposing it;
  until then they degrade as stated.

## Implementation outline (provisional)

1. `EmailMessage::attachInlineData()` + the two optional entry keys; bump `@version`.
2. `SmtpMailer::applyMessage()` — embed branch (covers SMTP/ConnectedMailbox/SES).
3. Per-provider inline mapping for SendGrid, Postmark, Mailjet, Mailgun; degrade +
   log for Resend, Brevo.
4. `php -l` + `validate_php_file.php` on every modified file; bump `@version` on each.
5. Tests: an inline entry embeds with the right Content-ID on a PHPMailer-built
   message (assert the MIME has `Content-ID: <cid>` and inline disposition); a
   regular attachment is unchanged; a degrade-provider emits a normal attachment +
   the log marker.

## Docs

On implementation, update `docs/email_system.md` with an "Inline / embedded images"
subsection: how to add one (`attachInlineData` with a bare `cid`, body references
`cid:<id>`), the per-transport support matrix, and the Resend/Brevo degrade note.
