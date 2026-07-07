# Mailbox — Encryption at Rest (Sealed Bodies + Sealed Search Index)

**Status:** Draft / awaiting implementation
**Version:** 1.3
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
  and is the accepted tradeoff, not a bug to be fixed later. What is **not**
  accepted is that exposure outliving the breach — see *Key Rotation* for the
  ceremony that ends a stolen key's usefulness.
- **Write-access forgery.** Sealing protects confidentiality, not authenticity:
  ingest seals to a public key that is deliberately readable on the box, so an
  attacker with database write access can fabricate a complete fake message
  exactly as ingest would. AEAD row-binding (see *Key Hierarchy*) kills the
  cheaper attack — splicing real ciphertexts onto other rows — but wholesale
  forgery is inherent to receive-while-locked, and is accepted and stated
  rather than implied away.

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
   `tmpfs` working copy and the in-memory private key are wiped. Nothing needs
   sealing at close — the sealed copy is already current (*seal-after-fold*,
   see Search Architecture).

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
  working copy, and any decrypted state. Logout and session expiry
  close it too, but they are no longer the only thing that does.
- **Closing is passive by design.** PHP only runs when a request arrives, so
  expiry must not depend on code executing at the moment the window ends.
  Seal-after-fold keeps the durable sealed index current at all times; the
  unwrapped key lives under a TTL that makes it evaporate on schedule; and the
  scheduled-task cron (already running every 15 minutes) sweeps `/dev/shm` for
  working copies whose window is gone — catching walk-aways and crashes alike.
  Worst case, a decrypted working copy lingers in RAM for one cron interval.
  Nothing critical happens at close; closing is deletion, not a ceremony.
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
  unlocker; any single unlocker opens it. That makes the whole system exactly
  as strong as the **weakest enrolled wrapping** — an offline attacker with a
  copy of the database attacks whichever one is cheapest, so a hardware-grade
  passkey buys nothing if a sibling wrapping falls to brute force. The entropy
  floors below exist for that reason, and the setup UI states the principle
  plainly. Enrolling or removing an unlocker is an
  in-session act (it needs the unwrapped secret key in hand). Three kinds:
  - **Passkey (the primary, default UX).** A PRF-capable WebAuthn credential
    derives a 32-byte KEK inside the authenticator hardware on a touch/face
    check — nothing to memorize, and the ingredient never rests on the server.
    Provided by the core passkey service (`specs/implemented/passkeys_core.md`, context
    `vault-kek`). PRF outputs are **per-credential**, so each enrolled passkey
    holds its own wrapping; revoking a passkey deletes its wrapping.
  - **Recovery codes (required backup).** A printed list of one-time codes
    generated at setup, each independently wrapping the secret key (a code is
    key material, not a server-verified check — that is why codes work where
    TOTP-style 2FA structurally cannot). Each code carries **≥128 bits of
    entropy** (26 Crockford-base32 characters, printed in typing-friendly
    groups); its KEK is derived with a keyed hash (`crypto_generichash` over
    the code plus a stored per-user salt) — the entropy *is* the defense, so
    no slow KDF is needed. A used code's wrapping is deleted.
  - **Passphrase (optional).** `sodium_crypto_pwhash` (Argon2id, at
    `OPSLIMIT_INTERACTIVE` / `MEMLIMIT_INTERACTIVE` or higher) derives a
    32-byte KEK from a memorized passphrase and a stored per-user salt, for
    operators who want a knowledge factor in addition to possession factors.
    This is the one unlocker whose strength the user chooses: a short
    passphrase quietly becomes the weakest wrapping and reduces the whole
    mailbox to a dictionary attack. The enrollment UI says so — which is
    exactly why the passphrase is optional and the passkey is the default.
- **User keypair.** An X25519 keypair (`sodium_crypto_box_keypair`). The **public**
  key is stored in cleartext and used at ingest. The **secret** key is wrapped
  under each unlocker's KEK with the same AEAD (AD: user id + unlocker id) and
  stored at rest; it is only ever unwrapped into RAM during a session.
- **Per-message data key (DEK).** Each message gets a random 32-byte DEK. Content
  fields are encrypted under the DEK with the AEAD construction
  (`crypto_aead_xchacha20poly1305_ietf` — same libsodium, same key size as
  secretbox) with **additional data binding the ciphertext to its row**:
  message id + field name for content columns, message id + attachment id for
  attachment bytes, and likewise for the sealed index blob. AD is neither
  stored nor secret — it must simply *match* at decrypt time, so a ciphertext
  copied onto another row fails authentication instead of decrypting into a
  lie. The DEK is sealed to the
  user's public key with `sodium_crypto_box_seal` and stored alongside the message.
  - Ingest needs only the public key (seal the DEK).
  - Read needs the secret key: `crypto_box_seal_open(DEK)` → decrypt fields.
- **Key loss.** Losing **every** enrolled unlocker — all passkey devices, all
  unused recovery codes, and the passphrase if set — makes all mail permanently
  unreadable. State this plainly in the setup UI; the recovery-code step
  requires explicit acknowledgment before it can be dismissed.

### Key storage & in-memory rules

- The unwrapped secret key **must never** be written to the disk- or DB-backed session
  store. **Decided mechanism: APCu** — in-process shared memory, keyed to the session
  id, TTL = the unlock window's idle timeout and re-stored on each key-using request
  (activity extension). No external daemon, no socket; owned by the FPM master, so it
  survives `ondemand` worker recycling (the window holds across requests) and clears on
  master restart. `apcu_delete` on window close (idle expiry, logout, or session
  expiry). Rejected alternatives: Redis/Memcached (external daemon, Redis persists to
  disk by default — a direct at-rest hazard — and both enlarge the auditable surface);
  a tmpfs file holding the raw key (a named filesystem path invites backup/`/proc`/
  coredump capture — strictly worse than an anonymous SHM slot; tmpfs stays right for
  the bulk FTS index, which is sealed content-derived data, but not for the bare key).
- **APCu alone does not satisfy the at-rest invariant — three host facts must hold**,
  each a provisioner health check:
  1. **Anonymous mmap.** `apc.mmap_file_mask` must be empty (anonymous `MAP_ANONYMOUS`),
     not the `/tmp`-file default. A file-backed segment is pageable to a real disk path.
  2. **Coredumps disabled** on the mail FPM pool (`rlimit_core = 0`; kernel not piping
     cores anywhere durable) — a crash must not dump the key.
  3. **Swap disabled or encrypted** (see the swap requirement below).
  With those three, the honest residue is that `apcu_delete` frees the slot without
  scrubbing, so key bytes linger in the SHM segment until overwritten — a RAM-residency
  fact (covered by the cold-boot / physical-RAM boundary this spec family places out of
  scope), not disk persistence. Any code path in the pool can read the SHM entry while a
  window is open; that is the already-accepted "compromise during an active window"
  residual, not a new exposure. (An optional dedicated FPM pool for the unlock code would
  narrow SHM readership to mail code; noted, not required.) Moving key custody to the
  browser for the window would remove the server-held copy entirely, but that is the
  deferred client-side-crypto fork — not adopted piecemeal here.
- The decrypted FTS index working copy lives in `/dev/shm` (tmpfs, RAM-backed),
  never on a persistent disk path. Deleted when the unlock window closes; the
  scheduled-task cron deletes any working copy whose window is gone.

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

**At-rest form — a disposable cache, not an organ:** the FTS5 database is a
single file, envelope-encrypted under the user's key (its own DEK sealed to the
public key), stored as a sealed blob. The blob is purely an accelerator. Ground
truth is always the sealed message rows plus the from-scratch rebuild path
(decrypt → tokenize → insert into an in-memory FTS5 db), which must exist in
full; the cache is **deleted, never repaired**, on any provocation — missing,
stale, crashed mid-write, corrupt, or key rotation. Measured on the dev box
(EPYC-class vCPU): a full rebuild costs ~2–3s per 10k messages (~20–30s per
100k; FTS5 tokenization dominates, the crypto is nearly free). That is why the
cache is worth persisting for a years-deep archive — and why losing it is
never an error.

**Incremental maintenance (the sealed append-log model):** a cleartext high-water mark
records the id of the last message folded into the index. At login, after the index is
decrypted to `/dev/shm`, the manager selects messages with id > high-water, decrypts
their indexable fields, inserts them into FTS5, and advances the mark. Inbound mail
arriving while logged out is simply sealed and stored; it is folded in at the next
login. First-ever login pays a one-time full backlog index build.

**Seal-after-fold (the crash-safe rule):** the index is re-sealed and persisted
immediately after every fold or update, while the key is in hand — never at
window close. The durable sealed copy is therefore always current, and closing
the window reduces to deleting the `/dev/shm` working copy, which needs no key
and no running code at any particular moment. A crash or walked-away session
loses nothing and leaves only a working copy for the cron sweep to delete.

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
- **Spam learning is key-gated; scoring is not.** rspamd *scores* each message
  pre-seal, wherever the pipeline runs — at receive time for Standard/Private,
  inside deferred ingest at the next unlock for Fortress (relay spec). But
  *learning* from user feedback (`LearnSpamFeedback`) trains on body tokens,
  so on protected domains it follows the same key-gated poll as AI processing
  (levels spec § AI Processing): pending items are cleartext references
  (verdict + message id), the learn step decrypts in-RAM inside an unlock
  window and records completion in the processing log. No plaintext
  side-queue. Consequence, stated plainly: on protected domains the filter
  learns only while the user is around. Named bounded artifact: rspamd's own
  Bayes store holds hashed token statistics derived from learned mail — not a
  body copy, but it exists and lives outside the sealed columns.
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
  `crypto_box_seal` / `crypto_box_seal_open` plus the per-message AEAD envelope
  and its row-binding AD convention, so the id-plus-field-name discipline lives
  in one tested place rather than at every call site. The same helper encrypts
  and decrypts attachment `File` bytes under the message DEK, so the gated
  `File` stream has one tested path to open them in-session.
- **Index manager** — owns the `/dev/shm` lifecycle: decrypt-on-unlock,
  incremental fold via the high-water mark (sealing after every fold), query,
  wipe-on-close, and the cron sweep that deletes orphaned working copies.

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
- **Swap must not silently persist RAM.** tmpfs pages and shared-memory
  segments are ordinary memory to the kernel: under pressure it pages them out
  to swap, which is persistent disk — fragments of the unwrapped key or whole
  pages of the decrypted index could then survive the wipe and the window.
  A host running encryption at rest must have swap disabled (`swapoff` — the
  normal state for the target VPS profile) or encrypted swap keyed with an
  ephemeral random key each boot (`cryptsetup` plain mode over the swap
  device). Add an `InboundEmailHealth` provisioner check that warns when
  protected domains exist and unencrypted swap is active. This closes paging;
  a cold-boot attack on physical RAM stays out of scope, as everywhere in
  this spec family.
- **APCu host hardening** (the key-store mechanism — see *Key storage & in-memory
  rules*). The same `InboundEmailHealth` check verifies, when protected domains exist:
  `apc.mmap_file_mask` is anonymous (not file-backed), and the mail FPM pool runs with
  coredumps disabled (`rlimit_core = 0`). Both are what keep the unwrapped key out of a
  real disk path; without them APCu residency is not equivalent to RAM-only.
- No Apache/PHP config changes beyond the extension.

## Pre-Launch / Backfill

The platform has no production users yet, so there is no existing mail to preserve. New
mail is sealed going forward. Any existing plaintext (dev mail, or a domain being
raised from Standard) converts via a one-time in-window backfill pass. Its unit of
work is **converge each message to the lean sealed form**, not just seal the
columns: seal the content fields, split attachments out to sealed `File`s if the
raw still carries them, then **destroy the raw** — null `iem_raw_message`, delete
the raw-message store file. A message is not marked done (`looksEncrypted()`-style
marker, idempotent) until its raw is gone; otherwise the "sealed" archive keeps a
complete plaintext copy of every pre-upgrade message. Exemption: rows whose raw
lives at a *remote* IMAP source — the counterparty provider holds that copy by
the nature of IMAP sourcing, and IMAP domains cap at Private with that
disclosure already.

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

## Key Rotation — recovering from a suspected compromise

The active-session residual means a breach during an unlock window can
exfiltrate the unwrapped secret key. Without rotation, that one theft converts
to permanent read access: every past message and all future arrivals seal to
the matching public key, and no amount of server rebuilding, password
rotation, or passkey re-enrollment revokes it. Rotation is the ceremony that
ends the stolen key's usefulness.

**The ceremony (in-session — it needs the current secret key in hand):**

1. Generate a fresh X25519 keypair.
2. For every message: `crypto_box_seal_open` its sealed DEK with the old
   secret key, re-seal the **same** DEK to the new public key. Bodies and
   attachment bytes are untouched — only the small sealed-key blob changes.
   One asymmetric op pair per message (thousands per second), so the full
   archive rotates in seconds-to-minutes.
3. Re-wrap the new secret key under every enrolled unlocker (each passkey's
   PRF KEK, the passphrase if set).
4. Replace the stored public key, so ingest seals new arrivals to the new pair.
5. Invalidate all recovery codes and generate a fresh printed list (each old
   code wrapped the old key), and offer a fresh key-file export.
6. Delete the sealed search-index cache — it seals under the old key and is a
   disposable cache by rule (see Search Architecture); the next unlock
   rebuilds it.

**Resumability:** the sealed-DEK column carries a key-generation id, so a
message is unambiguously old-sealed or new-sealed and an interrupted rotation
resumes where it stopped instead of stranding half the archive. During a
rotation both keypairs' wrappings exist; the old keypair's wrappings are
deleted only after the last message flips.

**The honest limit:** rotation cannot un-leak data. Anything the attacker
already copied *during* the breach (sealed blobs plus the key to open them,
or plaintext read in-window) is gone regardless. What rotation protects is
everything else: mail that arrives after it, and the archive against a
stolen key being replayed later — including the leaked-backup chain (a backup
leaked before rotation plus a key stolen after it no longer combine).

**Surface:** a "Rotate encryption keys" action in the mailbox security UI,
with copy recommending it after any suspected box compromise. It is the same
machinery the enrollment flows already need (unwrap, re-wrap, re-seal), not a
new crypto path.

## Recovery & Key Loss

- Lost/replaced passkey device → unlock with any remaining unlocker (another
  passkey, a recovery code, the passphrase), then enroll the new device and
  revoke the old credential's wrapping.
- Suspected box compromise → run the key-rotation ceremony (above) as soon as
  the box is trusted again.
- **The unlocker floor (structural, not advisory).** A wrapping-delete that
  would leave fewer than one passkey wrapping *and* fewer than three unused
  recovery codes is refused at the deletion point — enforced in the wrapping
  store, not in UI copy — and the refusal names what to enroll or regenerate
  first. Every flow stays possible in the right order: revoke every passkey
  once fresh codes exist; burn codes down until the floor forces an in-session
  regeneration before further revocations. One exemption: *consuming* a code
  to unlock deletes its wrapping by design and is exempt from the floor check,
  but still counts toward the forced-regeneration prompt. "Unrecoverable by
  design" is a guarantee against attackers who stole everything — it must not
  be reachable through the platform's own delete buttons.
- All unlockers lost → mail is unrecoverable, by design. The setup flow makes
  this explicit before the recovery codes can be dismissed.

## Documentation to Update

- `plugins/mailbox/docs/overview.md` — add an "Encryption at rest" section
  describing the sealed-content model, what stays cleartext and why, and the sealed
  search-index lifecycle (current-state only; do not narrate the FTS index it replaces).
- `docs/secret_box.md` — cross-reference the new sealing helper as the asymmetric
  companion to `SecretBox`.

## Open Items to Confirm During Implementation

- Confirm `sodium_crypto_box_seal` / `_open` are available (ext-sodium present per
  `SecretBox`).
- Confirm APCu is present and enabled (the decided key-store mechanism) and that the
  provisioner can enforce its three host facts — anonymous `apc.mmap_file_mask`,
  coredumps off on the mail FPM pool, swap off/encrypted.
- Confirm the production Docker base carries `libsqlite3` + FTS5.
- Decide sealed-index storage form (file vs row) against real index size once attachment-
  excluded text volume is measured.
- Confirm the gated `File` stream (`implemented/file_private_storage.md`) can carry an
  email-supplied decrypt hook that resolves the owning message's DEK at serve time,
  keeping `File` crypto-agnostic while still decrypting sealed attachments in-session.
