# Inbound email — attachments as files (lean-record storage)

**Status:** Draft — design in progress
**Plugin:** `inbound_email` (with core `File`)
**Depends on:**
`specs/implemented/file_private_storage.md` — attachments are stored as
**private `File` objects**, which requires `File` to offload to and serve from the
verified-private store; and
`specs/file_private_owner_admin.md` — each attachment is an **owner-or-admin private
`File`** (`fil_private`), so `is_viewable()` admits the mailbox owner and admins and no
one else; and
`specs/implemented/email_inline_attachments.md` — forwarding a message re-embeds its inline
images, which needs `EmailMessage` to carry inline (Content-ID) attachments. All three
capabilities must land first.

**Access model (decided):** an attachment `File` is **owner-or-admin private**
(`fil_private`): `is_viewable()` admits its owner (the mailbox owner) or any admin
(≥ 5), mirroring `authenticate_read`. This gives:
- **Individual mailboxes** work for any user, including **permission-0** owners — the
  owner sees their own attachments, no one else does.
- **Admins** can view any attachment (the documented coarse case).
- **Shared/team mailboxes require admin members** — a stated product policy and a
  documented limitation: a non-admin teammate can read a shared mailbox's *emails* (via
  `MailboxViewer`) but not its *attachments* (owner-or-admin only). Sharing among
  non-admin users is therefore not supported. Mailbox-level read access stays governed
  by `MailboxViewer`, separately and unchanged.
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
- **`File::is_viewable()` + `fil_private`** — the owner-or-admin private gate
  (dependency spec) is the attachment gate. An attachment sets `fil_private`; every
  consumer (serve.php, the download path, the reader) authorizes through the one
  `is_viewable()` algorithm, which admits the owner or an admin. No new gate, no
  `MailboxViewer` call at the file layer — mailbox read access is enforced separately,
  at email-read time (see *Access model*).
- **`RawMessageStore`** — the private `StorageProfile` for raw. Its role shrinks to
  near-nothing for new push mail (the lean record stores no raw blob); see
  *RawMessageStore's fate* below.

## What to build

### 0. Core helper — a `File` from bytes

The whole spec turns on "create a `File` from the decoded part," but `File` has **no
API for that today** — the only creation path is the upload pattern (write bytes to
`upload_dir` yourself, then `new File(NULL)` → `set()` → `save()`, where `save()` only
*moves* bytes already on disk). Attachment bytes are in memory (`$part->getContents()`),
not in `$_FILES`. So add one **general-purpose core helper** to `File`
(`data/files_class.php`) — not email-specific; the right home for any "mint a file from
generated/fetched bytes" caller:

```php
public static function createFromBytes(
    string $bytes,
    string $filename,        // original/display name, e.g. "invoice.pdf"
    string $content_type,
    int    $owner_id,        // fil_usr_user_id — the honest owner
    array  $restrictions = []   // restriction columns, e.g. ['fil_private' => true]
): File
```

It generates a **collision-free on-disk `fil_name`** (so two messages with
`invoice.pdf` don't clash), keeping `$filename` as the display title; writes the bytes
into `upload_dir`; applies the `$restrictions` columns; `save()`s; and returns the
`File`. **No `resize()`** (per the no-variants decision above). The array is explicit so
the access choice is visible at the call site — attachments pass `fil_private` (owner-or-admin).

The ingest split (§2) then becomes one line per part:

```php
$file = File::createFromBytes($part->getContents(), $name, $type, $owner_id,
    ['fil_private' => true]);   // owner-or-admin; mailbox read access is enforced at email-read time
$ima->set('ima_fil_file_id', $file->key);
```

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
   create a `File` from the decoded bytes (filename, content-type from the part) with
   `fil_private = true` — which makes it private (off the public bucket, onto the
   verified-private store) *and* makes `is_viewable()` admit owner-or-admin. Then write
   the `ima_` row with `ima_fil_file_id` set. The `is_inline` flag is preserved as today.

   **Owner** (`fil_usr_user_id`) = the mailbox/alias owner when there is one;
   **`User::USER_SYSTEM`** only when there isn't (a catch-all or otherwise ownerless
   alias). With `fil_private`, the owner *is* the access subject (plus admins): a
   permission-0 individual-mailbox owner sees their own attachments via the owner match.
   For an ownerless catch-all, `USER_SYSTEM` matches no human, so only admins see its
   attachments — appropriate for an ops/catch-all mailbox. `User::USER_SYSTEM` (id 2) is
   the reserved non-human account, the same sentinel family `File` already uses for
   orphaned files (`User::USER_DELETED`).

   **Attachment bytes are stored as-is — no image
   resizing or thumbnail variants are generated** (unlike uploaded photos, which
   call `File::resize()`). Email attachments are served only as their original; the
   resize step costs CPU and disk on every ingest, which is exactly the small-VPS
   pressure this design relieves.
2. Keep the **decoded text parts** in `iem_body_plain` / `iem_body_html` (already
   done) and the parsed header fields (already columns).
3. **Do not retain the attachment-laden raw — on success.** When every part
   extracts cleanly, there is no stored full raw to re-emit; the text columns +
   manifest + `File` objects are the message. (Whether a headers-only stub is kept
   for archival is a minor sub-decision; default is none.) The failure path is
   different — see *Ingest failure* below.

Inline parts become files too — one home for the bytes, and `ima_is_inline` /
`ima_content_id` are recorded so they can be rendered later. **Rendering inline images
in the admin reader is a separate follow-up** (`inbound_email_reader_inline_images.md`),
not built here: the reader is authenticated, so it resolves `cid:` to a grant-checked
inline-serve URL rather than embedding bytes. Storing the parts as files is all this
spec needs; `is_inline` only records the list-vs-inline intent that follow-up consumes.

### Ingest failure: the raw is the fallback

The lean-record model means an attachment's bytes have exactly one home — so if a
`File` write fails mid-ingest (disk full — the very pressure this design relieves),
that one home is gone and there is no raw to recover from. The safety net: **the raw
is already in memory** (it is the input to `processEmail()`), so it costs nothing to
fall back to persisting it.

The rule is **all-or-nothing per message**:

- **Happy path** — every non-text part extracts to a `File`: link each in the
  manifest, do **not** persist the raw.
- **Failure path** — any part fails: roll back the `File`s created for this message,
  then fall back to today's exact behavior — `RawMessageStore::write()` the raw and
  leave the manifest as section-pointers (no `ima_fil_file_id`). Download/forward then
  take their `getRawMimePart()` branch for this message, as they do for legacy rows.
  If the raw write also fails, today's final fallback (inline raw in
  `iem_raw_message`) still applies.

So the full degradation chain is **lean record → raw-to-disk → inline-in-DB**; the
last two levels already exist. **Ingest never aborts** — a message always lands, in
whichever of the three shapes succeeded. The fallback is logged (a distinct marker)
so an operator sees disk pressure when it starts happening, rather than discovering it
silently.

This is why `RawMessageStore` is **kept, not retired** — see below.

### 3. Reconstruction reads files, not raw

Three call sites stop re-parsing raw and read the `File` instead — dispatching on
`ima_fil_file_id`:

- **Download** (`admin_inbound_email_attachment_logic`): authorize with
  **`File::is_viewable($session)`** — the one algorithm — which admits owner-or-admin via
  `fil_private` (serve.php's generic `/uploads/*` path would authorize a
  file-backed attachment identically). The endpoint stays for presentation: for a
  file-backed row it reads the attachment's `File` bytes
  (not `getRawMimePart()`) and streams with the **original `ima_filename`** + attachment
  disposition + nosniff; `remote` row ⇒ IMAP fetch as today. The `getRawMimePart()`
  branch is no longer reached for push mail.
- **Forward** (`MailboxSender::attachOriginal`): **one manifest-driven loop**, not a
  per-transport branch. For each manifest row, fetch its bytes by *where they live* —
  `ima_fil_file_id` set ⇒ read the `File`; else `remote` ⇒ `ImapIngestor::fetchPart()`;
  else (legacy raw row) ⇒ `getRawMimePart(ima_mime_part)` — then re-attach. This is the
  same per-row dispatch download uses, so `attachFromRaw`, `attachFromImap`, and the
  message-level driver branch all collapse into this single loop; `attachFromRaw` is
  **deleted** (legacy raw rows are covered per-row by `getRawMimePart`).

  **Inline parts are re-embedded, not flattened.** A row with `is_inline=true` is
  re-attached *inline* with its original Content-ID via
  `EmailMessage::attachInlineData(bytes, ima_content_id, …)`, so the forwarded HTML
  body's `cid:` references still resolve in the recipient's client; `is_inline=false`
  rows attach normally. This requires the inline-embed capability — see the
  `email_inline_attachments.md` dependency. (URL-rewriting `cid:` is *not* viable on a
  forward: the recipient is external/unauthenticated and could not load a gated URL.)
  The outgoing message is built fresh regardless — forwarding already re-signs
  (DKIM/SRS), so byte-exact replay was never on the wire.
- **AI triage** (`joinery_ai_email_triage.md`): reads attachment bytes straight
  from the `File`, no MIME parse.

### 4. IMAP stays exactly as it is

For `remote` messages: no `File` extraction, `ima_fil_file_id` stays null,
`ima_mime_part` remains the locator, and download/forward keep fetching parts on
demand from the IMAP server. This spec adds a file-backed path **beside** the IMAP
path; it does not touch it.

### RawMessageStore is kept — as the ingest fallback

`RawMessageStore` exists to store and bucket-offload the **whole raw RFC822 blob**.
Under the lean-record model the **happy path no longer calls `RawMessageStore::write()`** —
a clean message stores no raw, so the store has nothing to write or drain for it (the
shared `CloudOffloadRun` tick simply finds no raw rows to offload).

But it is **kept, not retired**: it is the holder for the *Ingest failure* path above.
When extraction fails, the message falls back to a persisted raw, and `RawMessageStore`
is exactly the private consumer that stores and offloads that raw — unchanged. So it is
dormant on the happy path and active only on fallback.

This settles the fork the earlier draft left open: **keep it.** (It is also still
referenced by `inbound_email_encryption_at_rest.md`'s background, and IMAP (`remote`)
mail never used it anyway.) If a deliberate headers/text **stub** is ever introduced,
`RawMessageStore` is also the right consumer to hold it — but that remains out of scope
here; its only role this spec relies on is the failure fallback.

## What does NOT change

- **The IMAP path** — manifest-as-section-pointer + on-demand fetch, untouched.
- **The `File` model** beyond being the byte home — it is consumed, not modified,
  here (its private-store work is the dependency spec).
- **Spam/auth filtering, threading, the reader UI, the manifest's existing
  fields** — reused. The reader's attachment list still reads `ima_` rows; it just
  links to a gated `File` URL.
- **Mailbox-level access** (`MailboxViewer`, who can read which mailbox's emails) —
  unchanged. It governs the *reader and email access*, not the attachment `File`, which
  is gated independently by `fil_private` (owner-or-admin). Attachment access can be
  coarser than email access for a shared mailbox (any admin) — the accepted tradeoff and
  the reason team mailboxes require admin members (see *Access model*).

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
- **Private by construction.** Each attachment `File` carries `fil_private` (owner-or-admin)
  ⇒ private store ⇒ gated stream; never a public URL, and `is_viewable()` is enforced per
  request via the one authorization algorithm (dependency spec).
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

1. **`File::createFromBytes()`** — add the core helper (collision-free on-disk name,
   write bytes, apply restriction columns, `save()`, no `resize()`). Lands with the
   dependency core-File work.
2. **`ima_fil_file_id`** on `InboundMessageAttachment` (+ its Multi filter); sync
   schema. Dispatch helpers keying on its presence.
3. **Ingest split** — in the push path, extract each non-text part to a `File` via
   `createFromBytes()` with `fil_private = true` (+ owner = mailbox owner / `USER_SYSTEM`),
   write the manifest row linking it; keep text columns; on success stop retaining the
   raw; on any failure fall back to the raw (all-or-nothing per message), never abort.
4. **Download** — `admin_inbound_email_attachment_logic`: authorize via
   `File::is_viewable()` (owner-or-admin); file-backed ⇒ read the `File`'s bytes and
   stream with the original filename; `remote` ⇒ IMAP fetch (unchanged).
5. **Forward** — `MailboxSender::attachOriginal`: collapse to one manifest-driven
   loop dispatching per row (File / IMAP / legacy raw-part); delete `attachFromRaw`.
   Re-embed `is_inline` rows via `attachInlineData()` (dependency:
   `email_inline_attachments.md`); attach the rest normally.
6. **Reader** — the attachment list (the `is_inline=false` rows) links to the
   download endpoint. Inline-image rendering (`cid:` resolution) is **not** here — it
   is the follow-up `inbound_email_reader_inline_images.md`.
7. `php -l` + `validate_php_file.php` on every modified PHP file; bump the
   `inbound_email` plugin version and touched core file versions.

## Docs

On implementation, update `plugins/inbound_email/docs/overview.md`
(current-state voice): the attachment section describes attachments as private
`File` objects linked from the manifest, served through the gated stream, with the
message stored as a lean record (text + manifest) and reconstructed for
forward/download; note IMAP attachments remain on-demand from the server. Cross-
reference `docs/cloud_storage.md` (private files) and the encryption spec.
