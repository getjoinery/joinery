# Inbound Email — Encryption at Rest — Executor Package

**Status:** Ready for implementation
**Version:** 1.0
**Design authority:** `specs/mailbox_encryption_at_rest.md` (v1.3) — the *why* and
the threat model. This document is the *how*: exact files, line anchors, schema,
signatures, and acceptance checks. Where they disagree on intent, the design spec
governs; where the design spec's *literal wording* collides with the code as built, this
document resolves it (see Phase 4's ordering resolution) and is authoritative on the
mechanics.

> **Pull-forward from `specs/mailbox_security_levels.md` v1.5 (§ Vault-Gated
> Settings) — ship in THIS release, not with the levels package:** the moment
> any mailbox is sealed, the settings that reroute its future mail must be
> behind the same ceremony as the mail, or sealing is bypassable at the
> control plane (a filter on a protected domain acts at receive time, on
> plaintext). In the same release as sealing, gate these mutations on an open
> unlock window (one-tap prompt-and-continue, the locked-state pattern):
> **filters/forwarding rules, alias destinations/modes, and outbound relay
> SMTP settings** for accounts with a vault — the actions that redirect
> plaintext. API-key creation/scope/reveal, mailbox grants, and the
> notification-content toggle are a 2FA step-up (the design spec's
> sensitive-actions list), NOT a window gate. Also ship the *minimal*
> window end-event set: session end, explicit lock, and the global
> credential-event kill (password/2FA/passkey/recovery changes end all
> windows). The full event polish (heartbeat, IP-change hook, idle/absolute
> caps, native grace) may wait for the levels package. Document what this
> pull-forward ships by extending `docs/account_security.md` (the single
> doctrine doc) — not by creating a new doc.
**Depends on (build first):** `specs/implemented/passkeys_core_executor.md` (the `vault-kek` PRF context)
and `specs/implemented/sealed_vault_core_executor.md` (the shared crypto core, key hierarchy, and unlock
window — see the Vault baseline below). Do not start until `PasskeyService` and the vault
exist.

### Vault baseline (retarget — read before executing)

The key hierarchy and unlock window are **not built here** — they are the shared core vault
(`specs/implemented/sealed_vault_core_executor.md`), consumed by mail, AI chat, and later drive/passwords.
This package's **Phase 1** (crypto helpers), **Phase 2** (key hierarchy), **Phase 3** (unlock
window), the unlocker floor (2.4), **Phase 8** (key rotation), and the backup/recovery content
are **superseded** by the vault package — build them there, once, not here. They remain below
only as the detailed record that seeded the vault; **do not build them twice.** Apply these
substitutions throughout this package:

- `MailboxCrypto` → core **`VaultCrypto`** (mail supplies its own AD strings, e.g. `mail:{id}:{field}`)
- `MailboxUnlock` → core **`VaultUnlock`** (`MailboxUnlock::open/secretKey/close` →
  `VaultUnlock::open/secretKey/close`)
- the `iek`/`iew` tables → core **`uev`/`uew`**; the `mailbox_unlock_*` endpoints → core
  **`vault_unlock_*`**; PRF context `mail-kek` → **`vault-kek`**
- the APCu/swap host hardening (Phase 10) is the vault's core `VaultHealth` check, not a mail one

**Mail's own build is Phases 4, 5, 6, 7, 9, 10** — its content sealing, the ingest reorder,
the File-decrypt-hook *registration* (the hook itself is core), the FTS index, no-sideways-
copies, backfill, and mail-specific deployment. The per-message sealed-DEK column
(`iem_sealed_key`) stays on the mail row (Phase 4.4) — that is the one key-related thing mail
owns, per the vault consumer contract. Mail's key-rotation participation is a **re-seal
callback** it registers with the vault ceremony (re-seal each `iem_sealed_key`; delete the
FTS blob), not its own ceremony.

### Naming baseline (rename interaction — read before executing)

**Recommended order: run `specs/implemented/plugin_rename_inbound_email_to_mailbox.md` FIRST, then
this package.** Rationale: the rename spec is frozen and predates these feature files; if
features land first, the rename must be reopened to sweep the ~15 new files, new settings,
and new classes below — expanding a done spec's blast radius. Rename-first means features
build on the final name and the rename never needs touching. The rename is a cheap
mechanical job, so the pipeline is: rename → passkeys → encryption-at-rest → rest.

This package is written against **today's on-disk `inbound_email` paths** so every path and
line number can be verified against the code as it exists now. After the rename, apply
exactly **two** substitutions everywhere below — nothing else changes:

1. Directory prefix: `plugins/mailbox/…` → `plugins/mailbox/…`
2. Setting-key prefix: `inbound_email_…` → `mailbox_…` (so `inbound_email_unlock_idle_minutes`
   becomes `mailbox_unlock_idle_minutes`)

**Rename-invariant (do NOT change):** table/column prefixes (`iem_`/`iea_`/`ima_`), PHP class
names (`InboundEmailRouter`, `MailboxService`, `InboundEmailHealth`, the new `MailboxIndex` —
note `MailboxCrypto`/`MailboxUnlock` and the `iek_`/`iew_` tables are superseded by the core
vault per the Vault baseline above; `VaultCrypto`/`VaultUnlock`/`uev`/`uew` are core and the
rename never touches them), data-class filenames
(`inbound_email_*_class.php` — the rename keeps these), task class names, the
`utils/inbound_email_handler.php` basename, and every line number cited. If the rename is
*not* run first, use the paths/settings exactly as written below.

## What this package changes (map)

| Area | Files (real paths, verified) |
|---|---|
| Crypto core, key hierarchy, unlock window | **built by the vault** — `SealedBox`/`VaultCrypto`/`VaultUnlock`, `uev`/`uew` tables (`specs/implemented/sealed_vault_core_executor.md`). Mail consumes them. |
| New search engine | `plugins/mailbox/includes/MailboxIndex.php` + a `/dev/shm` sweep task |
| Ingest reorder + seal | `plugins/mailbox/includes/InboundEmailRouter.php` (`storeMessage()` ~336–423, `extractAttachmentsToFiles()` ~472–524) |
| Message schema | `plugins/mailbox/data/inbound_email_message_class.php` (`$field_specifications`, `getMultiResults()` ~332–361) |
| Thread list / read | `plugins/mailbox/includes/MailboxService.php` (`listThreads()` 434+, FTS predicate 477–485, previews 516–517, `getThread()` 596) |
| Outbound seal | `plugins/mailbox/includes/MailboxSender.php` (`storeOutboundRow()` 693, `buildBody()` 269, `attachOriginal()` 370, `readOriginalPartBytes()` 418) |
| Attachment decrypt hook | `data/files_class.php` (`serve_from_path()` 283–295), `serve.php` (private-cloud 417–448, local-restricted 459–483) |
| Drop old FTS | `plugins/mailbox/migrations/migrations.php` (`iem_007_fulltext_search_index`, index `iem_fulltext_idx`) |
| Key rotation / backfill / health | logic endpoints + `InboundEmailHealth` |

## Phase 0 — Preflight & new platform dependencies

0.1 Branch: `git checkout -b inbound-email-encryption-at-rest`.

0.2 Three **new** dependencies (none used in the codebase today — verified):
- **`ext-sodium`** — already present (`SecretBox` uses it). Unlike `SecretBox` (which has
  an OpenSSL fallback), the mail crypto **hard-requires** sodium: `crypto_box_seal` has no
  clean fallback and the feature is meaningless without it. `SealedBox` throws at
  construction if `function_exists('sodium_crypto_box_seal')` is false.
- **`php8.3-sqlite3` (`ext-sqlite3`, FTS5 compiled in)** — new. Add to provisioning
  (`install_email.sh` / the deploy provisioner) and the Docker base images. Verify FTS5:
  `php -r '$d=new SQLite3(":memory:"); $d->exec("CREATE VIRTUAL TABLE t USING fts5(x)");'`
  exits 0. The engine (`libsqlite3-0`) is already on dev; **confirm the production base
  image carries it with FTS5** before rollout.
- **`ext-apcu`** — new. Add to provisioning + Docker. APCu holds the unwrapped secret key
  for the unlock window (design § Key storage). Its three host-hardening facts are
  enforced by a health check (Phase 10): anonymous `apc.mmap_file_mask`, coredumps off on
  the mail FPM pool, swap off/encrypted.

0.3 No new Composer packages (all three are PHP extensions). Add `ext-sqlite3` and
`ext-apcu` to `public_html/composer.json` `require` for documentation
(`"ext-sqlite3": "*"`, `"ext-apcu": "*"`), matching how `ext-sodium` would be declared.

0.4 Scope gate: sealing is driven by the **security level** (levels spec) and by the
presence of key material for the mailbox owner. This package builds the *mechanism*;
**Standard-level mailboxes seal nothing** (no key material → ingest stores plaintext, as
today). Everything below is a no-op path when the owner has no `iek` row.

## Phase 1 — Crypto helpers

### 1.1 Core: `includes/SealedBox.php` (the asymmetric sibling to `SecretBox`)

Instance class, no namespace, `chmod 666`. Mirror `SecretBox`'s shape: versioned
self-describing base64url blobs, `RuntimeException` on every error, fail-closed. **Hard
sodium requirement** (no fallback). Public surface:

```php
class SealedBox {
    public function __construct();                    // throws if ext-sodium absent
    // Keypair
    public function generateKeypair(): array;         // ['public'=>b64, 'secret'=>b64] (X25519, crypto_box_keypair)
    // DEK sealing (anonymous, ingest needs only the public key)
    public function sealDek(string $dek, string $public_key_b64): string;      // crypto_box_seal
    public function openDek(string $sealed, string $public_key_b64, string $secret_key_b64): string; // crypto_box_seal_open
    // Content AEAD with row-binding additional data
    public function aeadEncrypt(string $plaintext, string $key, string $ad): string;   // xchacha20poly1305_ietf
    public function aeadDecrypt(string $blob, string $key, string $ad): string;        // throws on AD mismatch/tamper
    // Key wrapping (wrap the X25519 secret key under a KEK; AD binds {user id, unlocker id})
    public function wrapKey(string $secret_key, string $kek, string $ad): string;      // AEAD
    public function unwrapKey(string $wrapped, string $kek, string $ad): string;       // AEAD
    // KEK derivation
    public function kekFromRecoveryCode(string $code, string $salt): string;           // crypto_generichash(code, salt) -> 32B
    public function kekFromPassphrase(string $passphrase, string $salt): string;       // crypto_pwhash Argon2id, INTERACTIVE limits
    // Recovery codes
    public function generateRecoveryCode(): string;   // 26 Crockford-base32 chars (>=128 bits), grouped for printing
}
```

Notes the executor must honor:
- `aeadEncrypt`/`aeadDecrypt` use `sodium_crypto_aead_xchacha20poly1305_ietf_*`; a random
  24-byte nonce is generated per call and stored in the blob; the **AD is not stored** —
  it must be supplied identically at decrypt or the open fails (this is the splice
  defense, pentest-brief I5).
- `kekFromRecoveryCode` uses `crypto_generichash` (keyed hash, **no slow KDF** — the
  code's ≥128-bit entropy is the defense). `kekFromPassphrase` uses `crypto_pwhash` at
  `SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE` / `MEMLIMIT_INTERACTIVE` or higher.
- Cross-reference this class from `docs/secret_box.md` as the asymmetric companion.

### 1.2 Plugin: `plugins/mailbox/includes/MailboxCrypto.php`

Thin orchestration over `SealedBox` that owns the **mail AD conventions** and the
per-message DEK dance, so the id-plus-field discipline lives in one place. Loaded via
`require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxCrypto.php'))`.
Surface:

```php
class MailboxCrypto {
    public function __construct();  // holds a SealedBox
    // --- Ingest (public key only) ---
    public function newMessageDek(): string;                                  // random 32B
    public function sealMessageDek(string $dek, string $public_key): string;  // -> iem_sealed_key
    public function sealField(string $plaintext, string $dek, int $message_id, string $field): string;  // AD = "{id}:{field}"
    public function sealAttachment(string $bytes, string $dek, int $message_id, int $attachment_id): string; // AD = "{id}:att:{aid}"
    // --- Read (needs the in-window secret key) ---
    public function openMessageDek(string $sealed_key, string $public_key, string $secret_key): string;
    public function openField(string $blob, string $dek, int $message_id, string $field): string;
    public function openAttachment(string $blob, string $dek, int $message_id, int $attachment_id): string;
}
```

AD strings are the single source of the row-binding convention; keep them here so a change
is one edit. Field names used as AD: `body_plain`, `body_html`, `subject`, `sender`
(inbound) and the same for outbound recipient/subject/body.

## Phase 2 — Key hierarchy (new tables + enrollment)

No per-user mailbox key record exists today (verified). Two new plugin data classes,
`chmod 666`, mirroring the header/statics of `data/inbound_email_message_class.php`. After
creating them, run **Sync with Filesystem** (admin Plugins page) or `update_database` to
create the tables — the same path that created `iem_`/`iea_`/`ima_`. Confirm how the plugin
requires/loads its existing data classes and mirror it.

### 2.1 `data/inbound_email_key_class.php` — per-user key record

`InboundEmailKey`, prefix `iek`, table `iek_inbound_email_keys`:
```php
'iek_inbound_email_key_id' => array('type'=>'int8','is_nullable'=>false,'serial'=>true,'is_primary_key'=>true),
'iek_usr_user_id'          => array('type'=>'int8','is_nullable'=>false,'unique'=>true,
                                     'foreign_key'=>array('table'=>'usr_users','column'=>'usr_user_id','on_delete'=>'CASCADE')),
'iek_public_key'           => array('type'=>'text','is_nullable'=>false),   // X25519 public (cleartext, used at ingest)
'iek_salt'                 => array('type'=>'text','is_nullable'=>false),   // per-user salt for recovery/passphrase KEKs
'iek_key_generation'       => array('type'=>'int4','is_nullable'=>false,'default'=>1),  // rotation id
'iek_fts_high_water'       => array('type'=>'int8','is_nullable'=>false,'default'=>0),  // last message id folded into the index
'iek_fts_blob_fil_id'      => array('type'=>'int8','is_nullable'=>true),    // sealed FTS index stored as a private File (Phase 6)
'iek_created_time'         => array('type'=>'timestamp(6)','default'=>'now()'),
'iek_updated_time'         => array('type'=>'timestamp(6)','is_nullable'=>true),
```
The **secret key is never stored here** — only wrapped, in 2.2. API exposure: leave
`$api_readable`/`$api_writable` at their `false` default (key material is not an API
resource). `$permanent_delete_actions = array();`. Multi class as usual, filter key
`user_id`.

### 2.2 `data/inbound_email_key_wrapping_class.php` — one row per unlocker

`InboundEmailKeyWrapping`, prefix `iew`, table `iew_inbound_email_key_wrappings`:
```php
'iew_inbound_email_key_wrapping_id' => array('type'=>'int8','is_nullable'=>false,'serial'=>true,'is_primary_key'=>true),
'iew_usr_user_id'        => array('type'=>'int8','is_nullable'=>false,'index'=>true,
                                   'foreign_key'=>array('table'=>'usr_users','column'=>'usr_user_id','on_delete'=>'CASCADE')),
'iew_unlocker_type'      => array('type'=>'varchar(16)','is_nullable'=>false),   // 'passkey' | 'recovery' | 'passphrase'
'iew_pkc_credential_id'  => array('type'=>'int8','is_nullable'=>true),           // passkey only: which pkc credential
'iew_wrapped_secret_key' => array('type'=>'text','is_nullable'=>false),          // AEAD-wrapped X25519 secret, AD = {user id, unlocker id}
'iew_key_generation'     => array('type'=>'int4','is_nullable'=>false,'default'=>1),
'iew_is_used'            => array('type'=>'bool','is_nullable'=>false,'default'=>false),  // recovery codes: one-time
'iew_label'              => array('type'=>'varchar(255)','is_nullable'=>true),
'iew_created_time'       => array('type'=>'timestamp(6)','default'=>'now()'),
'iew_used_time'          => array('type'=>'timestamp(6)','is_nullable'=>true),
'iew_delete_time'        => array('type'=>'timestamp(6)','is_nullable'=>true),
```
Not an API resource (`$api_readable`/`$api_writable` default false). The AD for `wrapKey`
binds `{iew_usr_user_id, iew_inbound_email_key_wrapping_id}` — so a wrapping row can't be
spliced onto another user. (Chicken-and-egg: the wrapping id is serial, so wrap → insert →
the wrapping is written with a placeholder AD id, then the AD is finalized; simplest is to
insert the row to mint the id, then `wrapKey` with that id and UPDATE — same two-phase
pattern as Phase 4. Alternatively bind AD to `{user id, unlocker_type, pkc_credential_id}`
which is known pre-insert for passkey/passphrase; for recovery use the row id via
two-phase. Pick one and keep it consistent; document it in `MailboxCrypto`.)

### 2.3 Enrollment flows (all in-window — they need the unwrapped secret key)

Logic endpoints (sessioned, `requires_session=true`), each gating on `passkeys_enabled`
and the unlock window where noted:
- **Setup ceremony** `logic/mailbox_security_setup_logic.php`: generate keypair; generate
  a per-user salt; generate N recovery codes (default 10); wrap the secret key under each
  recovery code, the optional passphrase, and the enrolling passkey's `mail-kek` PRF
  output (from `PasskeyService::verifyDerivation('mail-kek')`); store `iek` + all `iew`
  rows; open the unlock window; offer the **key-file export** (the wrapped-key rows as a
  small downloadable file) and the printed recovery codes. **Force explicit
  acknowledgment** that losing every unlocker = permanent loss before the codes can be
  dismissed. FormWriter for any input; no hand-rolled forms.
- **Add passkey wrapping** `logic/mailbox_add_passkey_logic.php`: requires an open window
  (secret key in hand); derive `mail-kek` for the new credential; wrap; insert `iew`.
- **Regenerate recovery codes** `logic/mailbox_regenerate_codes_logic.php`: in-window;
  invalidate old recovery `iew` rows, mint fresh.
- **Enroll passphrase** / remove unlocker: in-window; the remove path enforces the floor
  (2.4).

### 2.4 The unlocker floor + revocation veto (structural)

- A wrapping delete is refused **at the deletion point** (in `InboundEmailKeyWrapping` or a
  guard the delete calls, not in UI) when it would leave `< 1` live passkey wrapping
  **AND** `< 3` unused recovery codes. The refusal names what to enroll/regenerate first.
  Exemption: *consuming* a recovery code to unlock (marking it used) is not a
  floor-checked delete, but counts toward a forced-regeneration prompt.
- Subscribe to the passkey **revocation veto hook** (passkeys package § 2.5): when a
  passkey revocation would delete this mailbox's last passkey wrapping and the floor would
  break, throw `PasskeyRevocationVetoException($reason)` so the passkey page surfaces it.

## Phase 3 — The unlock window (APCu key store)

### 3.1 `plugins/mailbox/includes/MailboxUnlock.php`

Owns the APCu-backed window. Surface:
```php
class MailboxUnlock {
    public function open(int $user_id, string $secret_key): void;   // apcu_store keyed to session id, TTL = idle setting
    public function isOpen(int $user_id): bool;
    public function secretKey(int $user_id): ?string;               // fetch + re-store (activity extension); null if closed
    public function close(int $user_id): void;                      // apcu_delete + delete /dev/shm working copy
}
```
- APCu key = `mailkek:{session_id}:{user_id}`; value = raw secret key bytes; TTL =
  `inbound_email_unlock_idle_minutes` × 60 (default 30). Every `secretKey()` that returns
  non-null re-stores with fresh TTL (idle extension). `close()` fires on the lock endpoint,
  logout, and session end.
- **The window is never derivable from "logged in."** Reading, searching, previews,
  attachment decrypt, sending on Fortress, spam-learning, AI processing all call
  `secretKey()` and treat null as locked (surface the locked-state prompt, not an error).

### 3.2 Unlock/lock endpoints (sessioned)

| File (`logic/…_logic.php`) | Purpose |
|---|---|
| `mailbox_unlock_options` | Return `PasskeyService::getDerivationOptions($user,'mail-kek')` for the passkey path. |
| `mailbox_unlock_passkey`  | Body = assertion client JSON. `verifyDerivation('mail-kek')` → PRF output = KEK → find the `iew` passkey wrapping for that credential → `unwrapKey` → `MailboxUnlock::open`. |
| `mailbox_unlock_recovery` | Body `{code}`. Derive KEK via `kekFromRecoveryCode(code, iek_salt)`; try each unused recovery `iew` until one unwraps; mark it used; open window. |
| `mailbox_unlock_passphrase` | Body `{passphrase}`. `kekFromPassphrase` → unwrap the passphrase `iew` → open window. |
| `mailbox_lock` | `MailboxUnlock::close`. |

## Phase 4 — Ingest sealing (pipeline reorder — the load-bearing change)

### 4.1 The ordering conflict, resolved

The design says "seal after filters + attachment-split, **before save**." In
`InboundEmailRouter::storeMessage()` the save (`CreateEntry`, ~line 387) happens **before**
attachment-split (~406) and filters (~417), and the content AD binds to the **serial
message id**, which does not exist until the insert. **Resolution (authoritative):**
1. Build `$row` with **empty content columns** (`iem_body_plain`/`iem_body_html`/
   `iem_subject`/`iem_sender` = `''`) plus all cleartext metadata. Keep the existing
   `iem_raw_message => ''` off-row behavior.
2. `CreateEntry($row)` → mints `$msg->key` (the id). **No plaintext was written.**
3. Resolve the owner public key (4.3). If none (Standard / no key material): write the
   plaintext content columns as today and stop — no sealing.
4. With key material: `$dek = newMessageDek()`; `iem_sealed_key = sealMessageDek($dek,
   $pub)`; seal each content field via `sealField($plain, $dek, $msg->key, $field)`;
   set `iem_key_generation` = the owner's `iek_key_generation`, `iem_content_sealed = true`;
   **UPDATE** the row with the ciphertext columns + sealed key.
5. Attachment split (`extractAttachmentsToFiles`, already post-save at ~406) seals each
   File's bytes under the **same** `$dek` with `sealAttachment($bytes, $dek, $msg->key,
   $ima_id)` before `File::createFromBytes` writes them (Phase 5).
6. Filters (`runForMessage`, ~417) run **after** — verify (4.2).

Crash window: a row can exist post-step-2 with empty content before step-4 completes.
`iem_content_sealed=false` marks it incomplete; a reader treats an unsealed-but-empty row
as pending, and a re-ingest/backfill can complete it. Do step 4's UPDATE in a transaction
where practical.

### 4.2 Filters — verify, likely no reorder

`InboundEmailFilter::runForMessage($msg, $parsed, $alias)` already receives `$parsed` (the
plaintext parse). **Verify** it matches on `$parsed` (and acts only on cleartext state —
`iem_is_read`, folder, labels), not on `$msg`'s content columns. If it reads `$msg`
content columns, refactor it to take the plaintext explicitly (it already has `$parsed`).
No filtering over *stored* messages is possible post-seal — that's by design.

### 4.3 Owner public-key resolution

Recipient alias (`iem_iea_inbound_email_alias_id`) → the owning user → that user's `iek`
row → `iek_public_key`. For the single-reader (ProtonMail) model, one mailbox has one
key-holding reader; resolve it from the alias's grant (`InboundEmailMailboxGrant`,
`ieg`). **Explicitly out of scope:** sealing one message to *multiple* readers' keys —
if a mailbox ever has multiple key-holders, that's future work (seal the DEK once per
reader). State this limit; do not build it.

### 4.4 Message schema additions

Add to `InboundEmailMessage::$field_specifications`:
```php
'iem_sealed_key'      => array('type'=>'text','is_nullable'=>true),   // DEK sealed to the owner public key
'iem_key_generation' => array('type'=>'int4','is_nullable'=>false,'default'=>0), // 0 = not sealed; matches iek_key_generation when sealed
'iem_content_sealed' => array('type'=>'bool','is_nullable'=>false,'default'=>false),
```
`iem_body_plain`/`iem_body_html` stay `text`, `iem_subject`/`iem_sender` stay their
varchar types but now hold ciphertext — **widen `iem_subject` (varchar(1000)) and
`iem_sender` (varchar(500)) to `text`**, since ciphertext + base64 overheads exceed the
plaintext caps.

### 4.5 Remove SQL text-search filters

In `getMultiResults()` (~332–361) remove the four ILIKE blocks (`subject`, `body`,
`recipient` sender/`sender`) — they scan columns that are now ciphertext. Keep `$dblink`
only if a remaining `quote()`-using filter needs it (`received_since`); otherwise remove
its now-dead fetch. Text search moves to Phase 6.

### 4.6 Outbound / drafts

`MailboxSender::storeOutboundRow()` (~693) seals identically (composing is always
in-window, so the public key — actually only the public key is needed — is available).
Apply the cleartext/sealed split: recipient addresses seal as content; the sending alias
stays cleartext. Drafts stay out of the FTS index.

## Phase 5 — Attachment decrypt hook (File stream)

### 5.1 Seal on write

In `extractAttachmentsToFiles` (~472–524), before `File::createFromBytes($bytes, …,
['fil_private'=>true, 'fil_source'=>File::SOURCE_EMAIL_ATTACHMENT])`, replace `$bytes`
with `sealAttachment($bytes, $dek, $msg->key, $ima_id)`. The File then stores **ciphertext
bytes**; `fil_source = SOURCE_EMAIL_ATTACHMENT` is the marker the decrypt hook keys on.

### 5.2 Decrypt on serve (the hook)

`File` stays crypto-agnostic; introduce a decrypt hook the stream consults. Add a method
`File::resolve_decrypt_hook(): ?callable` that returns a decryptor **iff** `fil_source ==
SOURCE_EMAIL_ATTACHMENT` (dispatch by source so `File` names no email code directly — the
plugin registers the resolver, or `File` calls a well-known plugin entry guarded by
plugin-active). The hook, given the on-disk temp path, returns a plaintext temp path:
1. Resolve the owning message via `ima_fil_file_id` → `InboundMessageAttachment` →
   `iem_inbound_email_message_id`.
2. Require an **open unlock window** for the viewing user (`MailboxUnlock::secretKey`);
   null → the stream returns the locked-state response (a 403/locked, not the ciphertext).
3. `openMessageDek(iem_sealed_key, iek_public_key, secret_key)` → `openAttachment(bytes,
   dek, message_id, ima_id)` → write plaintext to a fresh temp; return it.

Wire it in **both** serve.php branches:
- **Private cloud** (417–448): after `$driver->get(…, $tmp)` (~431) and before
  `serve_from_path($tmp, …)` (~438), if `resolve_decrypt_hook()` is non-null, replace
  `$tmp` with the decrypted temp (unlink the ciphertext temp).
- **Local restricted** (459–483): same interposition before its `serve_from_path` (~469/476).

`serve_from_path()` (283–295) is unchanged — it must receive a **plaintext** temp so
`Content-Length`/`readfile` and mime stay correct. Delete the plaintext temp after serving.

## Phase 6 — Search (FTS5 index manager)

### 6.1 Remove the old FTS

- New migration in `plugins/mailbox/migrations/migrations.php`:
  `DROP INDEX IF EXISTS iem_fulltext_idx;` (the `iem_007` GIN index; a plain migration, so
  `update_database` won't recreate it). Leave `iem_007` in place as history; add a new
  versioned drop entry.
- In `MailboxService::listThreads()` remove the `to_tsvector(...) @@
  websearch_to_tsquery(...)` predicate (477–485) and replace the `q` path with the FTS5
  id-whitelist join (6.3). Replace the preview aggregates (516–517) and the
  `senders`/`latest_subject` aggregates with **in-PHP decryption after `fetchAll`** (only
  runs in-window). `getThread()` (596) decrypts `iem_body_plain`/`iem_body_html`
  in-session before mapping to `body_plain`/`body_html` (642). `buildSnippet()` (573)
  consumes the decrypted preview.

### 6.2 `plugins/mailbox/includes/MailboxIndex.php`

Owns the `/dev/shm` FTS5 lifecycle:
```php
class MailboxIndex {
    public function ensureOpen(int $user_id, string $secret_key): void;  // decrypt sealed blob -> /dev/shm, then fold
    public function fold(int $user_id, string $secret_key): void;        // id > iek_fts_high_water: decrypt fields, insert, advance, SEAL-AFTER-FOLD
    public function search(int $user_id, string $query): array;          // -> [message_id, ...]
    public function rebuild(int $user_id, string $secret_key): void;     // full: decrypt all -> in-memory FTS5 -> seal
    public function wipe(int $user_id): void;                            // delete /dev/shm working copy
    public function shmPath(int $user_id): string;                       // /dev/shm/mailfts_{user_id}.sqlite
}
```
- Index source: body plain + body html + subject + sender + attachment **filenames**.
  Attachment *contents* are never indexed (surface this in search UI copy).
- **Seal-after-fold:** after every fold/update, re-seal the `/dev/shm` file (its own DEK
  sealed to `iek_public_key`) and persist to the sealed blob **immediately**, while the
  key is in hand — never at window close. Store the sealed blob as a **private File**
  referenced by `iek_fts_blob_fil_id` (decision: file, not a row — reuses the private
  store + backup rules, and a years-deep index can be large; revisit only if measured
  size is trivially small).
- **Disposable cache:** the blob is delete-never-repair. Missing/stale/corrupt/rotated →
  `rebuild()`. Ground truth is always the sealed message rows. Measured cost ~2–3s/10k
  messages (design § Search) — losing it is never an error.
- High-water mark = `iek_fts_high_water`; `fold()` advances it.

### 6.3 Query path

`listThreads()` `q` path: require an open window; `MailboxIndex::search()` → ids →
`iem_inbound_email_message_id IN (...)` whitelist joined into the existing
grouping/sorting/paging (unchanged, on cleartext metadata). If locked, the search UI
prompts to unlock rather than erroring.

### 6.4 The `/dev/shm` sweep task

New `plugins/mailbox/tasks/SweepMailboxIndexTemp.php` + `.json`, mirroring
`tasks/PurgeOldErrors.php`. Implements `ScheduledTaskInterface::run(array $config)`:
delete any `/dev/shm/mailfts_*.sqlite` whose unlock window is gone (no live APCu key for
that user/session). `.json` `"default_frequency": "every_run"` (fires each 15-min cron
pass via `utils/process_scheduled_tasks.php`). This is the passive-close safety net —
worst case a working copy lingers one cron interval.

## Phase 7 — No sideways copies (enforce at the boundaries)

- **Inbound log viewer:** verify it logs metadata only (recipient, message-id, verdicts,
  sizes, routing) — never subject/body. Global rule, not per-level.
- **Error paths redact:** ingest (`storeMessage`) and send (`MailboxSender`) exceptions
  logged with the message **reference**, never raw MIME/field content (the Apache error
  log is verbose and long-lived).
- **Admin message viewer:** cleartext metadata always; sealed fields render only in an
  open window through the same decrypt path. Gate on **key possession, not permission** —
  a permission-10 admin (incl. via login-as) with no window sees ciphertext.
- **Spam:** scoring stays pre-seal at receive (Standard/Private) / in deferred ingest
  (Fortress, relay package). **Learning** (`LearnSpamFeedback`) is key-gated — trains only
  in-window via the same poll as AI; no plaintext side-queue. Name the residual: rspamd's
  Bayes store holds hashed token stats outside the sealed columns.
- **AI:** processing log stores references + verdicts, never digest text; `iem_ai_summary`
  and any content-derived output seal as content (levels spec).

## Phase 8 — Key rotation ceremony

`logic/mailbox_rotate_keys_logic.php` (in-window; needs the current secret key):
1. `generateKeypair()` (new pair).
2. Per message (batched, resumable): `openDek(iem_sealed_key, old_pub, old_secret)` →
   `sealDek(dek, new_pub)`; bump `iem_key_generation`. Bodies/attachments untouched.
3. Re-wrap the new secret key under every live `iew` (each passkey PRF KEK, passphrase).
4. Swap `iek_public_key`, bump `iek_key_generation`.
5. Invalidate + regenerate recovery codes; offer fresh key-file export.
6. Delete the sealed FTS blob (`iek_fts_blob_fil_id`) — rebuilds next unlock.

Resumability: `iem_key_generation` vs `iek_key_generation` tells old-sealed from
new-sealed; both keypairs' wrappings coexist until the last message flips, then delete the
old wrappings. Surface as a "Rotate encryption keys" action in the mailbox security UI.

## Phase 9 — Pre-launch backfill

No production users, so no mail to preserve; this converts dev mail / a domain raised from
Standard. One-time in-window pass (a task or admin action): per message, converge to the
lean sealed form — seal content fields, split+seal attachments to Files if the raw still
carries them, then **destroy the raw** (`iem_raw_message = null`, delete the raw store
file). Not marked done (`iem_content_sealed = true`) until the raw is gone. Exemption: rows
whose raw lives at a **remote IMAP** source (the provider holds that copy; IMAP caps at
Private). Idempotent.

## Phase 10 — Settings, deployment, docs

- Settings (`plugins/mailbox/plugin.json` `settings`): `inbound_email_unlock_idle_minutes`
  default `"30"`. (No RP settings — passkeys owns those.)
- **`InboundEmailHealth` checks** (warn when protected domains exist):
  swap off/encrypted; `apc.mmap_file_mask` anonymous; mail FPM pool `rlimit_core = 0`;
  `ext-sqlite3` with FTS5; `ext-apcu` enabled. These are what make APCu residency
  RAM-only and the index temp non-persistent.
- Provisioning: add `php8.3-sqlite3`, `ext-apcu` to `install_email.sh` + Docker images.
- Docs (current-state voice): `plugins/mailbox/docs/overview.md` gains an
  "Encryption at rest" section (sealed-content model, cleartext/sealed split, sealed
  search-index lifecycle — do not narrate the removed GIN FTS); `docs/secret_box.md`
  cross-references `SealedBox`.

## Phase 11 — Verification (acceptance gate)

11.1 `php -l` + `validate_php_file.php` on every new/edited PHP file; resolve all flags.

11.2 Schema: `\d iem_inbound_email_messages` shows `iem_sealed_key`, `iem_key_generation`,
`iem_content_sealed`, `iem_subject`/`iem_sender` now `text`; `iek_`/`iew_` tables exist;
`iem_fulltext_idx` is gone.

11.3 Browser round-trip on `dev.getjoinery.com` (a test mailbox at Private):
- **Setup ceremony:** keypair + recovery codes + key-file export; permanent-loss ack
  required.
- **Receive → at rest:** send a test mail; confirm in psql that `iem_body_plain`/
  `iem_subject`/`iem_sender` are **ciphertext** (not readable), `iem_sealed_key` populated,
  `iem_content_sealed = true`, no `iem_raw_message`.
- **Unlock (passkey):** tap → window opens; thread list previews, subjects, senders render
  (decrypted in PHP); `getThread` shows the body.
- **Search:** `q` returns the right message via FTS5; a locked search prompts to unlock.
- **Attachment:** download decrypts via the hook (both cloud and local File paths);
  locked download is refused, not leaked.
- **Lock / passive close:** `mailbox_lock` wipes; walk-away → APCu TTL expiry + the
  `SweepMailboxIndexTemp` task removes the `/dev/shm` copy within one cron interval.
- **Recovery / passphrase unlock:** each opens the window; a consumed recovery code is
  marked used and can't be reused.
- **Unlocker floor:** revoking the last passkey while below the recovery floor is refused
  with the reason surfaced (the passkey page shows the veto).
- **Rotation:** run it; confirm `iem_key_generation` advances, old key no longer opens new
  arrivals, FTS blob rebuilt on next unlock.
- **Admin viewer:** a permission-10 admin with no window sees ciphertext (key-possession
  gate, not permission).
- **Splice (I5):** copy one message's `iem_body_plain` ciphertext onto another row in
  psql; opening it fails authentication (AD mismatch), not a wrong-plaintext render.

11.4 Provide `batcat` commands for each created file (do not run them).

## Open items the executor confirms against the running system (not decisions)

- Whether `InboundEmailFilter::runForMessage` reads `$msg` content columns or only
  `$parsed` (4.2) — determines if a small filter refactor is needed.
- How the plugin requires/loads its data classes (mirror for `iek`/`iew`), and that plugin
  Sync creates the two new tables.
- The exact plugin-active-guarded entry point `File` may call for the decrypt hook (5.2),
  so `data/files_class.php` names no email symbol directly.
- APCu availability + the three host-hardening facts on dev (health-check them).
- Production base image carries `ext-sqlite3` with FTS5.
- Measured attachment-excluded index size, to confirm the sealed-blob-as-File decision
  (6.2) over a row.
