# Inbound Email — Encryption at Rest (Sealed Bodies + Sealed Search Index)

**Status:** Draft / awaiting implementation
**Version:** 1.0
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
  raw MIME, attachments, and the search index are all ciphertext at rest.
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
2. **At login**: the passphrase unwraps the private key into session-scoped RAM. The
   sealed search index is decrypted to `tmpfs` (`/dev/shm`), and any messages
   received since the last login are folded into it incrementally.
3. **Search**: queries run against the in-RAM SQLite FTS5 index and return matching
   message ids; the existing thread list query then groups/sorts/pages those ids
   using cleartext operational metadata in PostgreSQL.
4. **Render**: opening a thread decrypts only the messages displayed.
5. **At logout / session expiry**: the index is re-sealed, `tmpfs` copies and the
   in-memory private key are wiped.

## Key Hierarchy & Cryptography

All primitives are libsodium (`ext-sodium`, bundled with PHP 8.3). Envelope encryption
keeps the asymmetric op to once per message rather than once per field.

- **Passphrase → KEK.** `sodium_crypto_pwhash` (Argon2id) derives a 32-byte
  key-encryption-key from the user's passphrase and a stored per-user salt.
- **User keypair.** An X25519 keypair (`sodium_crypto_box_keypair`). The **public**
  key is stored in cleartext and used at ingest. The **secret** key is wrapped with
  `crypto_secretbox` under the KEK and stored at rest; it is only ever unwrapped into
  RAM during a session.
- **Per-message data key (DEK).** Each message gets a random 32-byte DEK. Content
  fields are encrypted with `crypto_secretbox` under the DEK. The DEK is sealed to the
  user's public key with `sodium_crypto_box_seal` and stored alongside the message.
  - Ingest needs only the public key (seal the DEK).
  - Read needs the secret key: `crypto_box_seal_open(DEK)` → decrypt fields.
- **Recovery key.** The secret key is wrapped a **second** time under a random 32-byte
  recovery key shown to the user once at setup for offline storage. Either the
  passphrase or the recovery key can unwrap the secret key. **Losing both makes all
  mail permanently unreadable** — state this plainly in the setup UI.

### Key storage & in-memory rules

- The unwrapped secret key **must never** be written to the disk- or DB-backed session
  store. Hold it in shared RAM (APCu) keyed to the session id with a TTL, or
  equivalent process memory — never persisted. Cleared on logout/expiry.
- The decrypted FTS index file lives in `/dev/shm` (tmpfs, RAM-backed), never on a
  persistent disk path. Wiped on logout/expiry.

## What Is Sealed vs. What Stays Cleartext

**Sealed at rest (content):**

| Field | Location |
|---|---|
| `iem_body_plain`, `iem_body_html` | message row (columns hold ciphertext blobs) |
| `iem_subject`, `iem_sender` | message row |
| Raw MIME (incl. all attachment bytes) | `RawMessageStore` — sealed before write to disk/bucket |
| `ima_filename` | attachment row |

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

## Search Architecture

**Index source (built in-session from decrypted fields):** body plain + body html +
subject + sender + attachment filenames. **Attachment content and raw MIME are never
indexed.** Consequence to surface in UI expectations: search does not find text inside
a PDF/Word attachment — only the email's own text and the attachment's filename.

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
3. Generate the per-message DEK; encrypt content fields; seal the DEK to the public key.
4. Seal the raw MIME and write it via `RawMessageStore`.
5. Persist the row with ciphertext content fields + cleartext metadata.

## Read / Render Flow

- Thread list previews can no longer be produced in SQL (`left(iem_body_plain, …)`).
  After the rows are fetched, decrypt the latest message's body in PHP and take the
  preview substring. Previews therefore only render inside an authenticated session.
- Opening a thread decrypts only its messages. Reply-quoting (`MailboxSender`)
  decrypts the quoted body in-session.

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
- **`MailboxSender`** — quoted-body construction decrypts in-session.

## New Code

- **Sealing helper** — a sibling to `SecretBox` (which stays symmetric-only). Wraps
  `crypto_box_seal` / `crypto_box_seal_open` plus the per-message DEK envelope. Keeps
  the asymmetric-at-ingest contract in one tested place.
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

## Recovery & Key Loss

- Forgot passphrase → unwrap the secret key with the recovery key, then set a new
  passphrase (re-wrap under the new KEK).
- Lost passphrase **and** recovery key → mail is unrecoverable. The setup flow must
  make this consequence explicit before the recovery key is dismissed.

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
