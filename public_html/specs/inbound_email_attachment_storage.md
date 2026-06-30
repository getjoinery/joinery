# Inbound email — attachments as files (lean-record storage)

**Status:** Draft — design in progress
**Plugin:** `inbound_email` (with core `File`)
**Depends on:** `specs/implemented/file_private_storage.md` — attachments are stored as
**private `File` objects**, which requires `File` to offload to and serve from the
verified-private store. That capability must land first.
**Consumed by:** `specs/inbound_email_encryption_at_rest.md` and
`specs/joinery_ai_email_triage.md` — both currently assume attachment bytes live
inside the raw MIME blob; this spec moves them to discrete files and both will be
updated to consume the new shape (see *Interaction*).
**Scope:** **push transports only** (Postfix / Mailgun and any transport whose raw
is stored on the platform — `iem_raw_storage_driver` of `inline` / `local` /
`cloud`). **IMAP (`remote`) mail is unchanged** — its bytes stay on the IMAP
server and are fetched on demand exactly as today.

## Goal

A stored inbound message keeps every attachment **inside its raw RFC822 blob**,
base64-encoded; the `ima_` manifest is just a pointer to a MIME section, and
download and forward re-parse the raw to pull bytes back out. That single design
makes three things we want hard:

- **Encryption** has to seal a multi-megabyte raw blob that's mostly attachment
  binary, bloating whatever holds it.
- **Small-VPS storage** can't drain individual attachments to a bucket — the raw
  is one opaque object.
- **AI triage** has to MIME-parse a big raw every time it wants to look at an
  attachment.

This spec makes each attachment a **discrete file object** and keeps a **lean
record** of the message — headers + decoded text parts + the manifest — with the
attachment bytes living in exactly one place: a private `File`. No byte is stored
twice.

In plain terms: pull the attachments out of the email blob and store them as real
files, so they can be encrypted cleanly, pushed to a bucket, and read by the AI —
without keeping two copies.

## The model

- **The bytes are a private `File`.** Each non-text MIME part becomes one `File`
  row (restricted ⇒ private store ⇒ gated serving, per the dependency spec). The
  customer's bucket and the small-VPS drain come for free from the `File` machinery.
- **The message is a lean record.** Headers and the decoded text parts
  (`iem_body_plain` / `iem_body_html`, already columns) plus the manifest *are* the
  stored message. **No attachment-laden raw is retained.** To forward or download,
  the message is reconstructed from the text parts + manifest + `File` bytes.
- **The manifest is the glue.** The existing `ima_` row keeps the email-specific
  MIME metadata (content-type, encoding, content-id, inline flag) and gains a
  foreign key to its `File`. MIME semantics stay out of `File`; storage stays out
  of `ima_`.

This is the single decision everything else here follows from: **one home for the
bytes.** Extracting attachments to files *and* keeping the full raw would double
storage — so the raw's attachment bodies go away.

## What already exists (and is reused)

- **Private `File`** — bytes, per-row storage driver, bucket offload, and the
  permission-gated stream, from `implemented/file_private_storage.md`. Attachments are its
  first real email consumer.
- **The manifest** — `InboundMessageAttachment` (`ima_` rows): filename,
  content-type, size, MIME part, encoding, content-id, inline flag, with a
  cascade FK to its message. Reused; gains one column.
- **The ingest parse** — `InboundEmailRouter::writeManifestFromRaw()` already
  MIME-parses the inbound raw and enumerates every part to build the manifest.
  This is exactly where extraction hooks in — the parse is already happening.
- **`File::is_viewable()`** — reused as the attachment access gate (an attachment
  is exactly as private as its message; the grant check already mirrors the
  reader). The gated stream enforces it.
- **`RawMessageStore`** — the private `StorageProfile` for raw. Its role shrinks to
  near-nothing for new push mail (the lean record stores no raw blob); see
  *RawMessageStore's fate* below.

## What to build

### 1. The manifest links a `File`

Add to `InboundMessageAttachment`:

```php
'ima_fil_file_id' => array('type'=>'int8', 'is_nullable'=>true),
```

- **Present** ⇒ the bytes are a `File`; serve / read / re-attach through it
  (push mail).
- **Absent** ⇒ the bytes are remote; fetch by MIME section from IMAP (today's
  path, `remote` driver only).

The existing `ima_mime_part` / `ima_encoding` stay — they remain the locator for
IMAP rows and useful metadata for file rows. The presence of `ima_fil_file_id`,
not the transport, is what dispatch keys on.

### 2. Ingest: split attachments out, store the lean record

In the push ingest path, after the message is parsed (where
`writeManifestFromRaw()` runs today):

1. For **each non-text MIME part** (attachments *and* inline `cid:` parts):
   create a private `File` from the decoded bytes (filename, content-type from the
   part; owner = the mailbox/alias owner; restricted so it lands on the private
   store), then write the `ima_` row with `ima_fil_file_id` set. The `is_inline`
   flag is preserved as today.
2. Keep the **decoded text parts** in `iem_body_plain` / `iem_body_html` (already
   done) and the parsed header fields (already columns).
3. **Do not retain the attachment-laden raw.** Per the lean-record decision there
   is no stored full raw to re-emit; the text columns + manifest + `File` objects
   are the message. (Whether a headers-only stub is kept for archival is a minor
   sub-decision; default is none.)

Inline parts become files too, so the HTML body's `cid:` references resolve to the
attachment's **gated `File` URL** at render time (the reader rewrites `cid:` →
the gated serve path). Every non-text part has exactly one home; `is_inline` only
decides *list it* vs *render it inline*.

### 3. Reconstruction reads files, not raw

Three call sites stop re-parsing raw and read the `File` instead — dispatching on
`ima_fil_file_id`:

- **Download** (`admin_inbound_email_attachment_logic`): file-backed row ⇒ serve
  its `File` through the gated stream (the dependency spec's posture); `remote` row
  ⇒ IMAP fetch as today. The `getRawMimePart()` branch is no longer reached for
  push mail.
- **Forward** (`MailboxSender::attachOriginal`): file-backed source ⇒ re-attach by
  reading each manifest `File` (`attachFromManifest`); `remote` source ⇒
  `attachFromImap` as today. `attachFromRaw` is retired for push mail (no raw to
  replay). The outgoing message is built fresh regardless — forwarding already
  re-signs (DKIM/SRS), so byte-exact replay was never on the wire.
- **AI triage** (`joinery_ai_email_triage.md`): reads attachment bytes straight
  from the `File`, no MIME parse.

### 4. IMAP stays exactly as it is

For `remote` messages: no `File` extraction, `ima_fil_file_id` stays null,
`ima_mime_part` remains the locator, and download/forward keep fetching parts on
demand from the IMAP server. This spec adds a file-backed path **beside** the IMAP
path; it does not touch it.

### RawMessageStore's fate (decide at build time, don't pre-delete)

`RawMessageStore` exists to store and bucket-offload the **whole raw RFC822 blob** —
its entire purpose was carrying the message including its attachment bytes. Under the
lean-record model, **new push mail no longer calls `RawMessageStore::write()`**: there
is no retained raw, so the store has nothing to write or drain for new mail (the shared
`CloudOffloadRun` tick simply finds no raw rows to offload for it).

That leaves a deliberate fork to settle **when this is built, not now**:

- **Retire it** — if "no retained raw" holds, the store and its two offload tasks go
  dormant and can be removed once no `local`/`cloud` raw rows remain. (Pre-launch:
  there are none to strand.)
- **Repurpose it** — if a headers/text **stub** is ever kept (the deferred
  pruned-skeleton option), `RawMessageStore` is exactly the right private consumer to
  hold and offload that small stub, unchanged.

Either way, **do not delete `RawMessageStore` as part of this spec.** It is still the
live raw consumer until the lean-record ingest path replaces it, it is referenced by
`inbound_email_encryption_at_rest.md`'s background, and IMAP (`remote`) mail never
used it anyway. Removal is a separate, post-cutover cleanup.

## What does NOT change

- **The IMAP path** — manifest-as-section-pointer + on-demand fetch, untouched.
- **The `File` model** beyond being the byte home — it is consumed, not modified,
  here (its private-store work is the dependency spec).
- **Spam/auth filtering, threading, the reader UI, the manifest's existing
  fields** — reused. The reader's attachment list still reads `ima_` rows; it just
  links to a gated `File` URL.
- **`is_viewable()` / the grant check** — the attachment is as private as its
  message, exactly as today.

## Interaction with encryption and AI

This model is the foundation both feature specs were missing, and it **simplifies**
both:

- **Encryption** (`inbound_email_encryption_at_rest.md`): instead of sealing one
  giant raw blob, seal the small text columns and seal **each attachment `File`**
  in its store. This is precisely "keep the big binaries out of the encrypted
  database." That spec's "Raw MIME (incl. all attachment bytes) → RawMessageStore,
  sealed" line is superseded by "text columns sealed + per-attachment `File`
  sealed"; the gated `File` stream (dependency spec) is where in-session decryption
  hooks.
- **AI triage / scheduling** (`joinery_ai_email_triage.md`): reads decoded `File`
  bytes directly. And ingest is the single ordering hook where *both* the lean-record
  split *and* plaintext-time work (filters, and AI triage if it moves to ingest)
  happen before any sealing — the reconciliation of "encryption seals at rest" vs.
  "AI must read plaintext" lives at this one pipeline point.

Both specs will be updated to consume this model; the exact edits are theirs to
make, not this spec's.

## Security & cost

- **One copy of every byte.** Attachments live only as `File` objects; the raw no
  longer duplicates them. No storage doubling.
- **Private by construction.** Each attachment `File` is restricted ⇒ private
  store ⇒ gated stream; never a public URL, `is_viewable()` enforced per request
  (dependency spec).
- **Small-VPS drain.** Attachment files offload to the customer's private bucket
  on the normal cron tick and the local copy is deleted — the disk-fill problem
  this whole thread started from.
- **Bounded ingest cost** — extraction is the MIME parse the router already does
  to build the manifest, plus one `File` write per part. No new parse.

## Pre-launch / migration

The platform has no production users (`project_no_production_users`), so there is
**no existing mail to migrate**. New push mail uses the lean model going forward;
any dev-mailbox messages can be cleared. No backfill, no schema migration beyond
the additive `ima_fil_file_id` column (handled by `update_database` / plugin sync).

## Out of scope

- **IMAP attachment extraction to files** — `remote` mail keeps bytes on the IMAP
  server (this thread's explicit scope: "only the non-IMAP case").
- **Binary text extraction for AI** (PDF/image/Office → text) — a separate later
  spec; this one only makes attachment bytes available as files.
- **Byte-exact original `.eml` download / view-original** — given up with the
  lean-record decision; reconstruction is faithful-enough, not byte-identical. The
  pruned-skeleton alternative can be added later without disturbing consumers if
  archival fidelity is ever needed.
- **Sealing the files** — the encryption spec's job; named here only as the
  consuming interaction.

## Implementation outline (provisional)

1. **`ima_fil_file_id`** on `InboundMessageAttachment` (+ its Multi filter); sync
   schema. Dispatch helpers keying on its presence.
2. **Ingest split** — in the push path, extract each non-text part to a private
   `File` and write the manifest row linking it; keep text columns; stop retaining
   the attachment-laden raw.
3. **Download** — `admin_inbound_email_attachment_logic`: file-backed ⇒ gated
   `File` stream; `remote` ⇒ IMAP fetch (unchanged).
4. **Forward** — `MailboxSender::attachOriginal`: add `attachFromManifest` for
   file-backed sources; keep `attachFromImap`; retire `attachFromRaw` for push.
5. **Reader** — resolve inline `cid:` references to gated `File` URLs; attachment
   list links to the gated path.
6. `php -l` + `validate_php_file.php` on every modified PHP file; bump the
   `inbound_email` plugin version and touched core file versions.

## Docs

On implementation, update `plugins/inbound_email/docs/overview.md`
(current-state voice): the attachment section describes attachments as private
`File` objects linked from the manifest, served through the gated stream, with the
message stored as a lean record (text + manifest) and reconstructed for
forward/download; note IMAP attachments remain on-demand from the server. Cross-
reference `docs/cloud_storage.md` (private files) and the encryption spec.
