# Sealed Vault

A per-user encryption identity shared by every feature that seals content the
server should only read while the user has proven presence. One lock (a
passkey, a recovery code, or an optional passphrase), one bounded unlock
window, and any number of consumers behind it — mail was the first, chat is
the second, drive/passwords will be client-custody consumers later. The vault
owns the identity and the lock; each consumer owns what it seals and how it
presents locked state.

## The shape of it

Each user gets an X25519 keypair per **scope** (`uev_scope`; this package
builds the server-custody `user` scope, shared by mail and chat). The
**public** key is cleartext at rest — anything can seal to it, even while the
user is offline. The **secret** key never touches disk unwrapped: it exists
only as **wrappings**, one per enrolled unlocker (a passkey's WebAuthn PRF
output, a recovery code, an optional passphrase), and is unwrapped only
transiently into server RAM for the duration of an **unlock window**.

**One unlock opens everything in that scope.** A single passkey tap puts the
secret key in the window; every server-custody consumer's
`VaultUnlock::secretKey()` call sees it open at once. That is the UX win and
the accepted cost: an attacker resident during an active window reads every
consumer's in-window content, not just one — bounded by the idle timeout,
seal-after-use, and key rotation. A consumer with genuinely higher sensitivity
can enroll a second `uev` scope for isolation instead of sharing `user`.

## Crypto core

`includes/SealedBox.php` — the asymmetric sibling of
[SecretBox](secret_box.md), hard-requiring `ext-sodium` (no OpenSSL fallback;
`crypto_box_seal` has none). Versioned, self-describing base64url blobs, same
philosophy as SecretBox: fail closed, never return half-verified plaintext.

```php
$box = new SealedBox();
$keypair = $box->generateKeypair();               // ['public'=>b64, 'secret'=>b64] X25519
$sealed  = $box->sealDek($bytes, $public_key);     // crypto_box_seal - anyone can seal
$bytes   = $box->openDek($sealed, $public_key, $secret_key);
$blob    = $box->aeadEncrypt($plaintext, $key, $ad);   // xchacha20poly1305_ietf
$plain   = $box->aeadDecrypt($blob, $key, $ad);        // throws on tamper or AD mismatch
$wrapped = $box->wrapKey($secret_key, $kek, $ad);      // same AEAD primitive, wrapping a key
$secret  = $box->unwrapKey($wrapped, $kek, $ad);
$kek     = $box->kekFromRecoveryCode($code, $salt);    // crypto_generichash - fast; entropy is the defense
$kek     = $box->kekFromPassphrase($passphrase, $salt);// crypto_pwhash Argon2id - slow, low-entropy input
$salt    = $box->generateSalt();                       // one uev_salt serves both KDFs above
$code    = $box->generateRecoveryCode();               // 26 Crockford-base32 chars, >=128 bits, grouped
```

`includes/VaultCrypto.php` names the per-item envelope-encryption dance every
consumer repeats, thin over `SealedBox`:

```php
$crypto = new VaultCrypto();
$dek    = $crypto->newItemDek();                        // random 32B, one per content item
$sealed = $crypto->sealItemDek($dek, $public_key);       // store on the consumer's own row
$dek    = $crypto->openItemDek($sealed, $public_key, $secret_key);
$blob   = $crypto->sealField($plaintext, $dek, $ad);     // $ad is the CONSUMER's row-binding string
$plain  = $crypto->openField($blob, $dek, $ad);          // e.g. 'mail:{message_id}:body_plain'
```

The AD (additional data) is entirely the consumer's convention — a stable
per-item identity string. Binding it means a ciphertext can never be spliced
onto a different row and still decrypt.

## Key hierarchy

- **`uev_user_encryption_vaults`** (`UserEncryptionVault`) — one row per
  (user, scope): `uev_public_key` (cleartext), `uev_salt` (shared KDF salt for
  the recovery/passphrase unlockers), `uev_custody` (`server` here; `client`
  is the future drive/passwords shape), `uev_key_generation`.
- **`uew_user_encryption_wrappings`** (`UserEncryptionWrapping`) — one row per
  enrolled unlocker: `uew_unlocker_type` (`passkey`/`recovery`/`passphrase`),
  `uew_wrapped_secret_key` (AEAD-wrapped, AD = `vault:{vault_id}:{wrapping_id}`
  via `UserEncryptionWrapping::adFor()`), `uew_is_used` (recovery codes are
  one-time), `uew_delete_time` (soft delete retires a wrapping).

`UserEncryptionWrapping::createWrapped($vault_id, $type, $secret_key, $kek,
$credential_id = null, $label = null, $key_generation = 1)` is the one place
a wrapping gets created — it two-phase-inserts (the AD needs the row's own
id) so every wrapping is sealed the same way. `$key_generation` defaults to 1
(every enrollment ceremony except rotation targets a not-yet-rotated vault);
rotation passes its computed `new_key_generation` explicitly.

Neither table is an API resource; consumers never touch them directly.

## Enrollment

All in `logic/vault_*_logic.php`, gated on `passkeys_enabled` and a signed-in
session. Every vault endpoint declares `requires_browser_session` (see
[API § Authentication](api.md#authentication)): the unlock window is keyed to
the browser session id, so these actions are reachable only through the
browser-session credential, never an API key — the boundary is stated in the
contract rather than left to fail incidentally.

| Action pair | Purpose |
|---|---|
| `vault_setup_options` / `vault_setup_verify` | First-time setup: generate the keypair, wrap it under the enrolling passkey + N fresh recovery codes + an optional passphrase, open the window. Requires an account password first (see *The vault-activation flip*) and an explicit permanent-loss acknowledgment. |
| `vault_add_passkey_options` / `vault_add_passkey_verify` | Wrap the (already-unlocked) secret key under another PRF-capable passkey. |
| `vault_regenerate_codes` | Invalidate all recovery codes and mint a fresh set. Requires a recent step-up and an unlocked vault. |
| `vault_passphrase_enroll` / `vault_passphrase_remove` | Enroll or remove the optional passphrase fallback. Requires a recent step-up; enroll also requires an unlocked vault. |
| `vault_status` | Read-only: set-up/unlock state and the wrapping list (no secret material) for the keyring UI. |

## The unlock window

`includes/VaultUnlock.php` — the secret key lives in APCu, keyed
`vault:{session_id}:{user_id}:{scope}`, TTL = `vault_unlock_idle_minutes`
(default 30), re-stored on every read (activity extension):

```php
VaultUnlock::open($user_id, $secret_key, $scope = 'user');
VaultUnlock::isOpen($user_id, $scope = 'user'): bool;
VaultUnlock::secretKey($user_id, $scope = 'user'): ?string;  // null = locked
VaultUnlock::close($user_id, $scope = 'user'): void;         // current session
VaultUnlock::lock($user_id, $session_id, $scope = 'user'): void;  // a specific session
VaultUnlock::lockAll($user_id): void;                        // every scope, every session
```

Every content read calls `secretKey()` and treats `null` as **locked** — a
one-tap unlock prompt, never an error. `lock()`/`lockAll()` are the generic
wipe surface; *when* to call them (explicit lock, a credential event, a
heartbeat/IP-change policy, a permission cap) is entirely consumer-defined.

Unlock endpoints (`logic/vault_unlock_options_logic.php` and its
`vault_unlock_passkey` / `vault_unlock_recovery` / `vault_unlock_passphrase`
siblings, plus `vault_lock`) mint the WebAuthn PRF assertion options with
`userVerification: required` (`PasskeyService::getDerivationOptions()`) —
every vault unlock demands device user verification, not merely preferred.

### Host hardening

`includes/VaultHealth.php` checks the three facts that keep an unwrapped
secret key off disk even during a live window: APCu backed by anonymous
shared memory (`apc.mmap_file_mask` unset), the PHP worker's core dumps
disabled (`rlimit_core = 0`), and swap off or encrypted. Best-effort and
advisory (a check that can't be verified reports `unknown`, never a false
pass) — surfaced informationally from `vault_setup_verify` and via
`php maintenance_scripts/dev_tools/check_vault_health.php` (exits non-zero on
any `unmet` check, mirroring `check_provisioning.php`'s convention).

## The unlocker floor + revocation veto

A wrapping delete is refused when it would leave fewer than 1 **live** passkey
wrapping **and** fewer than 3 unused recovery codes — the refusal names what
to enroll first. `VaultUnlock::assertWrappingDeleteSafe($vault_id,
$exclude_credential_id = null)` is the shared counting logic behind every such
refusal: passkey revocation (excluding the credential being revoked from the
count) and passphrase removal (nothing to exclude — a passphrase never counts
toward the floor itself, so removing one only matters when the passkey/recovery
counts are already at the floor). A passkey wrapping counts only if its
credential row is still live (`pkc_delete_time IS NULL`) — belt-and-suspenders
against old data predating the cleanup below.

`VaultUnlock::registerRevocationHooks()` (called once, from
`logic/passkey_revoke_logic.php`) subscribes to both of
`PasskeyService`'s revocation registries:

- **`onPreRevoke`** → `VaultUnlock::assertRevocationSafe()` calls the shared
  floor and throws `PasskeyRevocationVetoException` when it would strand the
  vault; `PasskeyService::revoke()` propagates it without deleting the
  credential.
- **`onPostRevoke`** → `VaultUnlock::cleanupRevokedCredential()` soft-deletes
  every `uew` wrapping tied to the now-revoked credential — a wrapping for a
  dead credential can never be re-derived (its PRF output is gone with it),
  and left alive it would otherwise miscount as a usable passkey in the floor.

Consuming a recovery code to unlock is exempt from the floor, but drops the
vault into `regenerate_recommended` (surfaced by `vault_status` and the unlock
response) once fewer than 3 remain unused.

## The two generic consumer hooks

A server-custody consumer never builds its own decrypt plumbing — it declares
into one of two generic hooks and the vault (or the reader that already
exists) does the rest.

**Sealed-`File` decrypt hook** — a consumer with sealed attachments registers
a decryptor for its `fil_source` tag once, at bootstrap:

```php
File::registerDecryptHook(File::SOURCE_EMAIL_ATTACHMENT, function (string $ciphertext, File $file): string {
    $secret = VaultUnlock::secretKey($file->get('fil_usr_user_id'));
    if ($secret === null) throw new VaultLockedException();
    // ... open the per-item DEK, then the AEAD blob, return plaintext bytes
});
```

`File::serve_from_path()` calls the registered decryptor between reading the
stored bytes and writing the response; a `VaultLockedException` becomes a
generic `423 Locked` response, never a raw error or ciphertext.

**Sealed-field model hook** — a model declares which columns are sealed and
overrides the two decrypt methods `SystemBase` provides as an extension
point:

```php
class InboundEmailMessage extends SystemBase {
    public static $sealed_fields = ['iem_body_plain', 'iem_body_html'];

    protected function decryptSealedField($field, $ciphertext) {
        // read $this->get('iem_sealed_key') / the owning user id from $this,
        // VaultUnlock::secretKey(), VaultCrypto::openItemDek()+openField()
    }

    public static function decryptSealedFieldStatic($field, $ciphertext, array $row) {
        // same, but working from a raw associative row (no $this available) -
        // this is the path plugins/joinery_ai/includes/ModelQueryExecutor.php
        // uses, since it reads rows by SQL without instantiating models
    }
}
```

`SystemBase::get()` calls `decryptSealedField()` automatically whenever the
requested key is listed in `$sealed_fields` — covering ordinary field access
and anything built on it (`export_as_array()`, `export_for_api()`) for free.
`ModelQueryExecutor` (the AI `query_model` tool's raw-row reader) calls
`decryptSealedFieldStatic()` directly, since it never instantiates the model.
Both default to throwing — a model that lists a sealed field but doesn't
override the corresponding method is a programming error, not a silent
ciphertext leak. A locked vault becomes `VaultLockedException` (File hook) or
a `[locked - unlock your vault to view]` placeholder (the raw-row path, so an
LLM sees a legible state rather than a stack trace).

Every field-name pair `$sealed_fields` names, and every `$ad` convention a
consumer builds around them, is entirely the consumer's concern — the vault
provides only the hook.

## Key rotation

`logic/vault_rotate_options_logic.php` / `vault_rotate_verify_logic.php`: a
fresh PRF assertion from an already-enrolled passkey both proves possession
(unwrapping the current secret) and supplies a KEK the ceremony can act on
immediately. From there, in crash-safety order:

1. Generate a new keypair and salt; compute `new_key_generation` (`uev_key_generation + 1`).
2. **Persist the new generation first**, while the old wrappings are still
   live: the authorizing passkey's wrapping, 10 fresh recovery-code
   wrappings, and a resupplied passphrase's wrapping — each tagged
   `uew_key_generation = new_key_generation` — then flip the `uev` row
   (public key, salt, generation, updated time).
3. **Only then** walk every registered consumer's re-seal callback
   (`VaultUnlock::onReseal($callback)`, registration order; signature
   `function(int $user_id, string $old_secret_key, string $new_public_key,
   int $new_key_generation): void`) — the old secret is still in hand to
   open with, the new public key to seal to.
4. **Only after that** soft-delete the previous generation's wrappings.

A crash at any point up through step 3 leaves both generations' wrappings
live and both secrets recoverable — old wrappings still unwrap the old
secret, and each wrapping's own `uew_key_generation` says which secret it
belongs to. This is the resumability guarantee expressed as an ordering
constraint, not a resume endpoint; the consumer packages built on top of this
(mail package) are responsible for making their own re-seal callback
idempotent and keyed on each item's own per-item generation column, so a
retry after a partial crash skips items already flipped rather than re-sealing
them again.

**Every wrapping not re-derivable during this same request is invalidated**,
not left dangling — a KEK for another enrolled passkey can only come from
that passkey's own live WebAuthn assertion, which the ceremony doesn't have.
Leaving such a wrapping in place would let it silently unwrap to the
now-superseded secret. The response lists which passkeys (and whether the
passphrase) need re-adding via the ordinary enrollment endpoints afterward.

## Backups

`uev`/`uew` are never excluded from backup sets — losing them is the one
unrecoverable thing (every consumer's content is otherwise-unreadable
ciphertext). The setup and rotation ceremonies both return a `key_file`
payload (the wrapped-key rows, public key, and salt) for the client to offer
as a download — useless without a live unlocker, but the thing that makes a
restored backup's wrappings reconstructible if a `uew` row is ever lost
independently of the vault row itself.

## The consumer contract (server-custody)

1. Seal content with `VaultCrypto`, storing a per-item `*_sealed_key` on your
   own rows and using your own AD row-binding convention.
2. Read via `VaultUnlock::secretKey($user_id)`; treat `null` as locked — a
   one-tap prompt, never an error.
3. Reuse the File decrypt hook for sealed attachments (`File::registerDecryptHook`)
   and the sealed-field model hook for generic reads (`$sealed_fields` +
   `decryptSealedField()`/`decryptSealedFieldStatic()`).
4. Register a re-seal callback for rotation (`VaultUnlock::onReseal()`).
5. Register a wipe callback if you keep any disposable in-window cache
   (`VaultUnlock::onWipe()`), e.g. a plaintext search index.
6. Own your own levels, scope, and locked-state surfaces (list placeholders,
   a content-action unlock prompt, a native `locked` flag) — the vault
   provides everything below the content.

**One unlock opens every server-custody consumer** — the accepted tradeoff. A
consumer with a genuinely higher sensitivity bar may enroll a second `uev`
scope instead of sharing `user`.

## The vault-activation flip

A passkey never opens both session sign-in and the vault on the same account
— the platform-wide rule is stated in [Account
Security](account_security.md); this section is the vault's half of the
mechanics. `vault_setup_options`/`vault_setup_verify` refuse to start until the
account has a working password (prompting the user to set one via the
existing password-change flow first) — a vault holder always keeps password
sign-in as the second factor alongside their passkey.

The other half of the flip: once an account has a vault, its passkey stops
signing it in. `logic/passkey_login_verify_logic.php` checks
`UserEncryptionVault::loadForUser($user_id)` right after the WebAuthn
assertion verifies and, if a vault exists, undoes the session
`PasskeyService::verifyAuthentication()` just established and rejects with a
message pointing the user at their password. `logic/passkey_login_options_logic.php`
makes the same check for an email-scoped request (the discoverable/usernameless
flow can't know the account in advance, so the verify-side check is the actual
enforcement — the options-side check is only an earlier, friendlier rejection
for the common case). Passkey-as-step-up and passkey-as-vault-unlock remain
available on every account regardless of vault status — only passwordless
sign-in is withdrawn.

## Settings

- `vault_unlock_idle_minutes` (default `30`) — the unlock window's idle
  timeout.

No RP-ID, origin, or PRF-context setting here — see [Passkeys](passkeys.md)
for those (the vault uses the `vault-kek` PRF context).

## Client-custody scopes (not built here)

`uev_scope` (`drive`, `passwords`) and `uev_custody = 'client'` ship as
columns now so the shape is fixed, but the browser-only unlock (a client
crypto module, a vendored Argon2id WASM for the passphrase fallback, the
`vault-drive-kek`/`vault-passwords-kek` PRF contexts) is built by those
consumers when they land. This package never unwraps a `uev_custody =
'client'` secret key server-side.
