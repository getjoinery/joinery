# Inbound Email — Encryption at Rest (Sealed Bodies + Sealed Search Index)

**Status:** Draft / awaiting implementation
**Version:** 1.1
**Builds on:** `specs/implemented/inbound_email_attachment_storage.md` (attachments are
discrete private `File` objects, not bytes inside a raw blob) and
`specs/implemented/file_private_storage.md` (private `File` offload + gated serving). This spec
assumes both are in place: there is **no attachment-laden raw to seal** — content
columns are sealed in the row and each attachment is sealed as a `File`.
**Supersedes:** `specs/implemented/inbound_email_fulltext_search.md` (the plaintext
`to_tsvector` GIN index it built is removed by this spec — see *Integration Points*).

## Goal

Encrypt the human-readable content of stored inbound mail so that the contents are
not recoverable from the database, the raw-message store, or the box's filesystem
**while the user is not actively logged in** — without losing full-text search.

The product framing is a single-user, ProtonMail-style mailbox: one operator who is
also the only reader. That constraint is what makes strong (asymmetric, key-absent-
at-rest) encryption affordable here — there is no need for the server to read stored
mail on behalf of other users or run server-side processing over the archive.

## Threat Model — what this defends, and what it does not

**Defends against (the reason for the design):**

- **Stolen database / leaked backup / SQL-injection read** — body, subject, sender,
  attachment files, and the search index are all ciphertext at rest.
- **Offline box compromise** — an attacker who gains code execution or filesystem
  access *while no session is active* finds no key capable of decryption. Inbound
  mail is sealed to a **public** key; the matching private key is never on disk in
  usable form and is only ever unwrapped into memory during an authenticated session.

**Explicitly accepted residual (do not paper over this — it is the design boundary):**

- **Active-session exposure.** While the user is logged in, the unwrapped private
  key, the decrypted search index, and any opened message bodies live in RAM. An
  attacker who compromises the box *during* an active session can read what is
  decrypted in that window. This is the inherent floor for a server-rendered reader
  and is the accepted tradeoff, not a bug to be fixed later.

### Alternative: client-side key handling (deferred — recorded, not chosen)

The active-session exposure above exists only because decryption happens on the
server. A ProtonMail-style alternative moves it to the browser: the passphrase is
entered client-side, the private key is unwrapped and messages are decrypted in
JavaScript (libsodium.js / WASM — WebCrypto lacks Argon2id and sealed boxes), and the
server stores and serves **only** ciphertext. The unwrapped key and plaintext never
touch server RAM.

- **What it strengthens.** Removes the server-side active-session window: a server
  compromised *at rest or passively* cannot read mail, which is the real zero-access
  guarantee and a stronger product claim.
- **The honest caveat.** It is not absolute. A compromised server can still serve
  backdoored decryption JavaScript that exfiltrates the key or plaintext at decrypt
  time. The guarantee is "the server cannot read stored mail," not "the server can
  never be made to read mail" — the inherent limit of browser-delivered crypto. Worth
  stating plainly rather than overselling.
- **The cost (why it is deferred, not default).** It is a much larger build that cuts
  against this platform's server-rendered, vanilla-JS grain:
  - **Search must move.** The sealed-FTS5-in-`/dev/shm` design assumes the *server*
    can decrypt fields to build and query the index. Client-side decryption forces
    either (a) shipping the sealed index to the browser and running tokenization +
    search there (WASM SQLite or a JS index), or (b) falling back to a server-side
    **blind index** with its bounded word-presence leak. This is a fork of the search
    architecture, not a tweak.
  - **Rendering, sanitization, previews, and reply-quoting** all move client-side and
    operate on browser-decrypted content (HTML sanitization in the browser is
    security-sensitive).
  - The reader effectively becomes a crypto SPA with a WASM dependency — a significant
    departure from the rest of the platform.

Net: a stronger guarantee at a materially larger and more divergent build. Captured
here so the tradeoff is on record; the server-side model above remains the chosen
baseline unless we decide the stronger guarantee is worth the rebuild.

## Architecture Overview

1. **At ingest** (user may be offline): parse the message, run filters/rules on the
   plaintext, then **seal** all content fields to the user's public key and store the
   ciphertext. No key that can decrypt is present on the box.
2. **At unlock**: an unlocker (passkey PRF assertion, recovery code, or optional
   passphrase — see *Key Hierarchy*) unwraps the private key into session-scoped
   RAM, opening an **unlock window** (see *The Unlock Window* below). The
   sealed search index is decrypted to `tmpfs` (`/dev/shm`), and any messages
   received since the last login are folded into it incrementally.
3. **Search**: queries run against the in-RAM SQLite FTS5 index and return matching
   message ids; the existing thread list query then groups/sorts/pages those ids
   using cleartext operational metadata in PostgreSQL.
4. **Render**: opening a thread decrypts only the messages displayed.
5. **At unlock expiry, logout, or session expiry** (whichever comes first): the
   index is re-sealed, `tmpfs` copies and the in-memory private key are wiped.

### The Unlock Window (how long "unlocked" lasts)

Being **logged in** and being **unlocked** are two different states. A web
session can legitimately live for days; if the keys lived as long as the
session, the "active-session exposure" residual accepted throughout these
specs would be effectively permanent and the at-rest guarantee hollow. So:

- **Logging in** (password or passkey sign-in) gives the normal session —
  every non-mail feature, and the mailbox's cleartext surface (thread
  structure, counts, labels, folders). Sealed content is not readable.
- **Unlocking** is a separate, deliberate act — one passkey tap (or recovery
  code / passphrase) — that opens a window in which keys are in RAM and mail
  is readable, searchable, and (on Fortress domains) sendable.
- The window closes after a configurable **idle timeout of mailbox activity**
  (single setting, default 30 minutes; mail actions reset it), and closing it
  wipes everything unlock created: the unwrapped secret key, the `/dev/shm`
  index (re-sealed first), and any decrypted state. Logout and session expiry
  close it too, but they are no longer the only thing that does.
- **No hard cap on a busy window.** An idle cap bounds a hijacked-session or
  walked-away-from-desk exposure; a hard cap would only interrupt a user who
  is demonstrably present, and defends nothing — an attacker already on the
  box during a window has the key regardless of when the window would end.
- The passkey is what makes a short window affordable: re-unlocking is a
  fingerprint tap, not a passphrase retype. A 30-minute idle default with
  one-tap reopen is a dramatically smaller exposure than a days-long session,
  at near-zero UX cost.

Everywhere the mail specs say "at login" / "in-session" (the index fold,
deferred ingest, the key-gated AI poll, session-gated signing), the precise
meaning is **within an unlock window** — unlock is the event that starts that
work, and the window is the only time it can run.

## Key Hierarchy & Cryptography

All primitives are libsodium (`ext-sodium`, bundled with PHP 8.3). Envelope encryption
keeps the asymmetric op to once per message rather than once per field.

- **Unlockers → KEK wrappings.** The secret key is wrapped once per enrolled
  unlocker; any single unlocker opens it. Enrolling or removing an unlocker is an
  in-session act (it needs the unwrapped secret key in hand). Three kinds:
  - **Passkey (the primary, default UX).** A PRF-capable WebAuthn credential
    derives a 32-byte KEK inside the authenticator hardware on a touch/face
    check — nothing to memorize, and the ingredient never rests on the server.
    Provided by the core passkey service (`specs/passkeys_core.md`, context
    `mail-kek`). PRF outputs are **per-credential**, so each enrolled passkey
    holds its own wrapping; revoking a passkey deletes its wrapping.
  - **Recovery codes (required backup).** A printed list of high-entropy
    one-time codes generated at setup, each independently wrapping the secret
    key (a code is key material, not a server-verified check — that is why
    codes work where TOTP-style 2FA structurally cannot). A used code's
    wrapping is deleted.
  - **Passphrase (optional).** `sodium_crypto_pwhash` (Argon2id) derives a
    32-byte KEK from a memorized passphrase and a stored per-user salt, for
    operators who want a knowledge factor in addition to possession factors.
- **User keypair.** An X25519 keypair (`sodium_crypto_box_keypair`). The **public**
  key is stored in cleartext and used at ingest. The **secret** key is wrapped with
  `crypto_secretbox` under each unlocker's KEK and stored at rest; it is only ever
  unwrapped into RAM during a session.
- **Per-message data key (DEK).** Each message gets a random 32-byte DEK. Content
  fields are encrypted with `crypto_secretbox` under the DEK. The DEK is sealed to the
  user's public key with `sodium_crypto_box_seal` and stored alongside the message.
  - Ingest needs only the public key (seal the DEK).
  - Read needs the secret key: `crypto_box_seal_open(DEK)` → decrypt fields.
- **Key loss.** Losing **every** enrolled unlocker — all passkey devices, all
  unused recovery codes, and the passphrase if set — makes all mail permanently
  unreadable. State this plainly in the setup UI; the recovery-code step
  requires explicit acknowledgment before it can be dismissed.

### Key storage & in-memory rules

- The unwrapped secret key **must never** be written to the disk- or DB-backed session
  store. Hold it in shared RAM (APCu) keyed to the session id with a TTL matching the
  unlock window's idle timeout, or equivalent process memory — never persisted.
  Cleared when the unlock window closes (idle expiry, logout, or session expiry).
- The decrypted FTS index file lives in `/dev/shm` (tmpfs, RAM-backed), never on a
  persistent disk path. Re-sealed and wiped when the unlock window closes.

## What Is Sealed vs. What Stays Cleartext

**Sealed at rest (content):**

| Field | Location |
|---|---|
| `iem_body_plain`, `iem_body_html` | message row (columns hold ciphertext blobs) |
| `iem_subject`, `iem_sender` | message row |
| Attachment file **bytes** | each attachment `File` — sealed in its private store (disk/bucket) before write |
| `ima_filename` (and the `File`'s display name) | attachment / file row |

There is **no retained raw-MIME blob** (the lean-record model strips attachment
bodies out of the raw at ingest — see `inbound_email_attachment_storage.md`), so the
old "seal the whole raw via `RawMessageStore`" step is gone. Instead the small text
columns are sealed in the row and each attachment is sealed as its own `File`. This
is the concrete "keep big binaries out of the encrypted database" win: the sealed
DB payload is just text; the heavy bytes are sealed objects in the file store.

**Cleartext (operational metadata the server needs while the user is logged out — to
receive, dedupe, thread, sort, page, and list):**

- Routing: `iem_recipient`, `iem_iea_inbound_email_alias_id`
- Threading/dedup: `iem_message_id_header`, `iem_thread_key`
- State/sort: `iem_is_read`, `iem_read_time`, `iem_received_time`, `iem_size_bytes`,
  `iem_imap_folder`, `iem_direction`, labels, folder
- Attachment structure: `ima_content_type`, `ima_size_bytes`, `ima_mime_part`,
  `ima_encoding`, `ima_content_id`, `ima_is_inline` (filename is sealed; these are not
  content)

`iem_message_id_header` and `iem_thread_key` are opaque header tokens, not body
content; keeping them cleartext is what lets conversation threading keep working
server-side without the key (threading already groups on `iem_thread_key`, never on
subject — see `MailboxService::GROUP_KEY_SQL`).

### Sent mail and drafts seal under the same model

Mail the user writes holds the same secrets as mail the user receives, and both
are stored in the same message table — so outbound (`iem_direction`) rows and
drafts seal identically: content fields and attachment bytes under a per-message
DEK sealed to the public key. No new mechanism, and no new key-availability
problem either: composing, autosaving a draft, and sending all happen inside an
unlock window by construction, so the sealing key's public half is all that's
ever needed and the plaintext is only ever in session RAM.

The cleartext/sealed split follows one rule that covers both directions: **the
counterparty is sealed; the user's own address is cleartext.** Inbound already
works this way (`iem_sender` sealed, `iem_recipient` — the user's own alias —
cleartext for routing). Outbound mirrors it: the recipient addresses seal as
content; the sending alias stays cleartext. Sent messages fold into the FTS
index like received ones (the fold is in-window regardless of direction);
drafts stay out of the index — they are few, in-flux, and always opened
directly rather than searched for.

## Search Architecture

**Index source (built in-session from decrypted fields):** body plain + body html +
subject + sender + attachment filenames. **Attachment content is never indexed.**
Consequence to surface in UI expectations: search does not find text inside a
PDF/Word attachment — only the email's own text and the attachment's filename.

**Engine:** SQLite **FTS5**. Chosen after confirming the SQLite engine is already
installed on the box (`libsqlite3-0`, with FTS5 compiled in — symbols present) and only
the ~144KB `php8.3-sqlite3` PHP binding is missing. FTS5 provides a maintained
tokenizer, Porter stemming, BM25 ranking with per-column weighting, phrase/proximity/
prefix queries, and snippet generation — none of which we then own and maintain.

**At-rest form:** the FTS5 database is a single file, envelope-encrypted under the
user's key (its own DEK sealed to the public key), stored as a sealed blob.

**Incremental maintenance (the sealed append-log model):** a cleartext high-water mark
records the id of the last message folded into the index. At login, after the index is
decrypted to `/dev/shm`, the manager selects messages with id > high-water, decrypts
their indexable fields, inserts them into FTS5, and advances the mark. Inbound mail
arriving while logged out is simply sealed and stored; it is folded in at the next
login. First-ever login pays a one-time full backlog index build.

**Query path:** a search term is run against the in-RAM FTS5 index, returning matching
message ids. Those ids are passed into the existing `listThreads()` query as an id
whitelist (`iem_inbound_email_message_id IN (...)`); PostgreSQL still does thread
grouping, sorting, and pagination on cleartext metadata. Text matching moves to FTS5;
the two join on the message id. Subject/sender/body columns are no longer touched by
the SQL search predicate.

## Ingest Pipeline (ordering is load-bearing)

Filters and rules (`inbound_email_filter_class`) match on plaintext sender/subject/
body and **must run before sealing**. Required order:

1. Receive + parse the message.
2. Run filters/rules on the parsed plaintext.
3. **Split attachments out** to private `File` objects (per
   `inbound_email_attachment_storage.md`) — this is the same ingest moment, and it
   must happen while the plaintext bytes are still in hand.
4. Generate the per-message DEK; encrypt the content columns; encrypt each
   attachment `File`'s bytes under the **same** per-message DEK; seal the DEK to the
   public key.
5. Persist the **lean record**: ciphertext content columns + cleartext metadata +
   the manifest linking the (now-sealed) attachment `File`s. **No raw blob is stored.**

## Read / Render Flow

- Thread list previews can no longer be produced in SQL (`left(iem_body_plain, …)`).
  After the rows are fetched, decrypt the latest message's body in PHP and take the
  preview substring. Previews therefore only render inside an authenticated session.
- Opening a thread decrypts only its messages. Reply-quoting (`MailboxSender`)
  decrypts the quoted body in-session.
- **Downloading an attachment** decrypts in-session inside the gated `File` stream
  (`implemented/file_private_storage.md`): after `is_viewable()` passes, the sealed bytes are
  fetched and decrypted under the message DEK, then streamed. Forwarding
  (`MailboxSender` re-attach) decrypts each attachment `File` in-session the same
  way. Attachments are therefore only retrievable inside an authenticated session,
  exactly like bodies.

## No Sideways Copies: Admin Surfaces, Logs, and Transcripts

Sealing the content columns is pointless if content dribbles out through
operational surfaces. One rule, enforced at the boundaries: **message content
persists nowhere except the sealed columns and sealed attachment `File`s.**
Everything else stores references and metadata.

- **The inbound email log viewer** logs metadata only — recipient, message-id,
  verdicts, sizes, routing outcomes — never subject or body fragments. This is
  a global rule, not a per-level branch: it costs nothing on Standard and
  removes a whole audit class everywhere.
- **Error paths redact.** Ingest and send exceptions are caught at the
  pipeline boundary and logged with the message *reference*, never with raw
  MIME or field content in the exception text (the Apache error log is
  verbose, unsealed, and long-lived — treat it as public within the box).
- **The admin message viewer** shows cleartext metadata always; sealed fields
  render only inside an unlock window, through the same decrypt path as the
  mailbox reader. The gate is **key possession, not permission level**: a
  permission-10 admin without an enrolled unlocker — including one arriving
  via login-as — sees ciphertext, structurally. There is no admin override,
  because there is no key to override with.
- **AI artifacts inherit the rule.** The pipeline processing log stores
  message references and verdicts, never digest text; content-derived outputs
  (e.g. `iem_ai_summary`) seal as content per the security-levels spec; any
  transcript that would embed protected message text is either sealed the
  same way or not persisted.

## Integration Points That Change

- **`MailboxService::listThreads()`** — replace the `to_tsvector(...) @@
  websearch_to_tsquery(...)` predicate with the FTS5 id-whitelist join; replace the
  `left(coalesce(iem_body_plain/html, …))` preview aggregates with in-PHP decryption.
- **`data/inbound_email_message_class.php::getMultiResults()`** — remove the `ILIKE`
  filters on `iem_subject` / `iem_body_plain` / `iem_body_html` / `iem_sender`
  (lines ~300–326); text search no longer goes through SQL.
- **GIN expression index** from `inbound_email_fulltext_search.md` — dropped (migration
  to remove it).
- **`inbound_email_filter_class`** — unchanged logic, but the ingest pipeline must call
  it before sealing (see above). Filtering over *stored* messages is no longer possible
  without the key.
- **`MailboxSender`** — quoted-body construction decrypts in-session; forward
  re-attach reads each attachment `File`, decrypting under the message DEK in-session.

## New Code

- **Sealing helper** — a sibling to `SecretBox` (which stays symmetric-only). Wraps
  `crypto_box_seal` / `crypto_box_seal_open` plus the per-message DEK envelope. Keeps
  the asymmetric-at-ingest contract in one tested place. The same helper encrypts and
  decrypts attachment `File` bytes under the message DEK, so the gated `File` stream
  has one tested path to open them in-session.
- **Index manager** — owns the `/dev/shm` lifecycle: decrypt-on-login, incremental
  fold via the high-water mark, query, re-seal-on-logout, wipe.

## Schema Changes (via data-class `$field_specifications`, not migrations)

- Message row: a column for the sealed per-message DEK (e.g. `iem_sealed_key`, text).
  Content columns continue to hold text but now store ciphertext blobs.
- Per-user key material: salt, sealed secret key (passphrase-wrapped), sealed secret
  key (recovery-wrapped), public key, FTS index high-water mark. New table or columns
  on the user/mailbox-owner record.
- The sealed FTS index blob: stored as a file (sealed) or a row; decide during
  implementation based on size.
- Attachment `File`s hold ciphertext bytes in their private store. No per-file key
  column is needed: an attachment is encrypted under its **message's** DEK, so the
  gated stream resolves the owning message (via the manifest) and unwraps
  `iem_sealed_key` to decrypt. The general `File` stream calls an email-supplied
  decrypt hook; `File` itself stays crypto-agnostic.

Schema/data-type changes go through `update_database` / plugin sync, never hand-written
DDL.

## Deployment

- Add `php8.3-sqlite3` to provisioning and the Docker images. The SQLite engine
  (`libsqlite3-0`, FTS5 enabled) is already present on dev; **verify the production
  base image carries `libsqlite3` with FTS5** before rollout.
- No Apache/PHP config changes beyond the extension.

## Pre-Launch / Backfill

The platform has no production users yet, so there is no existing mail to preserve. New
mail is sealed going forward. Any existing dev-mailbox plaintext can be sealed by a
one-time in-session backfill pass (it needs the key, so it runs once the owner logs in
and sets a passphrase) or simply cleared. A `looksEncrypted()`-style marker lets a row
be detected as already-sealed for an idempotent backfill.

## Backups

Sealing changes what a backup is worth to an attacker and what it costs the
user to lose — in opposite directions:

- **A leaked backup no longer exposes mail content.** Bodies, subjects,
  senders, attachments, and the search index are ciphertext in the database
  and file store, so backups can be shipped to any offsite target (the
  server_manager backup targets: B2/S3/etc.) without the backup itself being
  a second copy of the readable archive. State the honest limit: cleartext
  operational metadata (who mails you, when, thread shapes, sizes) *is* in a
  backup; content is not.
- **A *lost* backup becomes more expensive.** The wrapped-key rows (salt,
  public key, and the secret key's wrapping per enrolled unlocker) are the
  one thing whose loss is unrecoverable even with every passkey and recovery
  code in hand — unlockers open the wrapped blob; they cannot recreate it.
  Rules: key-material tables are never excluded from backup sets; the private
  file store (sealed attachment bytes) is backed alongside the database, or
  attachments outlive nothing; the sealed FTS index is the only exempt
  artifact (rebuildable from a backlog fold).
- **Key file export.** Because wrapped key material is ciphertext, useless
  without an unlocker, the setup ceremony offers it as a small downloadable
  file alongside the recovery codes — safe to keep on a USB stick or in any
  cloud drive. A total-loss restore is then: reinstall, import key file (or
  restore any DB backup), unlock with any enrolled unlocker. Passkeys survive
  server loss by nature — the PRF ingredient lives in the authenticator
  hardware, not on the server.

## Recovery & Key Loss

- Lost/replaced passkey device → unlock with any remaining unlocker (another
  passkey, a recovery code, the passphrase), then enroll the new device and
  revoke the old credential's wrapping.
- Down to the last few recovery codes with no passkey → the mailbox UI warns and
  prompts to enroll a passkey or regenerate a fresh code list (in-session).
- All unlockers lost → mail is unrecoverable, by design. The setup flow makes
  this explicit before the recovery codes can be dismissed.

## Documentation to Update

- `plugins/inbound_email/docs/overview.md` — add an "Encryption at rest" section
  describing the sealed-content model, what stays cleartext and why, and the sealed
  search-index lifecycle (current-state only; do not narrate the FTS index it replaces).
- `docs/secret_box.md` — cross-reference the new sealing helper as the asymmetric
  companion to `SecretBox`.

## Open Items to Confirm During Implementation

- Confirm `sodium_crypto_box_seal` / `_open` are available (ext-sodium present per
  `SecretBox`).
- Confirm APCu (or an equivalent RAM-only store) is available for holding the unwrapped
  secret key off the persistent session store; choose the mechanism explicitly.
- Confirm the production Docker base carries `libsqlite3` + FTS5.
- Decide sealed-index storage form (file vs row) against real index size once attachment-
  excluded text volume is measured.
- Confirm the gated `File` stream (`implemented/file_private_storage.md`) can carry an
  email-supplied decrypt hook that resolves the owning message's DEK at serve time,
  keeping `File` crypto-agnostic while still decrypting sealed attachments in-session.
