# Sealed Vault

A per-user encryption identity shared by every feature that seals content the
server should only read while the user has proven presence. One lock (a
passkey, a recovery code, or — only where no passkey can hold a key — a
passphrase), one touch that opens every vault the person holds (see
[One vault](#one-vault)), one bounded unlock window, and any number of consumers behind it — mail, AI chat,
[protected conversations](social_features.md#protection-levels) and
[Drive's Private files](drive_encryption.md#private-files--server-custody) seal
server-custody content; the [password manager](../plugins/vault/docs/overview.md)
and [Drive's Fortress folders](drive_encryption.md) are client-custody consumers
(their keys are unwrapped only in the browser). The vault owns the identity and
the lock; each consumer owns what it seals and how it presents locked state.

Consumers register their hooks from a bootstrap file the vault loads lazily.
Who consumes the vault is instance configuration, not a list in core: a plugin
declares itself under `vaultConsumer` in its `plugin.json`, and a core consumer
— Drive is one, since it has no plugin — in `vault_consumers.json` at the
`public_html/` root. See [Registering a consumer](#registering-a-consumer), and
[Building a vault consumer](plugin_developer_guide.md#building-a-vault-consumer)
for the developer-facing walkthrough.

## The shape of it

Each user gets an X25519 keypair per **scope** (`uev_scope`). `user` is the
server-custody scope, shared by mail, chat and every other consumer the server
reads for while the member is present; every other scope is client custody. The
**public** key is cleartext at rest — anything can seal to it, even while the
user is offline. The **secret** key never touches disk unwrapped: it exists
only as **wrappings**, one per enrolled unlocker (a passkey's WebAuthn PRF
output, a recovery code, a passphrase), and is unwrapped only transiently into
server RAM for the duration of an **unlock window**.

**A passphrase only where no passkey can hold the key.** A phrase can be
guessed and phished where a tapped passkey cannot, so an account holds one
only when `Passkey::userNeedsPassphraseFallback()` says every passkey it has is
provably unable to derive a key (see
[When a passkey cannot hold the key](#when-a-passkey-cannot-hold-the-key)).
An account with a working passkey has no passphrase: a lost device is what the
recovery codes are for. `vault_passphrase_enroll`, `vault_client_setup` and
`VaultCeremonies::setup()`/`rotate()` refuse one otherwise, and a passkey
unlock removes any phrase an account no longer qualifies for, telling the
person why.

**One unlock opens everything.** A single passkey tap puts the account
secret key in the window and, from the same touch, opens the root vault in
the browser, which opens every browser-held vault ([One vault](#one-vault)); every server-custody consumer's
`VaultUnlock::secretKey()` call sees it open at once. That is the UX win and
the accepted cost: an attacker resident during an active window reads every
consumer's in-window content, not just one — bounded by the idle timeout,
seal-after-use, and key rotation. A consumer that needs genuine isolation
declares a **client-custody** scope of its own and accepts that no server-side
feature can ever read it; isolation *with* server readability is the one
combination the platform does not offer.

## One vault

*(Design and decisions: [specs/one_vault_experience.md](../specs/one_vault_experience.md).)*

Two kinds of key stay two — the **account vault** (`user`, server custody,
opened in server RAM) and the **browser-held vaults** (client custody) — but a
person has one thing to unlock, one set of recovery codes, and at most one
passphrase.

**The root vault.** `root` is a client-custody scope (`vault_scopes.json`)
that every unlocker opens: each passkey's second PRF output from the same
touch (`prf.eval.second`, context `vault-root-kek`), each recovery code's root
half, and the passphrase's root half where one exists. A **content vault**
(`mail`, `drive`, `passwords`, …; `VaultScopes::contentScopes()`) holds one
`root` wrapping: its secret under `HKDF(root secret, 'joinery-vault:scope:v1:'
+ scope)`, derived only inside the root's keyring session
(`session.scopeKek(scope)`), so each content vault has its own key and the
root's secret never leaves its closure. `VaultClientCustody::persistWrappings()`
enforces the split: the root takes only unlockers, and a content vault that
opens through the root takes only its `root` wrapping. A content vault made
before the root keeps its own unlockers (its rotation still writes them); it
opens once by its own ceremony and is then given its `root` wrapping.

**One touch.** `vault_unlock_options {with_root}` asks for both PRF outputs.
`JoineryPasskeys.derive()` takes the second out of the response it hands back,
so the posted credential carries the first only, exactly as the server has
always verified it; `VaultCeremonies::assertNoSecondPrfOutput()` refuses a
response that still carries the second. The browser opens the root with the
second output and every content vault through the root
(`JoinerySealed.openAllThroughRoot()`). An authenticator that returns only the
first output gets a second touch for the root alone
(`vault_client_prf_options {scope: root}` — the same salt). A passkey that
opens the account vault but has no root wrapping yet asks once for a passkey
that does; the root then learns the first passkey from the output already in
hand (`vault_client_add_wrapping`, no step-up for a passkey the account vault
already has).

**One set of recovery codes, never seen by the server.** The browser makes the
codes (`VaultKeyring.makeCodeSet`) and derives two halves from each, both
salted by the **root vault's salt**: the account half `HKDF-SHA256(code, salt,
'joinery-vault:recovery:account:v1')`, posted as `code_set: {id, entries:
[{index, kek}]}`, and the root half `SHA-256(salt ‖ code)`, which wraps the
root's secret in the browser. Every wrapping from a set carries its id and the
code's index (`uew_code_set`, `uew_code_index`) on both vaults.
`vault_unlock_recovery {code_kek}` finds the account wrapping the posted half
opens and, in one transaction, spends it and its root twin, returning the twin
(`root_wrapping`) for the browser to open. A use also ends every window,
stamps `uev_recovery_time` on every vault the person holds (a reload's resume
half kept before it is refused — `VaultClientResume::get()`), and clears the
vault keys of every linked device (`VaultClientCustody::forgetDevices()`).
Setup, rotation, regeneration and the code-set adoption below always change
both halves in one transaction: the root is created with the account vault
(`VaultClientCustody::createVault()` inside `VaultCeremonies::setup()`), and
its twins are replaced with the account's codes
(`VaultClientCustody::replaceRootRecovery()`). The key file names the code
salt and derivation (`code_kdf`), so a code plus the file still rebuilds the
account key offline.

**One passphrase.** One Argon2id run over the phrase with the root salt and
KDF params; HKDF splits the result into the account half
(`joinery-vault:passphrase:account:v1`, posted as `passphrase_kek`) and the
root half (`…:root:v1`, kept). What it costs, said plainly: a server that has
been broken into holds the account half from the next passphrase unlock and
can guess phrases offline; the same guess yields the end-to-end keys. For a
passphrase account, end-to-end content is as strong as the phrase against a
compromised server, and the passphrase form says so.

**One setup.** The Security page and the setup wizard make the account vault,
the root vault and the codes in one step (`VaultKeyring.setupVault()`, over
`vault_setup_verify` / `vault_setup_passphrase` with `code_set` and `root`). A
content vault is created silently the first time a feature needs it while the
root is open (`VaultKeyring.contentSession()`): a keypair in the browser and
its `root` wrapping, nothing to set up and nothing to remember. A root vault
is never created on its own beside an account vault (a second set of codes):
`createVault()` refuses it.

**An account vault whose codes carry no set id** (codes the server made) gets
a browser-made set, and the root vault, at its next passkey unlock:
`vault_unlock_passkey {adopt_code_set, root}` opens under the tapped passkey
with the new wrappings in its wrap list and creates the root in the same
transaction (`UserEncryptionWrapping::adoptCodeSet()`); the padlock then shows
the new codes once.

**One name.** Everything a person reads says "vault". The registry labels
(`vault_scopes.json`, a plugin's `vaultScopes`) name each scope on the
Security page's detail list and in logs.

## Crypto core

`includes/SealedBox.php` — the asymmetric sibling of
[SecretBox](secret_box.md), hard-requiring `ext-sodium` (no OpenSSL fallback;
`crypto_box_seal` has none). Versioned, self-describing base64url blobs, same
philosophy as SecretBox: fail closed, never return half-verified plaintext.

```php
$box = new SealedBox();
$keypair = $box->generateKeypair();               // ['public'=>b64, 'secret'=>b64] X25519
$sealed  = $box->sealDek($bytes, $public_key);     // crypto_box_seal - anyone can seal
$bytes   = $box->openDek($sealed, $secret_key);       // public key derived from the secret
$blob    = $box->aeadEncrypt($plaintext, $key, $ad);   // xchacha20poly1305_ietf
$plain   = $box->aeadDecrypt($blob, $key, $ad);        // throws on tamper or AD mismatch
$wrapped = $box->wrapKey($secret_key, $kek, $ad);      // same AEAD primitive, wrapping a key
$secret  = $box->unwrapKey($wrapped, $kek, $ad);
$kek     = $box->kekFromRecoveryCode($code, $salt);    // crypto_generichash; the readiness dry run of a vault with no root vault
$salt    = $box->generateSalt();                       // a vault row's uev_salt
$code    = $box->generateRecoveryCode();               // 26 Crockford-base32 chars, >=128 bits, grouped
```

The server derives no KEK from a code or a phrase a person holds: the browser
does, and posts only the account half (see [One vault](#one-vault)).
`includes/VaultUnlockerKdf.php` writes those derivations out in PHP for the
tests to build exactly what a browser sends; nothing in production calls it.

```php
$account = VaultUnlockerKdf::codeKekAccount($code, $root_salt_b64);  // HKDF-SHA256, info joinery-vault:recovery:account:v1
$root    = VaultUnlockerKdf::codeKekRoot($code, $root_salt_b64);     // SHA-256(salt ‖ code) = VaultCrypto.kekFromRecoveryCode
[$a, $r] = VaultUnlockerKdf::passphraseSplit($argon2id_output);     // HKDF per half
$kek     = VaultUnlockerKdf::scopeKek($root_secret, 'mail');         // a content vault's key from the root
```

`includes/VaultCrypto.php` names the per-item envelope-encryption dance every
consumer repeats, thin over `SealedBox`:

```php
$crypto = new VaultCrypto();
$key    = VaultUnlock::secretKey($user_id);              // ?VaultKey — null means locked
$dek    = $crypto->newItemDek();                        // random 32B, one per content item
$sealed = $crypto->sealItemDek($dek, $key->publicKey()); // store on the consumer's own row
$dek    = $crypto->openItemDek($sealed, $key);           // one row; memoized per key id + blob
$deks   = $crypto->openItemDeks($sealed_list, $key);     // a page of rows in ONE VaultKey::unseal()
$blob   = $crypto->sealField($plaintext, $dek, $ad);     // $ad is the CONSUMER's row-binding string
$plain  = $crypto->openField($blob, $dek, $ad);          // e.g. 'mail:{message_id}:body_plain'
$crypto->sealFieldFile($src, $dst, $dek, $ad);           // whole FILE, path-to-path, memory bounded
$crypto->openFieldFile($src, $dst, $dek, $ad);           //   by a chunk (SealedBox v1.stream. format)
```

The AD (additional data) is entirely the consumer's convention — a stable
per-item identity string. Binding it means a ciphertext can never be spliced
onto a different row and still decrypt.

**A vault key is used, never read.** `VaultUnlock::secretKey()` returns a
`VaultKey` (`includes/VaultKey.php`): `unseal(array $sealed)` opens
crypto_box_seal ciphertexts sealed to its public half, `publicKey()` is that
half, and `id()` is a stable non-secret identity the DEK memo keys on. There
is no getter for the bytes and no wrap method. `PoolVaultKey` is the
implementation that holds the bytes in PHP; the `SealedBox` primitives that
take or produce a vault secret (`openDek`, `openBinary`, `unwrapKey`,
`wrapKey`, `generateKeypair`) are pinned to that one file by
`tests/vault/sealed_read_paths_test.php`, so a consumer cannot reach the raw
secret by any path the tree contains. Wrapping the secret under a new
unlocker happens only inside `VaultUnlock::open()`/`openKey()`, in the
request that presented an unlocker for it.

## Key hierarchy

- **`uev_user_encryption_vaults`** (`UserEncryptionVault`) — one row per
  (user, scope): `uev_public_key` (cleartext), `uev_salt` (the current
  generation's KDF salt for the recovery/passphrase unlockers), `uev_custody`
  (`server` for mail/chat; `client` for the browser-only password/Drive scopes),
  `uev_key_generation`.
- **`uew_user_encryption_wrappings`** (`UserEncryptionWrapping`) — one row per
  enrolled unlocker: `uew_unlocker_type` (`passkey`/`recovery`/`passphrase`),
  `uew_wrapped_secret_key` (AEAD-wrapped, AD = `vault:{vault_id}:{wrapping_id}`
  via `UserEncryptionWrapping::adFor()`), `uew_salt` (the KDF salt this
  wrapping's KEK was derived under — recovery/passphrase only, null for
  passkeys — so a rotation replacing `uev_salt` never strands a live
  wrapping), `uew_key_generation` (which generation's secret it wraps),
  `uew_is_used` (recovery codes are one-time), `uew_delete_time` (soft delete
  retires a wrapping).

A wrapping is made in two phases because its AD binds the row's own id.
`UserEncryptionWrapping::reserve($vault_id, $type, $credential_id = null,
$label = null, $key_generation = null, $salt = null)` saves the row with an
empty wrapping; the row's `wrapEntry($kek)` goes into the wrap list of the
`VaultUnlock::open()`/`openKey()` call that unwraps (or mints) the secret;
`storeWrapped()` (or `storeWrappings()` for a batch) persists what that call
returned. Nothing else produces a wrapping: the secret is wrapped only in the
request that presented an unlocker for it, or that minted it. `$key_generation`
null resolves to the vault's current generation (correct for every enrollment
ceremony); rotation passes its computed `new_key_generation` explicitly.
Unlock paths derive each wrapping's KEK from the wrapping's own `uew_salt`
(falling back to `uev_salt` for a null) and hand `unlocker($kek)` to
`open()`, so codes and passphrases from a not-yet-drained generation keep
working in a two-generation state.

Neither table is an API resource; consumers never touch them directly.

## Every account holds one; using it is optional

Holding a vault and using a vault are separate things. A vault that seals
nothing costs its holder nothing, so the setup wizard's `encryption_key` step
is mandatory and offers no decline — an estate where every account already
holds a key needs no capability check before offering a private folder, a
sealed mailbox, or a protected conversation.

Two conditions stand between an account and a vault, and code that assumes
universal vaults must still handle them:

- **No PRF-capable passkey.** `Passkey::vault_capability()` answers
  `incapable` for an authenticator that cannot derive a secret. See the
  fallback below.
- **No account password.** `vault_setup_options` refuses with
  `requires_password`, because a vault holder keeps password sign-in as the
  second door.

The wizard routes around both with a `SetupDecision` row so the account can
still finish setup, and keeps offering the ceremony afterwards. What an account
without a vault is missing, until it has one:

- Mail is stored unsealed — readable to anyone who can reach the database or
  a backup archive.
- No private Drive folders (`logic/drive_folder_create_logic.php` refuses) and
  no saved passwords (the keyring is sealed under this key).
- No encrypted chat. A conversation cannot be raised to Private
  while any member holds no vault — `Conversation::members_without_vault()`
  names them, so one member without a key caps the whole conversation.

Creating a vault later turns all of it on; the decision row is only a
tie-breaker and real state always wins over it.

## When a passkey cannot hold the key

*(Rationale and the rejected alternatives: [specs/vault_passphrase_fallback.md](../specs/vault_passphrase_fallback.md).)*

PRF is a narrower requirement than passkey support: iPhones before iOS 18,
Windows 10, older Firefox, older Android and most security keys enrol a passkey
happily and then cannot derive a secret from it. For those accounts a vault is
bootstrapped under a **passphrase** instead, with no `TYPE_PASSKEY`
wrapping at all; the same phrase opens the root vault
([One vault](#one-vault)).

This is a compatibility fallback, never a preference. A phrase can be guessed
and phished where a tapped passkey cannot, so an account that could use a
passkey must:

- `Passkey::userNeedsPassphraseFallback()` is the single gate: the account
  holds at least one credential **and every one of them is provably
  incapable**. The count requirement closes the owning-nothing route: an
  account with no credentials at all is not eligible, so deleting your passkeys
  is not a way to opt into weaker crypto.
- The **failure stamp** comes only from a verified ceremony.
  `PasskeyService::verifyDerivation()` stamps `pkc_prf_failed_time` after the
  assertion checks out, never before, so a forged request cannot mark someone
  else's credential incapable. A later success clears the stamp — a firmware
  or OS update can make a credential capable. The stamp is one of two evidence
  sets: registration-time signals alone can also prove a credential incapable,
  and must, since a U2F-only key cannot pass a UV-required assertion and so
  could never earn a stamp.
- **The trust boundary of that evidence is the client.** The assertion
  signature covers the authenticator data and the client data hash;
  `clientExtensionResults` — where the PRF output travels — is assembled by
  the browser and unsigned, and registration-time signals are client-reported
  too. Remote proof that a credential *cannot* derive does not exist in
  WebAuthn, so capability evidence is best-effort against accident, not
  against a client that lies about its own account. Where signed corroboration
  exists it is used: a CTAP authenticator that evaluated PRF carries the
  hmac-secret output inside the signed authenticator data, and a missing
  client result is not stamped when that shows an evaluation happened. The
  backstops for a wrong stamp are the ceremony gate, the step-up on minting,
  and the clear-on-success rule.
- `VaultCeremonies::setup()` re-asks the same question before writing a
  passkeyless vault, and requires a phrase when it does. The gate is in the
  ceremony, not in the page that hides the button, so no other caller can
  route around it.
- `logic/vault_setup_passphrase_logic.php` is the only action that reaches
  this path.

Accepted trade: no passkey opens such a vault — only the phrase or a recovery
code, both memorized or written-down secrets. It is the best available on
hardware that cannot do better, and it is temporary by design — `vault_add_passkey_*` wraps the same
key under a real passkey once the holder has a capable device, after which the
phrase can be removed.

## Enrollment

All in `logic/vault_*_logic.php`, gated on `passkeys_enabled` and a signed-in
session. Every enrollment ceremony (add passkey, enroll passphrase,
regenerate codes) refuses while the vault has live wrappings in more than one
generation — an unfinished rotation, whose only exit is re-running the
rotation — because a wrapping it created could not be tagged with a single
truthful generation. Every vault endpoint declares `requires_browser_session` (see
[API § Authentication](api.md#authentication)): the unlock window is keyed to
the browser session id, so these actions are reachable only through the
browser-session credential, never an API key — the boundary is stated in the
contract rather than left to fail incidentally.

| Action pair | Purpose |
|---|---|
| `vault_setup_options` / `vault_setup_verify` | First-time setup: generate the keypair, wrap it under the enrolling passkey and the browser-made code set (`code_set`), create the root vault the browser made (`root`) in the same transaction, open the window. `with_root` on the options asks the same touch for the root's output. Requires an account password first (see *The vault-activation flip*) and an explicit permanent-loss acknowledgment. |
| `vault_add_passkey_options` / `vault_add_passkey_verify` | Wrap the secret key under another PRF-capable passkey — "activating" that passkey for the vault. The verify step takes the new passkey's derivation and a fresh `unlocker` in the same request. `passkey_register_verify` does the same activation at enrolment when the request carries an `unlocker`, so passkeys end up vault-active by default; each passkey row carries a vault badge with activate/deactivate in its Actions menu. |
| `vault_passkey_deactivate` | Remove one passkey's vault wrapping (it still signs in; it can no longer unlock). Requires a recent step-up; refused if it would break the unlocker floor. |
| `vault_regenerate_codes` | Replace every recovery code with a browser-made set (`code_set`) and the root's twins of it (`root_wrappings`), in one transaction. Requires a recent step-up and a fresh `unlocker`. |
| `vault_passphrase_enroll` / `vault_passphrase_remove` | Change or remove the passphrase, only for an account whose passkeys cannot hold a key. Enroll takes the account half (`passphrase_kek`) and the root's wrapping of the same phrase (`root_passphrase`); remove takes both away. Requires a recent step-up; enroll also takes a fresh `unlocker`. |

**Every enrolment presents a fresh unlocker.** A wrapping is produced only
in the request that presented a real unlocker for the vault — a tap of an
enrolled passkey (`unlocker: {credential}`, minted by `vault_unlock_options`),
the passphrase's account half (`{passphrase_kek}`) or a recovery code's
(`{code_kek}`, consumed with its root twin) — never from an open window, and
never the phrase or the code itself (refused as an out-of-date page). `VaultCeremonies::openWithUnlocker()` resolves
the input, opens under it with the new wrappings in the wrap list, and arms
the resulting window for the session. In the browser
`JoineryVaultLock.collectUnlocker(purpose)` offers whichever of the three the
vault has, derives the KEK in the browser, opens the root on the way where it
can, and returns the shape to send. A passkey enrolment therefore
verifies two assertions in one request; `PasskeyService` keeps one pending
challenge per purpose per session, and the add-passkey derivation carries the
tag `add` so it stands beside the unlocker's own `vault-kek` ceremony.
| `vault_status` | Read-only: set-up/unlock state and the wrapping list (no secret material) for the keyring UI. |

**Which passkeys a vault prompt offers** is one rule, shared by every ceremony
above and by unlock and rotation:
`VaultUnlock::offerableCredentialIds($user_id, $scope)` returns the credentials
holding a wrapping for that scope's vault; if none do, this is an enrollment, so
it returns everything except the credentials
[known to be incapable](passkeys.md#capability-detection) of ever deriving a
secret. The two halves answer different questions and the first is much the
stronger: which credentials hold a wrapping is a stored fact about this vault, so
unlock and rotation — the paths where a wrong answer means someone cannot reach
their own sealed content — never consult capability at all. A partially-rotated
vault holds wrappings across generations and the offer is their union. Client-
custody scopes need no special case: the server cannot read those KEKs, but it
does store each scope's wrapping rows tagged with the credential id, so it knows
*which* credentials unlock a scope without knowing *what* they unlock. An empty
result means "no opinion" and offers every live credential — never nothing, since
an empty `allowCredentials` on the unlock path is a lockout.

Adding an unlocker is the exception: `vault_add_passkey_options` takes a
`credential_id` and scopes the ceremony to that one passkey, because the browser
otherwise decides which credential answers — pick the security key's row, tap
Touch ID at the prompt, and Touch ID would get the wrapping while the row that
was clicked still read *Not activated*. `vault_add_passkey_verify` echoes the
credential id and label it actually activated, so a caller that forgets to scope
cannot activate one silently.

## The unlock window

`includes/VaultUnlock.php` — the secret key lives in APCu, keyed
`vault:{session_id}:{user_id}:{scope}`, TTL = `vault_unlock_idle_minutes`
(default 30), re-stored on every read (activity extension). What callers
hold is a `VaultKey`, never the bytes:

```php
// $unlocker = $wrapping->unlocker($kek) — the row's wrapping, the KEK the
// credential derived, the row's AD; null mints a fresh keypair (setup, rotation).
// $wrap_under = [$row->wrapEntry($kek), ...] — the wrappings to produce.
VaultUnlock::open($user_id, $unlocker, $wrap_under = [], $scope = 'user', $caps = null, $via)
    : array{key: VaultKey, wrappings: string[]};          // opens AND arms the session's window
VaultUnlock::openKey($user_id, $unlocker, $wrap_under = [], $scope = 'user'): array;  // the key, no window
VaultUnlock::arm($user_id, VaultKey $key, $scope = 'user', $caps = null, $via): void;  // make it the window
VaultUnlock::isOpen($user_id, $scope = 'user'): bool;
VaultUnlock::secretKey($user_id, $scope = 'user'): ?VaultKey;  // null = locked
VaultUnlock::close($user_id, $scope = 'user'): void;         // current session
VaultUnlock::lock($user_id, $session_id, $scope = 'user'): void;  // a specific session
VaultUnlock::lockAll($user_id): void;                        // every scope, every session
VaultUnlock::hasAnyOpenWindow($user_id, $scope = 'user'): bool;  // ANY session, any SAPI
```

`openKey()` exists for the two callers that need a key without a window: the
rotation ceremony, whose old-generation key every resealer uses and which
must never become the window, and the recovery-code / passphrase probes
(and the recovery-readiness dry run), which try each wrapping until one
opens. A wrong unlocker throws and yields nothing.

Every content read calls `secretKey()` and treats `null` as **locked** — a
one-tap unlock prompt, never an error; code that only asks whether the window
is open calls `isOpen()`. `lock()`/`lockAll()` are the generic
wipe surface; *when* to call them (explicit lock, a credential event, a
heartbeat/IP-change policy, a permission cap) is entirely consumer-defined.

`hasAnyOpenWindow()` answers "does any session hold a window for this user"
for a consumer's passive-close sweep (e.g. reclaiming `/dev/shm` working
copies from cron). Its signal is a secret-free marker file
(`/dev/shm/vault_window_{user_id}_{scope}`, mtime = the window's current
expiry, stamped by `open()`/`secretKey()`), NOT APCu — a CLI cron process has
its own APCu segment and can never see the web workers' entries, but every
process on the host sees `/dev/shm`. A single-session `lock()` leaves the
marker (another session may still hold a window); it expires with the idle
TTL, so a sweep is at worst delayed one interval, never wrong about an open
window. `lockAll()` removes the user's markers outright.

Unlock endpoints (`logic/vault_unlock_options_logic.php` and its
`vault_unlock_passkey` / `vault_unlock_recovery` / `vault_unlock_passphrase`
siblings, plus `vault_lock`) mint the WebAuthn PRF assertion options with
`userVerification: required` (`PasskeyService::getDerivationOptions()`) —
every passkey unlock demands device user verification, not merely preferred.
The recovery code and the passphrase each open the vault on their own, from
the account half the browser derived (`code_kek`, `passphrase_kek`). The
account's sign-in second factor never takes part in opening a vault: an
authenticator code confirms sign-ins and sensitive changes, and opens nothing.

### Host hardening

`includes/VaultHealth.php` checks the four facts that keep an unwrapped
secret key off disk even during a live window:

- APCu backed by anonymous shared memory (`apc.mmap_file_mask` unset).
- The PHP worker's core dumps disabled. `rlimit_core = 0` in the pool is the
  whole answer only when `kernel.core_pattern` names a file. When it pipes to
  a handler the kernel ignores the rlimit: Ubuntu's apport reads the entire
  core into its `/var/crash` report, so the check is `unmet` while apport is
  enabled (`sudo systemctl disable --now apport`, and `enabled=0` in
  `/etc/default/apport` — on the host, since `core_pattern` is not
  namespaced and a container reads the host's). `systemd-coredump` honours
  the rlimit; any other handler reports `unknown`, naming it.
- Exception traces omit their arguments (`zend.exception_ignore_args = On`).
  The secret key is a string argument on the `VaultCrypto` open methods, and
  with it off a logged uncaught exception carries the key's leading bytes.
- Swap off, or every active swap device encrypted. The device-mapper type is
  read from `/sys/block/dm-N/dm/uuid`: `CRYPT-` is dm-crypt and verified,
  `LVM-` is a plain volume and unmet. zram is accepted (compressed RAM, no
  disk without its writeback feature). The installer's housekeeping creates
  1 GB of swap as dm-crypt with a per-boot random key.

Best-effort and advisory (a check that can't be verified reports `unknown`,
never a false pass) — surfaced informationally from `vault_setup_verify` and
via `php maintenance_scripts/dev_tools/check_vault_health.php` (exits
non-zero on any `unmet` check, mirroring `check_provisioning.php`'s
convention).

Two facts about the APCu window these checks guard:

- APCu never zeroes a freed slot. Closing a window deletes the entry, but the
  key bytes remain in the segment until the allocator reuses them, so the
  anonymous-memory, no-core, encrypted-swap trio guards residue after close as
  much as the live window. `sodium_memzero` reaches the PHP copy only.
- The APCu segment belongs to the php-fpm master and is shared by every pool
  and every site that master serves. Code on any site served by the same
  php-fpm can read every other site's open windows. Managed nodes run one
  site per container; a self-hoster serving several sites from one php-fpm
  does not have that separation.

The mailbox search index's working copy in `/dev/shm` (a 1777 tmpfs every
local account can list) is created 0600 before its first write, at both of
its creation points, and `SealedBox::openStreamFile()` makes its plaintext
temp file private before the first decrypted byte lands.

## The lock chip

The platform-wide "what is unlocked" idiom: one padlock in a fixed place on
every signed-in page for a user with any vault. It reads **open**
(success-colored) only while everything is: the server unlock window (when the
person has an account vault), the root vault (`data-root-vault="1"`) and every
browser-held vault the page reads (`JoinerySealed.want`); anything less reads
partly locked. Clicking the closed padlock runs the one unlock in place
([One vault](#one-vault)), offering what the vault has — a passkey, the
passphrase or a recovery code. Clicking the open padlock opens a popover with
one line, "Vault", and one action: **Lock now** locks everything (the server
window and every browser-held vault), **Unlock** runs the one unlock. The
server window ending anywhere locks the browser-held vaults too. A user with no
account vault sees the chip only while a browser-held vault is open. Users with no vault at all, on pages that open none, never load
any of it.

`PublicPageBase` drives it: for a signed-in user with any vault row, or on a
page that declared `needs_vault_client()`, it emits
`<meta name="joinery-vault" content="locked|open" data-idle-minutes="N"
data-client-idle-minutes="M" data-server-vault="0|1" data-root-vault="0|1" data-server-label="…">`
(`content` and `data-idle-minutes` are the server window's; the client idle time
is `vault_client_autolock_minutes`) and includes `assets/js/vault-lock.js` +
`assets/css/vault-lock.css`, plus `passkeys.js` and the client modules
(`vault-crypto.js`, `vault-keyring.js`, `joinery-sealed.js`) the one unlock
needs, so a reload reopens what was open on every page. The chip
mounts into the page's `[data-vault-lock-slot]` element — the core page classes
emit one from their header icon cluster via
`PublicPageBase::render_vault_lock_slot()` (which emits nothing for chip-less
users, so headers never carry an empty gap) — and falls back to a fixed
bottom-right chip on any theme without a slot, so the idiom holds everywhere
with zero theme work.

**The ceremony surface.** `window.JoineryVaultLock` is the one client-side
unlock/lock ceremony: `unlock()` (resolves `true` on success), `lock()`, and
`state()`. Consumer surfaces (the mail reader's unlock banners, a
sending-lock compose) delegate to it when present rather than calling the vault actions
directly, so every ceremony updates the chip and announces itself.

**The event contract.** Two document-level events keep every surface on the
page — chip, presence beacon, consumer UIs — in one state:

- `joinery:vault-unlocked` — dispatched after any successful unlock. The chip
  flips open, `vault-presence.js` starts beating, and consumer surfaces may
  refresh sealed placeholders in place.
- `joinery:vault-locked` — dispatched after any explicit lock, and by
  `vault-presence.js` when a heartbeat answers `alive:false` (the window
  ended elsewhere — another session's lock, a credential event, a cap). The
  chip flips closed, the beacon stops, and consumer surfaces re-seal their
  content to placeholders.
- `joinery:vault-scope-unlocked` / `joinery:vault-scope-locked` (`detail.scope`)
  — the same for a vault this browser holds; the chip follows them.

## The unlocker floor + revocation veto

A wrapping delete is refused when it would leave fewer than 1 **live** passkey
wrapping **and** fewer than 3 unused recovery codes — the refusal names what
to enroll first. `VaultUnlock::assertWrappingDeleteSafe($vault_id,
$exclude_credential_id = null)` is the shared counting logic behind every such
refusal: passkey revocation (excluding the credential being revoked from the
count) and passphrase removal (nothing to exclude — a passphrase never
counts toward the floor itself, so removing one only matters when the
passkey/recovery counts are already at the floor). A passkey wrapping counts only if its
credential row is still live (`pkc_delete_time IS NULL`) — belt-and-suspenders
against old data predating the cleanup below. A credential the platform knows to
be [PRF-incapable](passkeys.md#capability-detection) can never count toward the
floor, and needs no special case to be excluded: it cannot have completed a
derivation, so it holds no wrapping to count.

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

## The generic consumer hooks

A server-custody consumer never builds its own decrypt plumbing — it declares
into one of the generic hooks and the vault (or the reader that already
exists) does the rest.

**Sealed-`File` decrypt hook** — a consumer with sealed attachments registers
a decryptor for its `fil_source` tag once, at bootstrap:

```php
File::registerDecryptHook(File::SOURCE_EMAIL_ATTACHMENT, function (string $ciphertext, File $file): string {
    $key = VaultUnlock::secretKey($file->get('fil_usr_user_id'));
    if ($key === null) throw new VaultLockedException();
    // ... $crypto->openItemDek($sealed_key, $key), then the AEAD blob, return plaintext bytes
});
```

`File::serve_from_path()` calls the registered decryptor between reading the
stored bytes and writing the response; a `VaultLockedException` becomes a
generic `423 Locked` response, never a raw error or ciphertext.

**Streaming `File` decrypt hook** — the shape for sealed content too large to
hold in memory, and the one that can answer a Range request honestly. A consumer
registers an opener that returns a `FileStreamingDecryptor`:

```php
File::registerStreamingDecryptHook('drive', function (File $file, $size_key = null) {
    return $file->is_sealed() ? new DriveSealedStream($file, $size_key) : null;  // null = stream unchanged
});
```

The opener is handed the size key being served — `'original'` or an image
variant — because a consumer's integrity checks differ between the two: a file's
row records the plaintext size of its original and knows nothing about a
variant's. A caller that cannot say passes `null`, which means *unknown*, never
*original*.

The decryptor answers three questions: `prepare($path)` acquires the in-window
key (and throws `VaultLockedException` if there is none), `plainSize($path)`
reports the plaintext length, and `stream($path, $sink, $offset, $length)`
decrypts a span. `serve_from_path()` resolves the key **before** writing any
header — so a locked vault is a clean 423 — then advertises `Accept-Ranges:
bytes` and serves 206 against plaintext offsets. Whole-file content should use
this shape; the whole-bytes hook above suits small sealed attachments.

**Blob-only sealing** — a consumer whose ciphertext lives entirely outside the
database declares no `$sealed_fields` at all. It still needs the four sealing
columns, and records its key with `SystemBase::recordSealedKey($row_id, $vault,
$dek)`, which wraps a key the consumer already minted (a file's bytes have to be
sealed before the row that will point at them exists) rather than minting its
own the way `sealColumns()` must. Such a row is still sealed: `save()` protects
its key wrapping exactly as it does for a column-sealing model.

**Sealed-field model hook** — a model declares which columns hold protected
content and adds four columns. That is the whole integration: no crypto code,
no key handling, no AD string of its own.

```php
class MailboxContact extends SystemBase {
    public static $sealed_fields = ['imc_address', 'imc_display_name'];

    public static $field_specifications = [
        // ... the content columns above, declared 'text' (base64 + AEAD
        // overhead outgrows any varchar cap), plus:
        'imc_content_sealed'       => ['type'=>'bool', 'is_nullable'=>false, 'default'=>false],
        'imc_sealed_key'           => ['type'=>'text', 'is_nullable'=>true],
        'imc_sealed_owner_user_id' => ['type'=>'int8', 'is_nullable'=>true],
        'imc_key_generation'       => ['type'=>'int4', 'is_nullable'=>false, 'default'=>0],
    ];
}
```

### Sealing is per row, not per model

The flag lives on the row because sensitivity does. The same table holds sealed
and plaintext rows side by side — a Private domain's mail and a Standard
domain's mail are the same model — and only the row knows which it is. A row
with `{prefix}_content_sealed` false reads and writes as ordinary plaintext and
costs nothing.

### Many readers: the one variation on the shape

Every model above seals to a **single owner**, whose wrapping lives on the row.
A conversation has many readers, so the messenger varies exactly that one part
and nothing else (docs/social_features.md § Protection levels): the key is
wrapped once per participant in `ckg_conversation_key_grants`, `Message`
overrides `decryptSealedFieldStatic()` to resolve it through whichever present
participant's grant opens, and the row's own wrapping columns stay null.
Everything else is the shipped machinery — `sealColumns()` with a supplied key
writes the ciphertext, `save()` leaves a sealed row's content columns alone, a
closed window is `VaultLockedException`, and rotation re-wraps grants without
rewriting a single message.

A consumer with the same shape (several people reading one item) should copy
that arrangement rather than inventing a third. A consumer with one owner should
not: the generic path is less code and less to get wrong.

### Reading

`SystemBase::get()` decrypts automatically whenever the requested key is in
`$sealed_fields`, which covers ordinary field access and everything built on it
(`export_as_array()`, `export_for_api()`). `ModelQueryExecutor` (the AI
`query_model` tool's raw-row reader) calls `decryptSealedFieldStatic()` on a raw
associative row instead, since it never instantiates the model. Both paths run
the same implementation, so they cannot drift apart.

A locked vault raises `VaultLockedException` — never a return of ciphertext,
which would look like data. At the edges that becomes a `423 Locked` response
(File hook) or a `[locked - unlock your vault to view]` placeholder (the raw-row
path, so an LLM sees a legible state rather than a stack trace).

### Writing

`save()` seals. A consumer writes its content the way it writes anything else:

```php
$note = new AcmeNote(NULL);
$note->set('acn_usr_user_id', $user_id);
$note->set('acn_body', $body);
$note->save();          // sealed
```

`set()` records which sealed columns the caller supplied, and `save()` lifts
exactly those out of the ordinary column build and seals them once the row id
exists. Everything that can fail — resolving the owner, finding their vault,
recovering an existing row's key — happens before any SQL runs, and the insert
and its seal share one transaction, so a save either seals or changes nothing.

**Whether a row seals is a per-row policy decision.** The default is *seal when
this row's owner has an active vault*, which is right for a consumer whose
premise is that its content is private and needs no declaration. A consumer
whose policy is dynamic — a per-domain security level, a per-conversation
setting — overrides one method:

```php
protected static function shouldSeal(array $row): bool {
    return $row['acn_visibility'] === 'private';
}
```

**Ownership** resolves through `sealedOwnerUserIdFor()`, falling back to the
conventional `{prefix}_usr_user_id` column — which the consumer sets on the row
anyway, and is why the write path needs no vault lookup of its own.

**Create works offline; updating sealed content needs the window.** That
asymmetry is the crypto, not the API: sealing needs only the owner's public key,
so any process can seal to a member at any time — an ingest path writes into a
locked vault and there is never a reason to store protected content in the clear
because "the window might close". Reusing an existing row's key means
*unwrapping* it, which needs the secret, so a sealed-column update against a
closed window raises `VaultLockedException`, exactly as `get()` does.

That asymmetry is what lets an unattended job add protected content to something
that already exists. Mail attachment adoption is the worked example: a message
ingested over IMAP holds only references to its attachments, and an archive
import that later turns up the real bytes stores them — sealed, in cron, with
nobody signed in. It can only do that because the bytes go into a **self-sealed
`File`** carrying its own key wrapped to the owner's vault, the same shape Drive
uses, rather than borrowing the message's DEK (which would mean opening it, and
so needing a window). Per-file keys also mean the existing Drive reseal sweep
re-wraps them on rotation with no new code — the sweep selects on
`fil_content_sealed` and the generation, deliberately **not** on `fil_source`.

**An update reuses the row's existing key** rather than minting a fresh one.
Minting would rewrite the wrapping and orphan every sealed column the update did
not itself rewrite. Whether the row is sealed is the **database's** answer, not
the instance's: `sealColumns()` writes with a targeted UPDATE that never touches
an already-loaded instance, so "loaded before the row sealed" is an ordinary
state (a deferred ingest, another request), and a save that trusted its own
stale flag would mint over the live wrapping. For the same reason `save()`
treats the seal flag and wrapping columns as owned by the sealing path on a
sealed row — a stale instance's `false`/`NULL` copies are never written back.

**A first-time seal of an existing row seals the whole row**, not just the
columns the edit touched. A row created plaintext (its owner had no vault yet,
or the policy declined) and sealed later must not end up half-and-half:
plaintext in a sealed column of a sealed row is leaked at rest and an exception
on every later read, so every populated `$sealed_fields` column is lifted into
that first seal.

**Null clears; non-scalars are refused.** Setting a sealed column to `null` (or
`''`) stores the empty value bare — never an AEAD blob of nothing — so `IS
NULL` queries stay honest and reads return the same shape a plaintext model
would. An array or object value throws: a silent string cast would durably seal
the literal `"Array"`. Encode structured values to a string before `set()`.
The write path also honors `sealedFieldIsActive()`, the same per-row predicate
the read path checks: a column that is metadata on this row travels the
ordinary column build in the clear, because sealing it would hand later readers
the raw blob as data.

`$seal_on_save = false` opts a model out, for a consumer that owns its own
sealing path — one that seals blobs under the same key, or decides in code that
predates this. Those call `sealColumns()` directly:

```php
MailboxContact::sealColumns($contact_id, $owner_vault, [
    'imc_address'      => $address,
    'imc_display_name' => $name,
]);
```

It mints the row's DEK, wraps it to the owner's vault public key, seals each
value, sets the flag and writes one UPDATE — returning the raw DEK so the caller
can seal related blobs (attachments, raw messages) under the same key. Pass a
DEK as the fourth argument to re-seal under an existing one, which leaves the
key wrapping untouched and keeps anything already sealed beside it readable. The
row must exist first: the AD binds every value to the primary key. On such a
model `save()` skips the `$sealed_fields` columns entirely on a sealed row, so
an ordinary metadata edit cannot write decrypted content back into them.

### The override surface

Two hooks, for the cases the defaults cannot answer:

- `sealedOwnerUserIdFor($row)` — whose vault this row opens against. The default
  is the owner recorded at seal time, which is immune to later membership
  changes. Override for an indirect owner (chat resolves through the
  conversation) or a fallback for rows sealed before the column existed.
- `sealedFieldIsActive($field, $row)` — whether a column holds content on this
  particular row. Override where a column is content on some rows and metadata
  on others: an inbound message's recipient is the routing alias, written in the
  clear, while an outbound message's recipient is a real address list.

`sealAd($row_id, $field)` builds the AD binding a value to its row and column —
the splice defense, so a ciphertext moved elsewhere fails to open rather than
decrypting into the wrong place. The default is `{prefix}:{id}:{field}`; models
that predate it override it and keep their own literal (`mail:`, `contact:`),
because changing an AD strands every row already sealed under it.

A model that declares `$sealed_fields` without the flag and key columns, and
without overriding the hooks, throws on first read. Failing loudly beats
returning ciphertext that looks like a value.

### Derived content

A record derived from protected material is itself protected material. An AI
summary of a sealed body, a run log quoting a sealed subject, a note written
from a sealed thread — all of it seals, on the same per-row terms, to the same
owner. Where a pointer will do, store the pointer: an id resolved through the
sealed reader at display time cannot leak and cannot go stale. See
`specs/implemented/sealed_content_egress.md`.

## The hot-turn rule

Reading protected content correctly still breaks the promise the moment the
reader writes what it read somewhere else. The rule that stops that is one rule
at one place, in `includes/SealedEgressGuard.php`:

> Once a process has actually opened sealed content, any long string it writes
> to the database must land somewhere that protects it.

A process is **cold** until `VaultCrypto::openField()` hands out a plaintext
(or its streaming sibling `openFieldFile()` writes one to disk), and **hot**
from then on. Cold is virtually every request, and costs one boolean
check per statement. Hot, an INSERT or UPDATE carrying a string longer than
`SealedEgressGuard::THRESHOLD` (64 characters) must satisfy one of:

- every long value is already a sealed blob (`v1.aead.` or `v1.seal.`) — this is
  how `sealColumns()` writes through the rule it sits behind;
- every long value is a list of integers (a JSON array or comma list of ids,
  `SealedEgressGuard::isIntegerList()`) — ids are references to content, never
  content, so a queue of message ids passes at any length;
- the statement updates a single row already sealed to the owner whose scope
  this process opened.

Anything else throws `SealedContentEgressException` naming the destination table
and what was read. The exception is the fix instruction, in preference order:
store a reference instead of a copy, give the destination the Layer 0 sealing
columns and seal the value, or do not write the content. There is deliberately
no way to declare a table exempt.

**A new row from a hot process** follows the Layer 0 order all the way through:
insert it with its content empty and its long plain metadata left out, seal it,
then write that metadata onto the now-sealed row, where the third allowance
covers it. An INSERT never qualifies for that allowance — the row has to exist
before anything can be sealed into it. Metadata that is not content but runs
long, such as a Message-ID or a carrier's receipt, is where this bites: the
Sent copy of a reply (`MailboxSender::storeOutboundRow()`) and its send attempt
(`MailboxSendAttempt::record()`) are both written this way.

The rule anchors at the PDO statement layer (`includes/GuardedPdo.php`), under
models, Multi collections, hand-written SQL and plugins alike, because there is
no single write path above it. Owner attribution comes from
`VaultUnlock::secretKey()`, which every read must pass through first; a process
that opened two people's content can name neither, so only ciphertext writes
pass.

**Mail is refused outright.** `EmailSender::send()` will not send from a hot
process unless the call site passes one of the `EmailSender::EGRESS_*`
assertions — `CONTENT_FREE` (built from counts, ids, links and fixed prose),
`USER_COMPOSE` (the user is sending their own message from their own mailbox),
or `ACKNOWLEDGED_FORWARD` (a filter whose owner acknowledged the egress in
writing). Refusing the send is also what keeps protected content out of
`equ_queued_emails`: a message that is never sent is never queued for retry.
An asserted send is attempted **once** — the retry queue stores bodies in the
clear, so a hot process never queues one, whatever the message contains. A
transport failure on a hot send is logged and final.

**AI web egress defers to the owner.** The AI web tools' arguments (a URL, a
search query) leave the box verbatim, so when sealed content is in play they
stop executing inline: in chat the call queues as a pending action whose card
shows the complete outbound argument, and on an autonomous recipe run it is
refused — see the hot-turn egress passage in
`plugins/joinery_ai/docs/overview.md#proposed-actions`.

**Sealed content is opened only in a protected chat.** Protection is a
conversation-level property, so a standard chat that opened sealed content would
be hot but plaintext — unable to persist its next reply or protect what it read.
Rather than patch that, the AI simply does not open sealed content in a standard
chat: `ToolContext::sealedReadsAllowed()` is true for a protected chat and for a
recipe (its whole run is the protected unit), false for a standard chat, and the
read executor excludes an actually-sealed row when it is false — the same
exclusion a locked vault triggers. A standard turn therefore never goes hot, and
an approved fetch's result only ever rides back into a protected conversation,
where the transcript seals. A backstop fails a standard turn cleanly (pointing to
a private chat) if some other path decrypts anyway.

Egress reads a wider predicate than the write-guard, `SealedEgressGuard::
egressGated()`: the process is hot, **or** the conversation is durably
egress-restricted. The hot flag alone is a per-process signal, but a chat
conversation carries sealed-derived context across turns in its transcript, and
each turn is a fresh process. So the first time any turn in a conversation opens
sealed content — a tool reading protected mail or drive, or (on a protected
conversation) decrypting its own sealed history — the conversation is marked
`aic_egress_restricted`, and every later turn arms `restrictEgress()` from that
mark before dispatch. The mark never clears: once the transcript holds
sealed-derived context, a later cold turn could otherwise smuggle it out inside
an outbound URL, so web tools gate behind the owner's approval for the life of
the conversation. A protected conversation gates from its first turn; a standard
conversation gates only after it actually touches sealed content, and never
before. Arming restriction does not arm the write-guard, so an ordinary standard
conversation keeps writing its plaintext transcript normally.

**Units of work.** `SealedEgressGuard::isolate()` runs one independent unit with
its own hot state and restores the caller's afterwards, so a process that does
several unrelated things in a row — a drain slice working through one user's
pending AI runs — does not let the first protected run poison every later one.
The caller is asserting that nothing the unit decrypted is still in play when it
returns; an outer hot state survives, so nesting cannot launder a process cold.
It is a boundary between units, never a wrapper around a write site.

**One sanctioned non-arming open.** Mail held in transit for a protected
domain — mail sealed at the relay waiting, sealed to the owner's key, for
the owner to appear — is opened with `VaultCrypto::openHeldDeliveryBlob()`,
which does not arm the rule. Opening it is first-time delivery arriving late:
the plaintext is exactly what receive-time ingest holds, cold, for the same
message on any server, so it is not a read of stored sealed content. It is
the only such exception, and `tests/vault/sealed_read_paths_test.php` pins
the entire caller set of the low-level decrypt primitives — a new direct
caller fails the suite and has to argue its case against that criterion in
review. Everything stored sealed is read through `openField()` (strings) or
`openFieldFile()` (whole files, streamed), both of which arm.

**The accepted gap.** Any copy shorter than the threshold passes. That is a
deliberate trade: the surfaces that actually carry short protected content —
subjects in run rows, summaries on message rows — are sealed structurally by the
record-level rule above, and every new write site prefers a reference anyway.

## Key rotation

`logic/vault_rotate_options_logic.php` / `vault_rotate_verify_logic.php`: a
fresh PRF assertion from an already-enrolled passkey both proves possession
(unwrapping the current secret) and supplies a KEK the ceremony can act on
immediately. (The ceremony bodies for setup, rotation, and the
recovery/passphrase unlocks live in `includes/VaultCeremonies.php` — the
logic files are shells owning gates and WebAuthn; the cores are driven by
tests with synthetic KEKs.) The authorizing wrapping is the presented
credential's **lowest-generation** live wrapping — after a partial failure
both generations' wrappings are live, and a retry must unwrap the oldest
secret, the one still holding un-resealed content. From there, in
crash-safety order:

1. Generate a new keypair and salt; compute `new_key_generation` (`uev_key_generation + 1`);
   note `old_key_generation` (the authorizing wrapping's generation).
2. **Persist the new generation first**, while the old wrappings are still
   live: the authorizing passkey's wrapping, 10 fresh recovery-code
   wrappings, and a resupplied passphrase's wrapping — each tagged
   `uew_key_generation = new_key_generation` — then flip the `uev` row
   (public key, salt, generation, updated time).
3. **Only then** walk every registered consumer's re-seal callback
   (`VaultUnlock::onReseal($callback)`, registration order; signature
   `function(int $user_id, VaultKey $old_key, int $old_key_generation,
   string $new_public_key, int $new_key_generation): void`) — the old
   generation's key is open to open with (`$crypto->openItemDek($sealed,
   $old_key)`), the new public key to seal to. A callback
   re-seals **exactly** the items whose per-item generation equals
   `$old_key_generation` (the only generation `$old_key` can open),
   attempts every item, and **throws** if any failed. Any callback throw
   aborts the ceremony here with an error: nothing is retired, every
   unlocker still works, and re-running the rotation converges.
4. **Only after every callback confirms the drain**, soft-delete the drained
   generation's wrappings (`uew_key_generation = old_key_generation`) —
   never the whole pre-rotation list, so wrappings of any other live
   generation survive until a later rotation drains them.

A crash or callback failure at any point up through step 3 leaves both
generations' wrappings live and both secrets recoverable — old wrappings
still unwrap the old secret, and each wrapping's own `uew_key_generation`
says which secret it belongs to (recovery/passphrase wrappings also carry
their own `uew_salt`, so they stay derivable after the vault row's salt has
moved on).

**Re-running the rotation completes it rather than repeating it.** When the
authorizing wrapping's generation is BELOW the vault row's — the signature of
an interrupted rotation — the ceremony runs in completion mode: no new
keypair, no new wrappings, no salt change. It drains the old generation to
the vault's existing current key and retires it, converging to a single live
generation. (Minting a fresh generation on every retry would instead leave
the vault permanently split across two generations — each pass retiring one
and creating another — with every unlock able to read only half the
content.) The completion response carries `completed_pending = true`, no
recovery codes (the current generation's were minted by the interrupted
attempt and never shown), and `regenerate_recommended = true`. Enrollment
ceremonies refuse while two generations are live, so completion is the one
road out of the interrupted state.

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

## Registering a consumer

A consumer is a package that seals content under the vault. Its load point is
its ordinary plugin bootstrap — the top-level `bootstrap` key every plugin may
declare (docs/plugin_developer_guide.md § Bootstrap), loaded once per request
by `PluginBootstraps` in declared order. `vaultConsumer` declares the vault
obligations riding on that bootstrap:

```json
"bootstrap": "includes/bootstrap.php",
"vaultConsumer": {
  "order": 20,
  "reseals": true,
  "caches": true
}
```

A core consumer declares in `vault_consumers.json` at the `public_html/` root;
having no plugin.json, each entry there carries its own `bootstrap` path
relative to `public_html` (the string shorthand `"name":
"includes/File.php"` declares a bootstrap and no obligations).

The bootstrap is where the consumer's hooks register: `File` decrypt hooks,
`onReseal`, `onWipe`, `onWindowCaps`, `VaultDeferredWork::register`.

- **`order`** — lower loads first, ties broken by consumer name; default `100`.
  Load order is load-bearing rather than cosmetic: mail parsing must precede AI
  judging, because an unparsed message has no fields to read. The resulting
  order is also the order `VaultDeferredWork` drains in. A plugin with a
  bootstrap and no `vaultConsumer` block loads at the default order.
- **`reseals`** — this consumer stores sealed content and must register an
  `onReseal` callback. Declared and missing **refuses key rotation**.
- **`caches`** — this consumer keeps disposable in-window plaintext outside the
  sealed columns and must register an `onWipe` callback. Declared and missing
  **logs**.
- **`client_reseals`** — a list of client-custody scopes this consumer keeps
  keys under (`["passwords"]`). It must register `VaultUnlock::clientReseal()`
  for each; declared and missing **refuses that scope's rotation**, the way
  `reseals` does for the server scope, including for a deactivated plugin that
  was ever used. See [Rotating a client-custody key](#rotating-a-client-custody-key).

The two obligations read symmetrically and deliberately do not behave
symmetrically. Rotation is an operation the platform may refuse; locking is not.
The only moment a missing wipe callback becomes observable is window close, and
refusing to close a window would leave the vault **open** — a live unlocked
vault traded for a stale plaintext file, which is worse than the thing being
guarded. So `caches` is worth declaring mainly for what it makes visible: a
reviewer reading `plugin.json` can see which consumers hold member plaintext
outside the sealed columns without reading their code. It is not checkable the
way `reseals` is — `$sealed_fields` is a filesystem fact, while nothing in the
tree betrays a consumer writing plaintext to `/dev/shm` — so it catches the
honest-but-forgetful only.

A deactivated plugin's consumer is simply absent: its hooks do not load, its
window caps lapse, and its sealed rows are untouched. Rotation still refuses
while it declares `reseals`, because deactivation removes the callbacks but not
the content. A plugin that was **never activated** on the instance does not
refuse: with no activation there are no sealed rows (often no tables), and
holding every member's rotation hostage to a feature nobody switched on would
be the guard misfiring. Activation history (`plg_plugins`) draws the line, so
deactivated-after-use still refuses.

The two core registry files are part of the tree, and a deploy that loses or
corrupts one fails **loudly**: `VaultScopes`/`VaultConsumers` throw rather than
serving an empty registry, because an empty registry is the quiet version of
the worst outcome — no scope resolves a PRF context, no consumer's hooks load,
and the rotation guard has nothing to refuse on. The `user` scope additionally
has a structural floor: the ceremonies hardcode it, so no edit to
`vault_scopes.json` can remove it.

Registrations are attributed to whichever consumer's bootstrap is loading, which
is what lets a missing obligation be reported by name. That holds only while
bootstraps load through `VaultUnlock::loadConsumerBootstraps()` and nowhere
else; the loader checks and logs loudly if a bootstrap was included some other
way first. So code that needs a class a bootstrap defines (Drive logic needing
`DriveSealed`) calls `VaultUnlock::loadConsumerBootstraps()` rather than
`require_once`-ing the bootstrap file — same classes loaded, attribution
intact.

## The consumer contract (server-custody)

1. Declare a top-level `bootstrap` and a `vaultConsumer` block in your
   `plugin.json`, with `reseals: true` if you store sealed content.
2. Declare `$sealed_fields` plus the four convention columns on your models, and
   write with ordinary `set()`/`save()`. Decide deliberately whose key each item
   seals to, including items that have no obvious owner — mail resolves the
   mailbox's single owner, falling back to the domain's owner for mail that
   belongs to no mailbox, because an item with no resolvable owner is stored in
   the clear.
3. Read via `SystemBase::get()`, or `VaultUnlock::secretKey($user_id)` where you
   need the `VaultKey` itself (for `VaultCrypto::openItemDek()` or its
   `publicKey()`); treat a locked vault as a one-tap prompt, never an error.
4. Reuse the File decrypt hook for sealed attachments
   (`File::registerDecryptHook`) and the sealed-field model hook for generic
   reads (`$sealed_fields` + `decryptSealedField()`/`decryptSealedFieldStatic()`).
5. Register a re-seal callback for rotation. For rows that live in models, that
   is one line:

   ```php
   VaultUnlock::onReseal(VaultUnlock::modelReseal([
       MailboxContact::class,
       MailboxMessage::class,
   ]));
   ```

   `SystemBase::resealRows()` does one model's pass: it re-seals exactly the
   rows on `$old_key_generation`, honors `sealedOwnerUserIdFor()`, attempts every
   row and reports failures so the caller throws. A consumer that also seals
   material outside model columns — mailbox's DKIM keys, Drive's blob keys —
   writes its own callback and may register this one alongside. Whichever you
   write, the contract is the same: re-seal exactly the items on
   `$old_key_generation`, attempt every item, and throw if any failed, because a
   swallowed failure lets the ceremony retire the only path to that content. The
   callback must cover **every** sealed asset the user can own, unconditionally —
   the mailbox callback re-seals protected-domain DKIM keys (live and
   rotation-pending) for a domain owner even when that user holds no mailbox
   grants at all.
6. Register a wipe callback if you keep any disposable in-window cache
   (`VaultUnlock::onWipe()`), e.g. a plaintext search index, and declare
   `caches: true`.
7. Own your own levels, scope, and locked-state surfaces (list placeholders,
   a content-action unlock prompt, a native `locked` flag) — the vault
   provides everything below the content. Run web unlock/lock ceremonies
   through `JoineryVaultLock` and listen for the two lock-chip events (see
   *The lock chip*) so your surface and the chip stay in one state.

**One unlock opens every server-custody consumer** — the accepted tradeoff, and
the reason a consumer needing genuine isolation declares a client-custody scope
instead (see *Client-custody scopes*).

## How long a window lasts

Every server-custody consumer shares one window, so its length is a fold of
every consumer's opinion. A consumer registers a provider from its bootstrap:

```php
VaultUnlock::onWindowCaps(
    function (int $user_id): array {
        return ['idle' => 7200, 'absolute' => 86400];
    },
    ['idle' => 7200, 'absolute' => 86400]   // contributed instead if the provider throws
);
```

`capsForUser()` folds every provider by taking the **strictest** value per
field — the minimum non-null `idle`, the minimum non-null `absolute`. One
window cannot honor two lengths, and a member who configured a tight window on
any consumer expressed a preference about their unlock window as a whole. A null
field is an abstention, not a cap of zero, and with no providers registered the
window is uncapped.

The second argument is the fail-closed pair: an error resolving a policy must
never hand an uncapped window to someone who may have configured the strictest
one, so a provider declares what its own failure should imply. Omitting it
means the hardened caps (`VaultUnlock::HARDENED_*_CAP_SECONDS`) — abstaining on error must be said explicitly, with
`['idle' => null, 'absolute' => null]`, never defaulted into.

Fail-closed covers the load path too: a declared consumer bootstrap that is
missing on disk (a partial deploy) never got to register its provider, so
`capsForUser()` folds the hardened caps in whenever any declared bootstrap
failed to load. A user already under them sees no difference; everyone else gets a
tighter-than-usual window until the deploy is fixed.

## The audit log

The window lives in APCu and a `/dev/shm` marker. Both vanish without trace, so
nothing about a past window is recoverable from the running system: whether it
was open at a given moment, how it was armed, or why it ended. `VaultAudit`
writes that down, into the platform's general event log (`evl_event_logs`)
alongside the rest of the audit trail.

Two events, one row per state **transition**:

| Event | Written when |
|---|---|
| `vault_window_opened` | `VaultUnlock::open()` arms a window |
| `vault_window_closed` | the window ends, for any reason |

A beacon beats every 25 seconds for as long as a tab is open. Those are not
transitions and are not logged — burying the two facts that matter under
thousands that do not is how an audit log stops being read.

Each opened row records `via`: `passkey`, `passphrase`, `recovery`, `setup`,
`rotate`, `reenroll`, or `unknown`. It also records the caps this window
resolved to and the configured `vault_unlock_idle_minutes`, because between
them those decide how late a legitimate read can arrive.

Each closed row records `reason` and `open_seconds`:

| Reason | Meaning |
|---|---|
| `idle_cap` | a cap fired: no content decrypt within the consumer's idle limit |
| `absolute_cap` | a cap fired: armed too long ago, however much it was used |
| `heartbeat_stale` | the browser stopped answering |
| `idle_expired` | the key aged out of APCu after `vault_unlock_idle_minutes` |
| `explicit_lock` | someone pressed Lock now |
| `logout` / `ip_change` | the session ended, or moved network |
| `credential_event` | password change or reset, recovery-code use, 2FA change |

The three end-events run code and write their own row. An APCu expiry does not
— it happens inside the cache with nothing to hook — so the row is written by
whoever next **notices**, which is why a session remembers in `$_SESSION` that
it armed a window at all. Normally the beacon notices within one beat, because
it is the only thing still asking once the user has gone.

Where a window is wiped by a session other than the one that owns it —
`lockAll()` on a credential event — the locking session writes the row and
leaves a short-lived tombstone, so the owning session reports nothing rather
than mistaking the vanished key for an expiry. `lockAll()` writes one row per
`(session, scope)`: a credential event that closes three devices reads as three
windows ending, which is what it was.

**What is never written:** the secret key, any wrapping, any sealed content, and
the session id. A session id is a bearer credential; the log carries
`VaultAudit::handle()` instead — a truncated one-way digest, enough to tie an
open to its close and useless to anyone reading the log.

Losing a row must never break the request that noticed, so the write is wrapped
in `SystemBase::server_initiated_write()` (a close is an observation the server
makes on whatever request happened to see it, often a GET) and any failure is
swallowed to `error_log`.

`evl_event_logs` has no retention policy, so these rows persist until something
prunes them. At one row per open and one per close, that is a handful per user
per day.

## Deferred work in the window

Some work over sealed content cannot happen when the user asks for it — mail
arrives while they are logged out, and AI features want to run continuously.
That work cannot run from cron either: the secret key lives in APCu keyed to
the **browser session**, so a CLI process has a different APCu segment and
`VaultUnlock::secretKey()` returns null there by construction. It has to run
inside a web request carrying a live window.

`includes/VaultDeferredWork.php` schedules it. A consumer registers from its
`includes/bootstrap.php` — already loaded by `loadConsumerBootstraps()`:

```php
VaultDeferredWork::register(
    'mailbox_parse',
    fn(int $user_id) => bool,                                   // cheap, indexed, no decrypt
    fn(int $user_id, VaultKey $key, float $deadline) => int      // work until the deadline
);
```

**What starts it.** `assets/js/vault-presence.js` beats `vault_heartbeat`
every 25s from every signed-in page with an open vault. The beat also reports
`work_pending`, and the client fires the separate `vault_deferred_work` action
when it is true, chaining while work remains. Drains observe a 10-second quiet
period after the beacon starts (an unlock, or a page load with the window
already open): `work_pending` schedules the drain for the end of the period
rather than firing it, so the page's own requests — the mail-list refresh an
unlock triggers, a fresh page's content fetches — get the workers and the
database first. The backlog is background work and loses nothing by starting
a few seconds late. Chained drains are paced — 15 seconds between one slice
ending and the next starting — because every drain counts against the API's
per-address request budget alongside the reader's own requests; the pacing
keeps a long backlog from spending that budget and locking the person out of
their own mail. A drain the server refuses (a 429, or any failure) backs off
for a minute before the next attempt.

The work never runs inside the beat. A batch can involve a language model whose
timeout is measured in minutes; a beat blocked that long would stack up behind
itself while the window it exists to protect lapsed.

**Order and budget.** Consumers run in registration order — the `order` each
declares in its `vaultConsumer` block, and it is meaningful: mail parsing
precedes AI judging, because an unparsed message has no fields to read. Each batch is bounded by
`vault_deferred_work_slice_seconds` (default 10), shared round-robin so one slow
consumer cannot starve another. The deadline is checked **between** items, never
inside one — an in-flight model call cannot be cut off cleanly, so a batch may
overrun by a single item. Each consumer's turn holds a Postgres advisory lock on
`(user, consumer)`, so two open tabs never double-process; a held lock is
skipped, not waited on. A consumer that throws is logged and skipped for that
batch, and retried on the next.

**Background work is not user activity.** `secretKey()` normally stamps the
content-decrypt time the hardened idle cap measures from. If a drain's reads
counted, a tab left open at an empty desk would hold the window open forever and
the idle cap would stop existing. Every batch therefore runs inside
`VaultDeferredWork::withBackgroundWork()`, which sets
`VaultUnlock::setActivitySuppressed(true)` for the duration: the key is still
returned and every policy check still applies, but the TTL is not re-stored, the
`/dev/shm` marker is not touched, and the content stamp is not refreshed. It is a
request-scoped flag rather than a separate accessor because consumer code below
the drain reaches `secretKey()` on its own.

A test asserts the property directly: a window whose only reads come from
background work still expires on schedule.

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

## Tests

The vault test estate lives in `tests/vault/` (crypto refusals, the unlock
window, the audit trail, ceremony state machines, rotation crash-injection) plus
`plugins/mailbox/tests/mailbox_reseal_test.php` (the consumer contract
against real rows); shared fixtures in `tests/lib/vault_fixtures.php`. The
window suite exercises APCu and skips under plain CLI — run it directly with
`php -d apc.enable_cli=1 tests/vault/vault_unlock_window_test.php`.

## Settings

- `vault_unlock_idle_minutes` (default `30`) — the server unlock window's idle
  timeout.
- `vault_client_autolock_minutes` (default `15`) — how long a vault the browser
  holds (the password vault, Fortress folders) stays unlocked without activity.
  A person can choose a shorter or longer time for their own browser (the
  password manager's select; stored in `localStorage` as
  `jy_vault_client_autolock`).

No RP-ID, origin, or PRF-context setting here — see [Passkeys](passkeys.md)
for those (the vault uses the `vault-kek` PRF context).

## Client-custody scopes

A client-custody scope (`uev_custody = 'client'`) is unwrapped **only in the
browser** — the server never holds the secret key and never sees plaintext.
Everything a consumer needs lives in **core**, so a consumer writes no crypto,
no ceremony and no session code:

- **`assets/js/vault-crypto.js`** — the browser crypto module: WebCrypto
  AES-GCM/X25519, the vendored hash-pinned Argon2id WASM for the passphrase KDF,
  KEK derivation (passkey PRF / recovery / passphrase), wrap/unwrap of the vault
  secret key, ECIES seal/open of a data key, and `encrypt(str, key, ad?)` /
  `decrypt(blob, key, ad?)`. `selfCheck()` proves this engine and the server
  agree on the bytes (the shared vector in `tests/vault/fixtures/edge_vector.json`).
- **`assets/js/vault-keyring.js`** — `VaultKeyring.ensureUnlocked(scope, opts)`,
  the one ceremony: setup (then the recovery codes), unlock, or nothing.
- **`assets/js/joinery-sealed.js`** — `JoinerySealed`: opening and sealing rows,
  the per-scope session and its lock, and key rotation.
- **`includes/VaultClientCustody.php`** + the core `logic/vault_client_*`
  actions — opaque-blob storage: the keypair record, the keyring view,
  add/remove/replace unlocker wrappings, consume a one-time recovery key (which
  emails the account — the server can't verify code knowledge, so visibility is
  the defense against a session-rider burning codes).

Each client scope has its **own** keypair and its **own** PRF context, so
unlocking one never opens another. The context is DERIVED from the scope name
(`vault-{scope}-kek`, with `user` grandfathered to `vault-kek`), never declared:
a declared context lets a copy-pasted declaration silently merge two scopes'
unlocks, and deriving it makes that mistake unrepresentable. Which scopes exist
is instance configuration — core scopes in `vault_scopes.json`, a plugin's own
under `vaultScopes` in its `plugin.json`:

```json
"vaultScopes": {
  "passwords": { "custody": "client", "label": "Password vault" }
}
```

A scope a plugin declares is always client custody: `user` is the only
server-custody scope, and `VaultScopes` refuses a plugin declaring
`custody: server`. A name collision is refused rather than merged — core wins
over a plugin, and two plugins claiming one name are both refused — because a
shared name means a shared PRF context, which is the isolation failure
derivation exists to prevent. A vault row whose scope nothing declares is inert:
no card, no unlock, and the rows are never deleted, so reactivating the plugin
restores access.

The built consumers are the [password manager](../plugins/vault/docs/overview.md)
(scope `passwords`) and [Drive encryption](drive_encryption.md) (scope `drive`,
adding per-file content encryption and multi-user key sharing on top).

### One row shape for both custodies

A `$sealed_fields` model seals to a client-custody scope with the same four
columns server custody uses. **Custody is per row**, chosen by one write-side
hook, because one table can hold a Private mailbox's rows beside a Fortress
mailbox's:

```php
protected static function sealScopeForWrite(array $row): string {
    return $row['acn_end_to_end'] ? 'acme_notes' : 'user';
}
```

The default is `user`, so a model that never overrides it is server custody. A
scope nothing registers throws on first use, naming it.

The sealed key is **self-describing**, which is how a reader knows the custody
with no extra column:

| Blob | Server custody | Client custody |
|---|---|---|
| sealed DEK | `v1.seal.` + libsodium sealed box | `v1.edgeseal.{scope}.` + base64(ephPub ‖ IV ‖ ct), `vault-crypto.js` ECIES to the scope's public key |
| field | `v1.aead.` + XChaCha20-Poly1305 | `v1.edge.` + base64(IV ‖ ct ‖ tag), AES-256-GCM under the DEK, AD = the model's `sealAd($row_id, $field)` |

The prefixes belong to the row layer (`SystemBase`, `joinery-sealed.js`); the
primitives (`SealedBox::sealEdge`/`openEdge`/`aeadEncryptGcm`/`aeadDecryptGcm`,
`vault-crypto.js`) emit raw bytes. `VaultCrypto::openItemDek()`/`openField()`
open whichever prefix they find (`VaultKey::unsealEdge()` for a DEK sealed in
the browser format to a key the server holds); writers emit the format their
custody dictates.

**Reading on the server.** `get()` — and `decryptSealedFieldStatic()`, which the
AI surface uses — throws `VaultSealedForBrowserException` for a sealed field of
a client-custody row. Not "wait for the window": no server code reads that row,
open window or not. The one place it is caught is the API export.

**The API representation.** `export_for_api()` hands such a row to the browser
as stored: every plain column normally, each sealed field as its ciphertext,
plus three derived keys — `sealed_scope`, `sealed_dek` (the key column's value;
the column itself ends in `_key`, so the credential floor keeps it out under its
own name) and `sealed_ad_prefix` (`sealedAdPrefix()`: the part of `sealAd()`
before the row id, so a legacy literal works too). The AD of each field is
`{sealed_ad_prefix}{key}:{field}`.

**Writing from the server** (ingest, a webhook) is unchanged for the caller:
`save()` and `sealColumns()` seal plaintext, in the browser format when the row's
scope is client custody, to the owner's vault of that scope (no such vault: the
row stays plaintext, as for a member with no vault). A server writer never holds
the client secret, so it cannot reuse a row's DEK: an update of a sealed field
on a client-sealed row must supply **every** sealed field, and mints a new DEK;
a partial update throws `VaultSealedForBrowserException`. The same holds for a
row changing scope.

**Writing from the browser** is two steps, because the AD needs the row id:
create the row with its sealed columns empty, then post the ciphertext. The
ciphertext enters only through `SystemBase::acceptBrowserSealed($row_id,
$sealed_dek, $fields)`, which checks the `v1.edgeseal.{scope}.` prefix, that the
scope is the row's `sealScopeForWrite()` and client custody, that `shouldSeal()`
does not keep the row plaintext, that every field is declared and carries
`v1.edge.`, and that no populated sealed field is left behind, then writes the
four columns and the fields in one statement. **Authorization is the caller's**:
the consumer's save logic proves the caller owns the row first. `save()` refuses
a `v1.edge.` value, so ciphertext cannot be stored as though it were plaintext.

### The browser side

```js
const note = await JoinerySealed.open(rowFromApi, refetch);   // plaintext fields, or an unlock prompt
await JoinerySealed.save('acme/note_save', values, { scope: 'acme_notes', sealedFields: ['acn_title', 'acn_body'] });
```

- `open(row, refetch, opts)` — a plain row resolves as is; a server-custody row
  with `content_locked` runs `JoineryVaultLock.unlock()` then resolves
  `refetch()`; a row with `sealed_scope` opens `sealed_dek` with the scope's
  session and decrypts every `v1.edge.` field. Opened values are cached per
  scope session and dropped on lock.
- `seal(scope, id, adPrefix, values)` — mints a DEK, seals it to the scope's
  public key (no unlock needed; a scope not set up runs setup) and each field
  under its AD. Empty values stay bare.
- `save(action, values, opts)` — the two-step write as one call: posts `values`
  minus `opts.sealedFields` to `action`, reads `id` and `sealed_ad_prefix` from
  the reply, seals, and posts `{id, sealed_dek, fields}` to the same action.

A page that uses any of this calls `$page->needs_vault_client()` before its
header; the head then carries `passkeys.js`, `vault-crypto.js`,
`vault-keyring.js` and `joinery-sealed.js` (`joinery-api.js` is on every page).
`AdminPage` has the same method. A page that rotates keys calls
`needs_vault_rotation()` instead, which adds every consumer's re-seal hook.

### The ceremony

`JoinerySealed.session(scope)` opens a content vault through the root
([One vault](#one-vault)): with the root shut it runs the one unlock
(`JoineryVaultLock.unlock()`), then opens the vault with its `root` wrapping,
or creates it silently when it does not exist yet. There is no separate setup
and no separate prompt per vault. A person with no vault is sent to their
Security page, where the account vault, the root and the codes are made
together.

`VaultKeyring.ensureUnlocked(scope, opts)` remains for a content vault that
holds unlockers of its own and no `root` wrapping: it reads
`vault_client_status` and runs the unlock inside one `JoineryModal`, offering
only what that keyring has; the session is then given its `root` wrapping, so
it is the last time. `opts.reason` reads in the prompt ("You need it to open
this file").

### Sessions and the lock

`JoinerySealed` holds one session per scope per tab, the root's among them
(`session(scope)` runs the ceremony on a miss; `adopt(scope, session)` holds
one the lock chip's unlock opened) and never hands out key bytes: a session opens and seals,
it cannot reveal. Every open scope locks together after
`vault_client_autolock_minutes` without keyboard or pointer activity (a
person's own choice for this browser overrides it).

**Reloads reopen.** A scope stays open across a reload, or a move to another
page, in the same tab. When it opens, its secret is wrapped (`AD
vault:{scope}:resume`) under `HKDF-SHA256(server half ‖ tab half, info
'joinery-vault-resume:v1:{scope}')`, two random 32-byte halves. The tab keeps
the wrapped secret and its half in `sessionStorage`; the server keeps the
other half in the sign-in's PHP session (`vault_client_resume`,
`includes/VaultClientResume.php`, keyed by scope and a random per-tab id).
Neither half opens anything alone, and shares are never logged. At load
`JoinerySealed.ready` settles once every scope the tab had open has reopened
(`joinery:vault-scope-unlocked` with `detail.resumed`) or been given up — a
stored public key a rotation retired, a tab idle past its limit, a session
that ended. `session()` waits for `ready`, so a page never runs a ceremony for
a scope that is about to reopen; a consumer deciding a scope is shut waits for
it too.

`pagehide` drops only the in-memory key, and a back/forward-cache restore
(`pageshow` with `persisted`) drops it and reopens through the halves — a
restored page never shows plaintext with a key it did not re-derive. Every
other lock is real and forgets both halves. Closing the tab loses the tab's
half; signing out loses the server's. A new tab asks once.

`JoinerySealed.lock(scope)` / `lockAll()` are the explicit entries;
`onLock(scope, fn)` registers a callback and `document` receives
`joinery:vault-scope-locked`. Core drops the session and its cache of opened
values. **The consumer owns wiping its own DOM and caches**: decrypted names
written into elements, raw key bytes it kept, inputs and detail panes, object
URLs.

### Rotating a client-custody key

Only the browser holds the secret, so only the browser can rotate it, and it
costs: **new recovery codes** (the browser never held the old ones), the
**passphrase again** if there is one, **one passkey tap per enrolled passkey**,
and every **linked device must re-link** (it holds the old secret). The
security page's card for each browser-held vault offers it
(`JoinerySealed.resealScope(scope)`):

1. `vault_client_rotate_begin` stores the new public key and its wrappings as
   generation N+1, **pending**. The key in use and all its unlockers keep
   working; changing its unlockers is refused meanwhile. It needs a recent
   step-up; the browser asks first with `dry_run`, before collecting any
   passkey tap or the passphrase.
2. The batch moves every sealed DEK onto the new key: rows of the models a
   consumer registered through `vault_client_reseal_rows` (only rows still on
   generation N) and `vault_row_reseal` (key column and generation only, the
   caller's own rows, the blob's scope must be the row's), then each consumer's
   `JoinerySealed.onReseal(scope, fn)` hook for keys kept elsewhere.
3. `vault_client_rotate_commit` — refused while any registered row is left on
   generation N — retires generation N's wrappings, makes the pending key the
   key, and takes the scope off every linked device (`sde_vault_scopes`). Any
   key a hook skipped is named in the result.

What gets re-sealed is a registry. A consumer registers in its bootstrap:

```php
VaultUnlock::clientReseal('acme_notes', array(AcmeNote::class));                  // models
VaultUnlock::clientReseal('passwords', array(), array('plugins/vault/assets/js/vault-reseal.js'));  // a hook
```

and declares `"client_reseals": ["acme_notes"]` in its `vaultConsumer` block. A
hook script registers `JoinerySealed.onReseal(scope, async ctx => …)`, receiving
`{oldSession, newSession, newPublicKey, progress, skip}`; it must be safe to run
twice (a key the new session opens was moved already), and a key neither
session opens — unreadable before the rotation too — is left and reported with
`ctx.skip()` rather than holding the rotation back. Drive re-seals its `FileKeyGrant` rows through `drive_key_grants_reseal`
(the member's own grants only, only while a rotation is pending); the password
manager its store key through `vault/keyring_replace` (accepted only while a
rotation is pending; `keyring_save` stays create-only).

**While a rotation is pending, new material seals to the pending key**
(`UserEncryptionVault::sealingPublicKey()`): the server sealer, the keys
`drive_public_keys` hands out, and `JoinerySealed.seal()` all use it, and a
browser write names the key it sealed to (`acceptBrowserSealed(..., $public_key)`)
so the row is stamped with that key's generation. Nothing sealed during the
rotation is left on the key the commit retires.

A rotation that stops part way is **finished, never discarded**: what it moved
opens only with the new key, and keys a consumer's hook moved (Drive's grants,
the password store key) carry no generation the server could count, so there is
no telling "nothing moved" from "everything moved". The card then says that
what moved will not open until the rotation is finished and offers "Finish
rotating", which unlocks both keys (the new one with the unlockers it was given)
and runs the batch again — everything in it resumes. A new begin is refused
while one is pending. The status payload lists the key in use's wrappings in
`wrappings` and the pending ones apart in `pending_wrappings`.

### Handing a vault to a device

The device-link page (`/profile/devices/link`) offers a checkbox for each
browser-held vault the user has set up: Drive's (`enable_vault`, its key in
`sealed_vault_key`, the field the shipped sync client reads) and one per other
scope (its key in `sealed_vault_keys`, `{scope: blob}`). Each chosen vault is
unlocked and its secret sealed to the device's public key in the browser
(`session.sealSecretKeyTo()`), one unlock per vault. The device collects both
fields once on its claim, and `sde_vault_scopes` records what it holds. A
native client learns a scope's public key and key generation — and so notices a
rotation — from `vault_client_probe` (any registered client scope, session-key
reachable, no unlock material).
