# Mailbox Sealing — Fix Pack

**Status:** Ready for implementation
**Version:** 1.0
**Fixes:** the ten verified defects from the post-implementation review of
`specs/inbound_email_encryption_at_rest_executor.md` (encryption at rest for the mailbox
plugin, Sealed Vault consumer). Each fix is at the causing layer — no defensive shims.

## Why this pack exists

Three of the defects destroy user mail unrecoverably (key rotation bricks every sealed
message; a transient failure between insert and seal leaves a permanently empty message;
attachment-extraction failure on a sealed mailbox silently drops attachments). The rest
are user-visible breakage: sealed attachments download as ciphertext, long recipient
lists fail after the SMTP send, backfilled rows 500 on read, grant changes permanently
lock sealed mail, and the index sweep deletes working copies out from under open windows.

## Inventory of integration points touched

| Layer | File | Change |
|---|---|---|
| Core crypto | `includes/SealedBox.php` | `openDek()` derives the public key from the secret |
| Core crypto | `includes/VaultCrypto.php` | `openItemDek()` drops the public-key param |
| Core window | `includes/VaultUnlock.php` | `/dev/shm` window markers; new `onReseal` contract |
| Rotation ceremony | `logic/vault_rotate_verify_logic.php` | oldest-generation authorization, fail-loud retirement |
| Consumer bootstrap | `plugins/mailbox/includes/bootstrap.php` | generation-scoped reseal, throws on failure, per-attachment flag |
| Message model | `plugins/mailbox/data/inbound_email_message_class.php` | `iem_recipient` → text; `iem_sealed_owner_user_id`; `iem_raw_sealed`; `rawAd()`; owner-from-row decrypt; sealed-raw read; `openSealedAttachment` keyed on the attachment row |
| Attachment model | `plugins/mailbox/data/inbound_message_attachment_class.php` | `ima_is_sealed` |
| Ingest | `plugins/mailbox/includes/InboundEmailRouter.php` | insert+seal transaction; sealed-raw fallback; flag on extraction |
| Outbound | `plugins/mailbox/includes/MailboxSender.php` | insert+seal transaction; no recipient truncation; new attachment-open call |
| Downloads | `plugins/mailbox/includes/attachment_retrieval.php` | decrypt file-backed sealed bytes; locked-state errors |
| Backfill | `plugins/mailbox/logic/backfill_seal_logic.php` | direction-aware recipient sealing |
| Migrations | `plugins/mailbox/migrations/migrations.php` | data backfills for the two new flags/columns |
| Docs | `docs/sealed_vault.md`, `plugins/mailbox/docs/overview.md` | updated contracts, current-state voice |

Out of scope (verified in review but below its severity cap; a later pass): FTS
high-water advancing past vault-locked rows, the retained non-vault search path after the
GIN index drop, fold/persist efficiency, vault-gating of outbound SMTP settings.

## Fix 1 — Key rotation must never strand sealed mail

**Defect.** The rotation ceremony saves the new public key onto the vault row *before*
running consumer re-seal callbacks. The mailbox callback reads that row to get the "old"
public key, so it gets the new one, every DEK unwrap fails, the failures are swallowed
into `error_log`, the ceremony believes re-sealing succeeded and soft-deletes every
old-generation wrapping — destroying the only path to the old secret while all mail is
still sealed to it. First rotation = total, permanent loss of sealed mail.

**Fix, three layers:**

1. **Kill the wrong-public-key class at the crypto layer.** An X25519 public key is
   derivable from its secret key (`sodium_crypto_box_publickey_from_secretkey`).
   `SealedBox::openDek(string $sealed, string $secret_key)` derives it internally;
   the public-key parameter is removed. `VaultCrypto::openItemDek(string $sealed,
   string $secret_key)` likewise. Update all call sites (mailbox bootstrap,
   `InboundEmailMessage::openMessageDekCrypto()`, `MailboxIndex`). It is now impossible
   to unwrap with a mismatched keypair — the caller supplies one thing, the secret.

2. **Generation-scoped, fail-loud re-seal.** `VaultUnlock::onReseal()` callback contract
   becomes `function(int $user_id, string $old_secret_key, int $old_key_generation,
   string $new_public_key, int $new_key_generation): void`. The mailbox callback selects
   rows `WHERE iem_key_generation = old_key_generation` (equality — it re-seals exactly
   the generation whose secret it holds), attempts every row, and **throws** at the end
   if any row failed (naming the count), instead of returning success.

3. **The ceremony retires nothing it hasn't drained.** In
   `logic/vault_rotate_verify_logic.php`:
   - The authorizing wrapping is the one with the **lowest** `uew_key_generation` for the
     presented credential (after a partial failure both generations' wrappings are live;
     the retry must unwrap the oldest secret to finish draining it).
   - `$old_generation` = that wrapping's generation, passed to the callbacks.
   - The callback loop runs in try/catch. On any Throwable: **retire nothing**, return a
     LogicResult error telling the user rotation is incomplete, every unlocker still
     works, and to run it again. (Both generations' wrappings staying live is the
     documented crash-safe state — a retry converges.)
   - On success: soft-delete only wrappings with `uew_key_generation == $old_generation`
     (the generation just drained), not the whole pre-rotation list.

Update `docs/sealed_vault.md`: the `onReseal` signature, the equality-scoped re-seal
rule, `openItemDek(sealed, secret)`.

## Fix 2 — Sealed attachments must decrypt on download; sealed state lives on the attachment

**Defect pair.** (a) The member and admin download endpoints stream
`File::read_bytes('original')` raw — the AEAD ciphertext — because the File decrypt hook
only fires in `serve_from_path()`. (b) Whether the bytes are sealed is inferred from the
*message's* `iem_content_sealed`, but backfill flips that flag while leaving pre-existing
lean attachment Files as plaintext — the hook then AEAD-opens plaintext and the
uncaught RuntimeException 500s every such download.

**Fix.** Sealed-or-not is a property of the stored bytes, so record it on the attachment
row: new column `ima_is_sealed` (`bool`, default false) on
`InboundMessageAttachment`. `extractAttachmentsToFiles()` sets it true exactly when it
seals the part bytes.

`InboundEmailMessage::openSealedAttachment()` changes to take the attachment row —
`openSealedAttachment(InboundEmailMessage $msg, InboundMessageAttachment $att, string
$bytes)` — returns `$bytes` unchanged when `ima_is_sealed` is false, decrypts (owner
resolution per Fix 7, AD from `ima_mime_part`) when true. Callers: the bootstrap File
hook, `MailboxSender::readOriginalPartBytes()`, and (new) `attachment_retrieval.php`.

`mailbox_retrieve_attachment_bytes()` file-backed branch: after `read_bytes()`, pass
through `openSealedAttachment()`; catch `VaultLockedException` → `fail('Unlock your
vault to download this attachment.')`. The raw-parse branch catches the same from
`getRawMimePart()` (sealed raw, Fix 4).

Migration (data-only): set `ima_is_sealed = true` on file-backed manifest rows whose
owning message has `iem_content_sealed = true` — every such dev row was sealed by ingest.

## Fix 3 — Insert and seal are one atomic step

**Defect.** Ingest commits the row with empty content columns, then seals in a separate
UPDATE. If the UPDATE fails, Postfix's retry (exit 75) hits the dedup unique constraint
and reports success — the message is permanently empty, and the raw was never kept.

**Fix.** Wrap the insert and the seal UPDATE in one DB transaction, both places that do
the insert-then-seal dance:
- `InboundEmailRouter::storeMessage()`: when `$sealing`, `beginTransaction()` before
  `CreateEntry`, `commit()` after `sealMessageContent()`. Any failure (including the
  dedup catch paths) rolls back first; a seal failure rethrows so `handleDelivery()`
  returns 75 and the retry re-inserts cleanly — no half-row survives to poison dedup.
- `MailboxSender::storeOutboundRow()`: same shape around `$row->save()` +
  `sealAndPersistContent()`.

Attachment/file work stays outside the transaction (file I/O is not transactional and
has its own rollback), as do filters.

## Fix 4 — Extraction failure on a sealed mailbox keeps the raw, sealed

**Defect.** When attachment extraction fails for a sealed mailbox, the raw fallback is
refused (it would write plaintext) and the attachments are silently lost — the one tier
that pays for protection gets *less* durability.

**Fix.** Seal the raw itself under the message's DEK and store that. New AD helper
`InboundEmailMessage::rawAd(int $message_id)` = `mail:{id}:raw`. New column
`iem_raw_sealed` (`bool`, default false). In `persistRawAndManifest()`, the `$dek !==
null` failure branch becomes: `sealField($raw_email, $dek, rawAd($id))` →
`persistRawFallback()` with the sealed blob → `writeManifestFromRaw($id, $raw_email)`
(section pointers parse the in-memory plaintext) → set `iem_raw_sealed = true`.

`getRawMessage()` decrypts when `iem_raw_sealed` (owner resolution per Fix 7, throws
`VaultLockedException` when locked). Sweep every `getRawMessage()`/`getRawMimePart()`
caller: user-facing paths catch `VaultLockedException` and surface the locked state;
in-window paths (backfill, compose/forward) let it propagate as today's failure shape.

## Fix 5 — Recipient column fits its ciphertext

**Defect.** Outbound rows seal `iem_recipient`, but the column is `varchar(500)`; the
AEAD blob overflows it for recipient lists over ~330 chars. The UPDATE throws *after*
the SMTP send — the user sees a failure for delivered mail, and no sent copy exists.

**Fix.** `iem_recipient` → `type 'text'` in `$field_specifications` (schema syncs
automatically). Remove the `substr(..., 0, 500)` on `$recipient_str` in
`MailboxSender::storeOutboundRow()` — the compose path already bounds its input; the
stored value must be the full list.

## Fix 6 — Backfill seals what the read path will expect

**Defect.** Backfill has no direction filter but always passes `$seal_recipient=false`;
the read hooks decrypt `iem_recipient` on any sealed outbound row, so a backfilled
outbound row's plaintext recipient AEAD-opens as "malformed blob" and 500s the thread.

**Fix.** In `backfill_seal_logic.php`, per message:
`$seal_recipient = ($msg->get('iem_direction') === 'outbound')`, passing the row's
current plaintext `iem_recipient` through. (The already-lean plaintext-attachment
residual is made safe by Fix 2's per-attachment flag — those Files stay plaintext and
stream as-is; the accepted pre-launch residual, now correct instead of a 500.)

## Fix 7 — The sealed owner is recorded at seal time

**Defect.** Decryption resolves the vault owner from the *live* grant list
(`singleOwnerUserId()`); adding a second grantee or deleting the alias makes it return
null and every read throws `VaultLockedException` forever, even for the owner.

**Fix.** New column `iem_sealed_owner_user_id` (`int8`, nullable). `sealAndPersistContent()`
writes it from the vault's `uev_usr_user_id`. Every decrypt path
(`decryptSealedField()`, `decryptSealedFieldStatic()`, `openSealedAttachment()`,
`getRawMessage()`) resolves the owner as `iem_sealed_owner_user_id` first, falling back
to `singleOwnerUserId()` only when the column is null (legacy rows until the migration
runs). Add the column to `MailboxService`'s raw-row SELECT lists that feed
`decryptSealedFieldStatic()`.

Migration (data-only): populate it on existing sealed rows from the alias's current
single grantee.

## Fix 8 — Window visibility works from cron

**Defect.** `VaultUnlock::hasAnyOpenWindow()` enumerates APCu, but its only caller runs
under the CLI cron SAPI, whose APCu segment is separate from the web workers' — it
always sees nothing, so `SweepMailboxIndexTemp` deletes `/dev/shm` FTS working copies
out from under open windows every 15 minutes.

**Fix.** Windows leave a cross-process marker where the working copies already live:
`/dev/shm/vault_window_{user_id}_{scope}` (scope sanitized to `[a-z0-9_]`). The marker
holds no secret — its existence + freshness is the signal. `open()` and every
`secretKey()` extension `touch()` it with mtime = now + the idle TTL; `lockAll()`
unlinks the user's markers; a single-session `lock()` leaves it (another session may
hold a window — the marker expires on its own, so the sweep is at worst delayed one
idle interval, never wrong). `hasAnyOpenWindow()` becomes marker-only: exists and
mtime > now → true; stale markers are unlinked opportunistically.

## Fix 9 — Ship the alias-config dependency with this changeset

**Defect (release integrity, no code).** `EmailSecurityScanJob.php` now requires
`plugins/mailbox/includes/MailboxAliasConfig.php`, which is an untracked file from the
triage effort — committing the encryption work without it fatals the phishing-scan
pipeline and the AI recipe admin page.

**Fix.** `plugins/mailbox/includes/MailboxAliasConfig.php` (and the other untracked
mailbox files: `MailboxIndex.php`, `bootstrap.php`, `backfill_seal_logic.php`,
`inbound_mailbox_search_index_class.php`, the sweep task pair) are committed together
with this changeset — one release unit.

## Verification

- `php -l` + `validate_php_file.php` on every touched file.
- Schema after sync: `iem_recipient` is `text`; `iem_sealed_owner_user_id`,
  `iem_raw_sealed`, `ima_is_sealed` exist.
- Rotation: rotate a test vault with sealed mail; every message's `iem_key_generation`
  advances and the mail still decrypts; force a reseal failure (temporarily bad row) and
  confirm the ceremony errors *without* soft-deleting old wrappings.
- Attachment: download a sealed attachment via `/profile/mailbox/attachment` in-window
  (plaintext file), locked (clean locked-state error, not ciphertext, not a 500).
- Ingest: deliver to a sealed mailbox; confirm one transaction (no empty-content row on
  a forced seal failure; Postfix retry stores it whole).
- Outbound: send to a >500-char recipient list from a sealed mailbox; sent copy stored,
  recipient decrypts.
- Backfill: run against a mailbox with pre-vault inbound + outbound mail; threads and
  attachments read cleanly afterward.
- Sweep: with a window open (web), run the sweep task from CLI; the working copy
  survives; after idle expiry it is swept.
