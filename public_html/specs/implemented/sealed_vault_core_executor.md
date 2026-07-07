# Sealed Vault — Core — Executor Package

**Status:** Ready for implementation
**Version:** 1.0
**Design authority:** `specs/sealed_vault_core.md` (v1.0). This is the *how* for the shared
per-user encryption capability every consumer plugs into. Built **once**; mail, AI chat, and
later drive/passwords consume it.
**Depends on (build first):** `specs/passkeys_core_executor.md` (the `vault-kek` PRF context).
**Consumed by:** `inbound_email_encryption_at_rest_executor.md` (Phases 4–10),
`joinery_ai_chat_encryption.md`, future drive/passwords.

This package builds the crypto core, the per-user key hierarchy, the unlock window, the two
generic hooks, key rotation, and backups. A **consumer** package builds only *what it seals*,
*its levels/scope*, and *its locked-state surfaces* — everything below the content is here.

> **v1.3 doctrine delta (`specs/mailbox_security_levels.md` § Authentication /
> § The Unlock Window) — fold in while building:** (1) every vault unlock
> ceremony sets `userVerification: required` on its assertion options
> (`PasskeyService::getDerivationOptions()` call site); (2) `VaultUnlock`
> exposes a generic wipe surface — `lock($user_id, $session_id)` and
> `lockAll($user_id)` — that consumer-policy events call (explicit lock,
> credential events, heartbeat loss, IP change, caps); end-of-window *policy*
> is consumer-defined, the mechanism just makes wiping callable. (3) **The
> vault-activation flip:** a passkey never opens both session and vault on
> the same account — the enrollment ceremony this package builds must first
> verify the account has a working password (prompt to set one if not) and
> then disable sole passkey sign-in for the account (`passkey_login_verify`
> rejects vault holders; the login button hides for them). Passkey-as-2FA
> remains allowed always; no-vault accounts keep passkey sign-in untouched.
> If passkey-based password reset exists by the time this builds, it follows
> the same rule: a vault holder's reset ceremony requires the second factor
> (levels spec § Password reset & account recovery).

## Phase 0 — Preflight & host dependencies (core)

Branch `sealed-vault-core`. Dependencies (were previously scoped to the mail package; they
are **core** now):
- **`ext-sodium`** — present. The vault **hard-requires** it (`crypto_box_seal` has no clean
  fallback); `SealedBox` throws at construction if absent (unlike `SecretBox`'s OpenSSL
  fallback).
- **`ext-apcu`** — new. Holds the unwrapped secret key for the unlock window. Its three
  host-hardening facts are a **core** provisioner health check (Phase 3.3).
- Declare both in `public_html/composer.json` `require` (`"ext-sodium": "*"`, `"ext-apcu": "*"`).

## Phase 1 — Crypto helpers

### 1.1 `includes/SealedBox.php` (asymmetric sibling to `SecretBox`)

Instance class, `chmod 666`, `SecretBox`-style (versioned base64url blobs, `RuntimeException`
on error, fail-closed), **hard sodium requirement**. Surface:
```php
class SealedBox {
    public function __construct();                                   // throws if ext-sodium absent
    public function generateKeypair(): array;                        // ['public'=>b64,'secret'=>b64] X25519
    public function sealDek(string $bytes, string $public_key): string;                     // crypto_box_seal (arbitrary bytes)
    public function openDek(string $sealed, string $public_key, string $secret_key): string; // crypto_box_seal_open
    public function aeadEncrypt(string $plaintext, string $key, string $ad): string;          // xchacha20poly1305_ietf, random 24B nonce in-blob, AD not stored
    public function aeadDecrypt(string $blob, string $key, string $ad): string;               // throws on AD mismatch/tamper (splice defense)
    public function wrapKey(string $secret_key, string $kek, string $ad): string;
    public function unwrapKey(string $wrapped, string $kek, string $ad): string;
    public function kekFromRecoveryCode(string $code, string $salt): string;                  // crypto_generichash (no slow KDF; entropy is the defense)
    public function kekFromPassphrase(string $passphrase, string $salt): string;              // crypto_pwhash Argon2id, >=INTERACTIVE
    public function generateRecoveryCode(): string;                  // 26 Crockford-base32 chars (>=128 bits), grouped
}
```

### 1.2 `includes/VaultCrypto.php` (generic AD orchestration)

Thin over `SealedBox`; owns the per-item DEK dance. **The AD is consumer-supplied** (a stable
per-item row-binding string, e.g. mail `mail:{id}:{field}`, chat `chat:{id}:body`), so the
convention lives in one place but the vault names no consumer:
```php
class VaultCrypto {
    public function newItemDek(): string;                            // random 32B
    public function sealItemDek(string $dek, string $public_key): string;
    public function openItemDek(string $sealed, string $public_key, string $secret_key): string;
    public function sealField(string $plaintext, string $dek, string $ad): string;
    public function openField(string $blob, string $dek, string $ad): string;
}
```
Cross-reference both from `docs/secret_box.md` as the per-user asymmetric layer above `SecretBox`.

## Phase 2 — Key hierarchy (core tables + enrollment)

Two core data classes, `chmod 666`. After creating, run `update_database` to create the
tables.

### 2.1 `data/user_encryption_vaults_class.php`

`UserEncryptionVault`, prefix `uev`, table `uev_user_encryption_vaults`:
```php
'uev_user_encryption_vault_id' => array('type'=>'int8','is_nullable'=>false,'serial'=>true,'is_primary_key'=>true),
'uev_usr_user_id' => array('type'=>'int8','is_nullable'=>false,'index'=>true,
                            'foreign_key'=>array('table'=>'usr_users','column'=>'usr_user_id','on_delete'=>'CASCADE')),
'uev_scope'       => array('type'=>'varchar(32)','is_nullable'=>false,'default'=>'user'),  // 'user' (server-custody: mail/chat) | 'drive' | 'passwords' (each client-custody, separate keypair)
'uev_custody'     => array('type'=>'varchar(10)','is_nullable'=>false,'default'=>'server'),  // 'server' | 'client' (see design § Two Custody Modes)
'uev_public_key'  => array('type'=>'text','is_nullable'=>false),
'uev_salt'        => array('type'=>'text','is_nullable'=>false),
'uev_kdf_params'  => array('type'=>'text','is_nullable'=>true),   // JSON Argon2id params for client-custody's browser KDF; null for server-custody
'uev_key_generation' => array('type'=>'int4','is_nullable'=>false,'default'=>1),
'uev_created_time'   => array('type'=>'timestamp(6)','default'=>'now()'),
'uev_updated_time'   => array('type'=>'timestamp(6)','is_nullable'=>true),
```
Partial-unique on `(uev_usr_user_id, uev_scope)`. Secret key never stored here.
**This package builds server-custody only** (scope `user`, `uev_custody='server'`, the
`VaultUnlock`/APCu window below, PRF context `vault-kek`). The `uev_custody`/`uev_kdf_params`
columns and the `drive`/`passwords` scopes ship now so the table shape is fixed, but the
**client-custody build** (the shared browser crypto module, the per-scope `vault-drive-kek` /
`vault-passwords-kek` contexts, in-browser unlock) is a client-side build owned by the
client-custody consumers — `specs/drive_encryption.md` / `specs/password_vault.md` — reusing this
same `uev`/`uew` identity storage, each with its **own** keypair (Drive and passwords are
separate scopes). Do not build it here; do not unwrap a `uev_custody='client'` secret key
server-side, ever.
`$api_readable`/`$api_writable` default false. `$permanent_delete_actions = array();`.

### 2.2 `data/user_encryption_wrappings_class.php`

`UserEncryptionWrapping`, prefix `uew`, table `uew_user_encryption_wrappings`:
```php
'uew_user_encryption_wrapping_id' => array('type'=>'int8','is_nullable'=>false,'serial'=>true,'is_primary_key'=>true),
'uew_uev_user_encryption_vault_id'=> array('type'=>'int8','is_nullable'=>false,'index'=>true,
                                             'foreign_key'=>array('table'=>'uev_user_encryption_vaults','column'=>'uev_user_encryption_vault_id','on_delete'=>'CASCADE')),
'uew_unlocker_type'      => array('type'=>'varchar(16)','is_nullable'=>false),   // 'passkey'|'recovery'|'passphrase'
'uew_pkc_credential_id'  => array('type'=>'int8','is_nullable'=>true),
'uew_wrapped_secret_key' => array('type'=>'text','is_nullable'=>false),          // AEAD-wrapped X25519 secret, AD {vault id, wrapping id}
'uew_key_generation'     => array('type'=>'int4','is_nullable'=>false,'default'=>1),
'uew_is_used'            => array('type'=>'bool','is_nullable'=>false,'default'=>false),  // recovery: one-time
'uew_label'              => array('type'=>'varchar(255)','is_nullable'=>true),
'uew_created_time'       => array('type'=>'timestamp(6)','default'=>'now()'),
'uew_used_time'          => array('type'=>'timestamp(6)','is_nullable'=>true),
'uew_delete_time'        => array('type'=>'timestamp(6)','is_nullable'=>true),
```
Not an API resource.

### 2.3 Enrollment (core, in-window)

Core logic endpoints (not consumer-owned):
- **Setup ceremony** `logic/vault_setup_logic.php`: generate keypair; per-user salt; N
  recovery codes (default 10); wrap the secret key under each recovery code, the optional
  passphrase, and the enrolling passkey's `vault-kek` PRF output
  (`PasskeyService::verifyDerivation('vault-kek')`); write `uev` + `uew` rows; open the
  window; offer the key-file export + printed codes; **force explicit permanent-loss
  acknowledgment**. This runs once per user (scope `user`); consumers trigger it if not done.
- **Add passkey wrapping** `logic/vault_add_passkey_logic.php`, **regenerate codes**
  `logic/vault_regenerate_codes_logic.php`, **enroll/remove passphrase** — all in-window.

### 2.4 Unlocker floor + revocation veto (core)

A wrapping delete is refused at the deletion point when it would leave `<1` passkey wrapping
**AND** `<3` unused recovery codes (the refusal names what to enroll first; consuming a code
to unlock is exempt but counts toward a forced-regeneration prompt). Subscribe to the passkey
**revocation veto hook** and throw `PasskeyRevocationVetoException($reason)` when a revocation
would strand the vault.

## Phase 3 — The unlock window (core, APCu)

### 3.1 `includes/VaultUnlock.php`

```php
class VaultUnlock {
    public function open(int $user_id, string $secret_key, string $scope='user'): void;  // apcu_store keyed to session, TTL = idle setting
    public function isOpen(int $user_id, string $scope='user'): bool;
    public function secretKey(int $user_id, string $scope='user'): ?string;               // fetch + re-store (activity extension); null=locked
    public function close(int $user_id, string $scope='user'): void;                      // apcu_delete + consumer wipe callbacks
}
```
APCu key `vault:{session_id}:{user_id}:{scope}`, value = raw secret key, TTL =
`vault_unlock_idle_minutes` (default 30), re-stored on each `secretKey()`. **One window, one
key, every consumer** — a single unlock opens all content that seals to that scope's keypair.

### 3.2 Unlock/lock endpoints (core, sessioned)

| File (`logic/…_logic.php`) | Purpose |
|---|---|
| `vault_unlock_options` | `PasskeyService::getDerivationOptions($user,'vault-kek')` |
| `vault_unlock_passkey` | assertion → `verifyDerivation('vault-kek')` → find `uew` passkey wrapping → `unwrapKey` → `VaultUnlock::open` |
| `vault_unlock_recovery` | `{code}` → `kekFromRecoveryCode` → try unused recovery `uew` → mark used → open |
| `vault_unlock_passphrase` | `{passphrase}` → `kekFromPassphrase` → unwrap → open |
| `vault_lock` | `VaultUnlock::close` |

### 3.3 Host hardening (core health check)

`VaultHealth` verifies, when any user has a vault: `apc.mmap_file_mask` anonymous (not
file-backed); mail/app FPM pool coredumps off (`rlimit_core = 0`); swap off or encrypted.
These keep the unwrapped key off any disk path (design § Key storage).

## Phase 4 — The two consumer hooks + re-seal callback

- **Sealed-`File` decrypt hook** (generic): a `File` whose `fil_source` maps to a registered
  consumer decryptor is decrypted in-window before serving. Wire it into both `serve.php`
  branches (private-cloud + local-restricted) between byte-fetch and `serve_from_path`, and
  `File::resolve_decrypt_hook()` dispatches by source so `File` names no consumer. (This is
  the hook the mail package specified; it lives here as a generic seam consumers register into.)
- **Sealed-field model hook** (generic): a model that declares sealed fields is read through
  an in-window decrypt accessor by generic readers (`ModelQueryExecutor`, exports). Consumers
  declare their sealed fields + decryptor.
- **Re-seal callback registry**: consumers register how to re-seal their per-item DEKs; the
  key-rotation ceremony (Phase 5) walks every registered consumer. Mirror the revocation-veto
  hook's mechanism.

## Phase 5 — Key rotation ceremony (core)

`logic/vault_rotate_logic.php` (in-window): generate a fresh keypair; **walk every consumer's
re-seal callback** to re-seal its per-item DEKs (open with old secret, seal to new public,
bump the consumer's per-item key-generation); re-wrap the new secret key under every `uew`;
swap `uev_public_key`, bump `uev_key_generation`; invalidate + regenerate recovery codes;
offer a fresh key-file export; delete disposable caches (e.g. mail's FTS blob) via consumer
callbacks. Resumable via per-item key-generation vs `uev_key_generation`; old wrappings
deleted only after the last item flips.

## Phase 6 — Backups & key-file export (core)

Key-material tables (`uev`/`uew`) are **never** excluded from backup sets (their loss is the
one unrecoverable thing). The setup ceremony offers a downloadable key-file (the wrapped-key
rows — useless without an unlocker). A leaked backup exposes no content (all consumer content
is ciphertext); a lost backup's key rows are unrecoverable even with every unlocker in hand.

## Phase 7 — Consumer contract (reference)

A consumer: (1) seals content with `VaultCrypto` using its own AD row-binding string, storing
a per-item `*_sealed_key` on its own rows; (2) reads via `VaultUnlock::secretKey()` and treats
null as locked; (3) reuses the File decrypt hook for sealed attachments; (4) reuses the
sealed-field model hook for generic reads; (5) registers a re-seal callback for rotation; (6)
owns its own levels, scope, and locked-state surfaces. **One unlock opens every consumer** —
the accepted tradeoff (design § One unlock opens everything). A high-sensitivity consumer may
enroll a second `uev` scope for isolation (design § One Key, or More).

## Phase 8 — Settings & docs

- Settings: `vault_unlock_idle_minutes` (default `30`).
- New `docs/sealed_vault.md` — the capability, the consumer contract, the one-unlock-all
  tradeoff, the second-keypair escape hatch. `docs/passkeys.md` — the `vault-kek` context.

## Phase 9 — Verification (acceptance gate)

`php -l` + `validate_php_file.php` on every file. `\d uev_user_encryption_vaults` /
`uew_user_encryption_wrappings` exist. On dev: setup ceremony (keypair + codes + key-file);
unlock via passkey/recovery/passphrase each opens the window; a stable per-credential
`vault-kek` output; the unlocker floor refuses a stranding delete; rotation re-seals a
registered consumer's items; **one unlock opens two consumers** (mail + chat) — the headline
check. `batcat` for each file.

## Open items the executor confirms against the running system

- Final `uev`/`uew` prefixes; whether `uev_scope` ships now (recommend yes — fixes the shape
  for the passwords escape hatch).
- The re-seal-callback + File-decrypt-hook registration mechanism (a signal, or a registry) —
  keep consistent with the revocation-veto hook.
- The exact `SessionControl` accessor for the challenge/session plumbing (shared with the
  passkeys package).
