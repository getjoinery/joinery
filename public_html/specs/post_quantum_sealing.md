# Post-quantum sealing: every key the platform wraps is wrapped with X-Wing

**Status:** spec, draft 4, 2026-10-31 (reviewer1 final review applied; Q1-Q8 decided). Building.
WP0 built and committed 2026-10-31 (`f8ff4393a`): `MlKem768`, the NIST ML-KEM vectors, `pqReady()`
and its surfaces, the 26.04 install gate, `ffi.enable = On`; reviewed by reviewer2 (closed); box
checks owed in the live verification queue.
WP1 built 2026-10-31, reviewed by reviewer2 (B1-B5 fixed, re-check closed): `includes/XWing.php` (all three draft-11 Appendix C vectors
pass), SealedBox 2.0 (v2 seal, v1 open-only), PoolVaultKey/VaultKey/VaultCrypto versions and
frames, BackupEnvelope v2 with the seed site key, BackupRecoveryKey, decoys, the size ceiling,
the approval/copy-key/break-glass shape checks, the 1216-byte caps and columns, SiteCopyLiveRun,
`backup_envelope.php` and `escrow_keypair.php`, `_config_secrets.sh` 1.1, the vector file and
`tests/vault/xwing_vector_test.php`. Two changes from the text below: the version travels in the
wrapped secret, not from the vault row (R2, "Where a vault key's version comes from"); and WP7's
converger replacement of an old site key was pulled into WP1 (`.retired`), because every backup
suite that uses the machine's own key fails without it. Until WP3 the relay suites that push a
v2 transport key to the Go applier fail (it refuses 1216 bytes); until WP2 the browser cannot
make a v2 key, so no client-custody vault can be created or rotated on a WP1-only tree. WP1 must
not be published alone. Ten backup suites wait on localdev's own recovery key becoming X-Wing (live
verification queue). Next: WP2.
**Decided by the owner, 2026-10-31:** Joinery requires Ubuntu 26.04 or later.
There is no 24.04 path for this feature and no pure-PHP fallback.
**Decided by the owner, 2026-10-31:** no transition period. The upgrade forces
every key holder onto v2 at once, and anything that needs a v1 key refuses
until its holder has rotated. Old-format code survives only where the rotation
itself has to read a v1 blob.

## The problem in plain terms

Everything the platform encrypts is safe against a quantum computer except the
one step that hands out the key. Content is AES-256 or XChaCha20; keys are
derived with Argon2id, HKDF and PBKDF2; a quantum computer only halves those,
which leaves them fine. But every time one key is sealed to another party's
public key, that seal is X25519, and X25519 falls to Shor's algorithm. A copy
of a sealed blob taken today can be opened the day such a machine exists, and
the blobs that matter most are exactly the long-lived ones: a backup envelope,
a vault's wrapped secret, a break-glass copy of a disk key.

This spec swaps that one step for a hybrid key encapsulation, X-Wing, which
combines X25519 with ML-KEM-768 so a seal falls only if both are broken.
Nothing else about the platform's key hierarchy changes. The migration is a key
rotation, which the platform already knows how to do. The post-quantum half of
the work is recorded as open item O7 of
`specs/hosted_data_promise_disk_encryption.md` (line 2184); this spec is the
spec that item defers to.

## What was found, verified 2026-10-31

### F1. Every place a key is sealed to a public key today

Two seal formats exist, both X25519, and six runtimes write or open them.

**The libsodium form** (`crypto_box_seal`, server side only):

- `SealedBox::sealDek()` emits `v1.seal.` + base64url of the raw sealed box
  (`includes/SealedBox.php:87-94`); `sealBinary()` emits the raw bytes with no
  frame for bulk Joinery Direct parts (`:134-140`). `openDek()` (`:102`)
  strips the frame with `unframeSeal()` (`:111`, accepts only `v1`) and hands
  off to `openBinary()`, which derives the public half from the secret
  (`:147-160`). `PoolVaultKey` derives its public half the same way, from the
  secret alone (`includes/PoolVaultKey.php:38-44`), and its `unseal()` /
  `unsealEdge()` call `openBinary()` / `openEdge()` (`:95-111`).
- Backup envelopes: every recipient entry is `base64(crypto_box_seal(data_key,
  pub))` (`includes/BackupEnvelope.php:108`), envelope `version` 1 (`:38`,
  refused otherwise at `:347`). The site keypair is a raw 64-byte sodium
  keypair in `config/backup_site_key` (`:157-187`, length check `:217`). The
  host converger mints that file as root before PHP ever does, with
  `php -r 'echo base64_encode(sodium_crypto_box_keypair());'`
  (`maintenance_scripts/install_tools/_config_secrets.sh:87-106`, called at
  `:139-140`); `site_keypair()`'s own mint is the fallback.
- The standalone envelope tool
  `maintenance_scripts/sysadmin_tools/backup_envelope.php` carries its own
  sodium copy of the envelope: mint (`:179`), build (`:288-298`), open
  (`:417`), site-key public half (`:481`). `restore_project.sh`
  `resolve_archive_key()` uses it to open an archive's `.keys.json` sidecar
  with `config/backup_site_key` (`:345-358`).
- The recovery possession challenge (`includes/BackupRecoveryKey.php:132-134`).
- The agent's break-glass sidecar for an encrypted data root, Go
  `box.SealAnonymous` in the same envelope shape (`joinery-agent/dataroot.go:181-209`),
  opened by `openSidecar` (`:231-268`).
- The relay seals a Standard mailbox's mail to the transport key with
  `box.SealAnonymous` (`plugins/mailbox/provisioning/relay-sealer/seal.go:50-57`);
  the pull consumer opens it with `openDek` (`plugins/mailbox/includes/RelaySpoolConsumer.php:390`).

**The browser form** (X25519 ECIES: `ephPub[32] ‖ iv[12] ‖ AES-256-GCM(ct ‖ tag)`,
AES key = HKDF-SHA256 over the shared secret with info
`'sealed-vault:dek' ‖ ephPub ‖ recipientPub`):

- Browser: `VaultCrypto.sealToPublicKey()`/`openFromSecretKey()`
  (`assets/js/vault-crypto.js:338-358`), keypair from WebCrypto (`:284-289`).
  Rows carry it as `v1.edgeseal.{scope}.` (`assets/js/joinery-sealed.js:505`);
  Drive file-key grants (`assets/js/drive-crypto.js:223-224`); the device
  handoff seals a vault secret bare (`assets/js/vault-keyring.js:98`,
  `assets/js/device-link.js:128-135`).
- Server: `SealedBox::sealEdge()`/`openEdge()` (`includes/SealedBox.php:217-252`),
  KDF at `:291-300`; `VaultCrypto::sealItemDekToBrowserKey()` frames it
  (`includes/VaultCrypto.php:90-94`); `openItemDeks()` splits classic from edge
  (`:185-210`); `VaultKey::unsealEdge()` (`includes/VaultKey.php:64`).
- Sync client: `sync/jd-crypto/src/vault.rs:193-250`, keypair `:53`.
- Relay: `seal.go:107-183` for a Fortress mailbox's mail.
- Agent: approval challenges `approval.go:568-600`; the copy export sealed to a
  target agent's Ed25519 key converted to X25519
  (`primitives/copy_seal.go:146-183`, opened at `:188`).
- iOS: `ios/joinery-kit/Sources/JoineryKit/VaultCrypto.swift:70-101`; the
  device key is a CryptoKit Curve25519 pair (`DeviceKeyStore.swift:165-176`).
- Android: `android/.../vault/VaultCrypto.kt:76-110`, device key at
  `DeviceKeys.kt:94-98`, X25519 from Tink 1.13 (`build.gradle:81`).
- Three operator challenges use the same construction with their own HKDF
  labels: the recovery key proof (`BackupRecoveryKey::browser_challenge()`,
  `:151-175`; opened by `assets/js/recovery-readiness.js:133-177` and
  `assets/js/backup_key_verify.js:61-105`), restore and decommission approvals
  (`includes/ApprovalChallenge.php`), and the copy-from-backups key handoff
  (`includes/CopyKeyHandoff.php`, `assets/js/copy-key.js:59-93`). Each scope's
  label is part of the HKDF info, so one scope's answer can never be another's:
  `joinery-restore-approval:`, `joinery-decommission-approval:`,
  `joinery-copy-export-approval:` (`ApprovalChallenge.php:102,149,192`; the
  agent's `infoPrefix`, `approval.go:137-190`), and the recovery proof's
  `BROWSER_INFO` (`BackupRecoveryKey.php:159`).
- The phone mail kits seal draft DEKs through their kit's `VaultCrypto`:
  `ios/joinery-kit/Sources/JoineryMailKit/MailDrafts.swift:206`,
  `FortressWork.swift:198-232`,
  `android/joinery-android-mail/.../drafts/MailDrafts.kt:184-191`.
- The browser self-checks mint a throwaway pair and assert
  `publicKeyFromSecret()` matches (`mailbox_search.js:375`,
  `mailbox_fortress.js:1479`); `publicKeyFromSecret()` is at
  `vault-crypto.js:220-224`.
- The data root's LUKS slot pending copy is a second sidecar seal,
  `sealSlotSecret()` (`dataroot.go:177`).
- The live-test harness opens both kinds of challenge itself:
  `SiteCopyLiveRun::prove_recovery_key()` with `sodium_crypto_box_seal_open`
  and `open_challenge()` with X25519 + HKDF + GCM
  (`plugins/server_manager/includes/SiteCopyLiveRun.php:308-343`).

**Three flows let the browser do half the opening and hand the agent the
rest.** These boxes are libsodium sealed boxes (a backup's data key, the
break-glass key). The page computes the X25519 shared secret from the pasted
recovery key and the box's ephemeral key and posts it with the public key. The
agent finishes the open as `crypto_box_seal` does: HSalsa20 key from the shared
secret, BLAKE2b-192 nonce over ephemeral and recipient public keys,
XSalsa20-Poly1305 (`primitives/copy_take_key.go:227-262`, `openRecoverySealed`).
Its callers: the copy take-key (`copy_take_key.go:183`), the restore approval's
key box (`primitives/restore_key_box.go:83`), the holder unlock
(`escrow.go:735`), and the console unlock page (`unlock_page.go:202-223`). The
server checks only shape, both values 32 bytes: `ApprovalChallenge::answer()`
(`:394-397`) and its box reader `key_box()` (`:345-347`, `ephemeral_public`
must be 32 bytes), `CopyKeyHandoff::answer()` (`:73-80`),
`DataRootEscrow::accept_unlock_value()`
(`plugins/server_manager/data/data_root_escrows_class.php:423-437`). The browser
sides are `recovery-readiness.js openKeyBox()` (`:196-230`) and `copy-key.js
work()`.

**Decoy keys.** A sealed-tier Direct receiver answers an unknown address with a
deterministic X25519 point derived from an HMAC
(`includes/joinery_direct/DirectDecoyKeys.php:77-86`), so the sender cannot
tell a real key from a decoy.

### F2. What already holds

- The symmetric and KDF layers: SecretBox (`includes/SecretBox.php:137-153`),
  the vault's XChaCha20-Poly1305 fields (`SealedBox::aeadEncrypt()`, `:168-176`),
  the browser's AES-256-GCM, backup archives (`BackupEnvelope::CIPHER`, `:41`),
  Argon2id, HKDF, PBKDF2. Unchanged by this spec.
- Transit. localdev's Apache on 26.04 negotiates `X25519MLKEM768` today
  (checked with `openssl s_client` against 127.0.0.1:443 on 2026-10-31), because
  26.04 ships OpenSSL 3.5. The hosted data promise spec's T-E11 covers the
  agent's fetch through Cloudflare.
- The agent is Go 1.24 with the 1.27.2 toolchain (`joinery-agent/go.mod`), so
  it has `crypto/mlkem` and `crypto/sha3`. The relay sealer is Go 1.22
  (`relay-sealer/go.mod`).
- Signatures (Ed25519 instance and agent keys, DKIM RSA, passkey ES256) are
  not a copy-now-open-later exposure: a forged signature only matters at
  verification time. Out of scope here.

### F3. What each runtime can do

**PHP.** libsodium on 26.04 is 1.0.18 (`dpkg`), which has no ML-KEM, and PHP 8.5's
sodium and openssl extensions expose none: no `*kem*` function is defined and
`OPENSSL_KEYTYPE_*` stops at X448/Ed448. OpenSSL itself is 3.5.5
(`OPENSSL_VERSION_TEXT`) and lists `ML-KEM-768` and `X25519MLKEM768`
(`openssl list -kem-algorithms`). PHP's FFI extension is loaded from
`php8.5-common` (`dpkg -S`), and `ffi.enable` is at its default `preload`
(commented out at `/etc/php/8.5/fpm/php.ini:1882`), which allows FFI in the CLI
and in preloaded scripts only.

A probe run on dev on 2026-10-31 (`FFI::cdef` against `libcrypto.so.3`,
`EVP_PKEY_fromdata` with the `seed` parameter, `EVP_PKEY_new_raw_public_key_ex`,
`EVP_PKEY_encapsulate`, `EVP_PKEY_decapsulate`) gave:

| Check | Result |
|---|---|
| Key generated from a fixed 64-byte seed | deterministic, public key 1184 bytes |
| Encapsulate to the raw public key | ciphertext 1088 bytes, secret 32 bytes |
| Decapsulate with the seed-derived key | matches |
| 100 encapsulate plus decapsulate pairs | 9.8 ms |

So OpenSSL's audited ML-KEM is reachable from PHP in a few dozen lines, and it
costs a tenth of a millisecond per seal. There is no pure-PHP ML-KEM on
Packagist; a proposal to add `openssl_pkey_encapsulate` to PHP stalled in
February 2025 with nothing merged.

**Browser.** WebCrypto has no ML-KEM. `@noble/post-quantum` (paulmillr) carries
ML-KEM-768 and a `hybrids` sub-path with X-Wing; the platform already vendors
one hash-pinned bundle for a primitive WebCrypto lacks (Argon2id,
`vault-crypto.js:51`, loaded on demand at `:155-169`, pinned by
`tests/functional/drive/sync_crypto_parity.mjs:21`).

**Go.** `crypto/mlkem` with `NewDecapsulationKey768(seed)` and `crypto/sha3`
are in the standard library from 1.24. `filippo.io/mlkem768/xwing` implements
X-Wing draft 07 with `NewKeyFromSeed`.

**Rust.** The `ml-kem`, `x25519-dalek`, `hkdf` and `sha3` crates; an `x-wing`
crate exists at 0.1.0-pre.2 (draft 06), unaudited.

**iOS.** CryptoKit has `XWingMLKEM768X25519` and `MLKEM768` from iOS 26.0. The
kit's floor is iOS 16 (`ios/joinery-kit/Package.swift:24-25`).

**Android.** Tink 1.13 has no ML-KEM. Bouncy Castle 1.84 has ML-KEM-768 with
seed-form private keys (`MLKEMPrivateKeyParameters.getSeed()`), pure Java, so
minSdk 24 (`android/joinery-android/build.gradle:13`) holds.

### F4. Where the 32-byte key size is written down

Public keys grow from 32 to 1216 bytes. The places that check or store 32:

- Parsing: `BackupEnvelope::normalize_public_key()` (`:78-80`),
  `BackupRecoveryKey::parse_public_key()` (`:87`), `classify_key()` (`:241`),
  `set_public_key()` (`:269`); `SealedBox` (`:89`, `:136`, `:304`);
  `logic/device_key_enroll_logic.php:62-63` and its API field `max_length 64`
  (`:108`); `JobCommandBuilder` (`:3077` the copy target's agent key, `:3792`
  and `:6050` the recovery public key as a 43-character base64 regex);
  `approval.go:496`, `:524`; `dataroot.go:154`, `:214`;
  `ApprovalChallenge::key_box()` (`:347`); the relay's map parser
  `decodePublicKey()` (`relay-sealer/seal.go:32-42`), used for the transport key
  and every recipient key (`merge.go:396`, `:437`); the sync client's
  `device_pubkey` at token issue (`includes/ApiAuthEndpoint.php:236-242`); the
  client rotation's own entry point, `VaultClientCustody::begin()`, which
  refuses a pending key that is not 32 bytes (`:695`); the browser pages' `priv.length !== 32`
  checks (`recovery-readiness.js:203`, `copy-key.js:66`) are on the secret and
  stay.
- Columns: `mgn_backup_recovery_public_key varchar(64)`
  (`plugins/server_manager/data/managed_nodes_class.php:208`),
  `cvp_recovery_public_key varchar(64)` (`customer_cloud_provisions_class.php:272`).
  Every other public-key column is already `text` (`uev_public_key`,
  `uev_pending_public_key`, `sde_device_pubkey`, `dlk_device_pubkey`,
  `mrl_transport_public_key`, `stg_value`), and every sealed-key column is
  `text` (21 of them). The `varchar(64)` columns `rci_public_key`,
  `mft_public_key`, `ajr_public_key`, `mgn_agent_public_key` hold Ed25519
  signing keys and do not change.
- API length caps: `public_key` at `max_length 512` in
  `plugins/mailbox/logic/search_key_logic.php:41` and
  `plugins/mailbox/logic/draft_save_logic.php:128` (a v2 key is 1622 base64
  characters). `sealed_dek` and `sealed_vault_key` at 4096 hold a v2 seal
  (about 1600 characters).
- Sizes on the wire: `DirectProtocol::sealedSizeCeiling()` adds
  `SODIUM_CRYPTO_BOX_SEALBYTES` (`:200-202`), and the relay sealer's Go copy
  adds a literal 48 (`relay-sealer/direct_protocol.go:191-198`).
- DNS carries no sealing key: `_joinery-key` is the Ed25519 signing key
  (`docs/joinery_direct.md:58`); the vault key rides the `accept` message
  (`includes/joinery_direct/DirectReceiver.php:231-235`).

Server-custody secrets and the recovery key are 32 bytes and stay 32 (R3), so
the recovery key an operator pastes keeps its shape. Client-custody secrets
are not 32 bytes: they travel as 48-byte X25519 PKCS#8. The browser exports
and imports PKCS#8 (`vault-crypto.js:284-293`) and wraps those bytes (`:303`).
The device handoff ships `vault.pkcs8_hex`
(`tests/vault/fixtures/device_handoff_vector.json`). Rust uses
`secret_key_pkcs8` (`sync/jd-crypto/src/vault.rs:44-75`, `:225-230`). iOS
treats a vault secret as PKCS#8 (`VaultCrypto.swift:16`, `:42-44`), and
`DeviceVault.swift:310` wraps a 32-byte opened secret into X25519 PKCS#8.
Android uses `pkcs8Encode`.

### F5. The rotation machinery that exists

- Server-custody vault rotation: `VaultCeremonies::rotate()`
  (`includes/VaultCeremonies.php:278`) mints a new keypair, persists the new
  generation's wrappings first, then walks every consumer registered with
  `VaultUnlock::onReseal()` (`VaultUnlock.php:903`; the walk is
  `drainAndRetire()`, `VaultCeremonies.php:596-600`; registrants: `ApiIdempotencySealed`,
  `CalendarSealed`, `DriveSealed`, `DirectSpoolDrain`, and the mailbox,
  messenger, joinery_ai and server_manager bootstraps), each re-sealing with
  `sealItemDek()` to the new public key, and only then retires the old
  generation (`docs/sealed_vault.md:1016-1079`). Re-running completes an
  interrupted rotation.
- Client-custody rotation: the browser mints the pair and calls
  `vault_client_rotate_begin` (`joinery-sealed.js:592-645`); the server stores
  it as `uev_pending_public_key` (`VaultClientCustody::begin()`, `:691-732`),
  new material seals to the pending key meanwhile
  (`UserEncryptionVault::sealingPublicKey()`), pages are re-sealed
  (`resealPage()`/`resealRows()`, `:749-810`) and `commit()` flips the row
  (`:811-835`).
- Backup recovery key rotation: the Backups page's rotate ceremony
  (`RecoveryKeySetupPanel::renderRotate()`, `:151`); `BackupObjects::reseal_epochs()`
  opens each epoch's envelope with the site key and rebuilds it for the new
  recipients (`:507-538`), so no archive is re-uploaded and nothing offline is
  needed. It skips an envelope whose recovery fingerprint is already current
  (`:516`), and an envelope that fails to rebuild is logged and passed over
  (`:533`), not fatal to the run.
- The data root's break-glass copy: `joinery-agent data-root reseal --recipient
  FILE` (`cli.go:429`), driven by the management node's `data_root_recipient`
  word (`JobCommandBuilder::build_data_root_recipient()`, `:3791`).
- The relay transport key is minted once (`MailboxRelay::ensureTransportKeypair()`,
  `plugins/mailbox/data/mailbox_relays_class.php:195-208`) and has no rotation.

### F6. Operator surfaces that handle a key

- Generate-in-browser recovery key (`recovery-readiness.js generateKeypair()`,
  `:96-105`), shown once, pasted back, proven against a challenge
  (`docs/backups.md:1003-1045`).
- `maintenance_scripts/sysadmin_tools/escrow_keypair.php`: standalone CLI,
  needs only PHP and libsodium (`:30-33`), used in the proof's CLI fallback
  (`BackupReadinessItems.php:85-86`, `RecoveryKeySetupPanel.php:210`).
- The disaster recipe in `docs/backups.md:975-1000`: a `php -r` one-liner
  opening the envelope with libsodium, then `openssl enc` for the archive.
- `joinery-agent data-root unseal --sidecar FILE` for a break-glass copy.

### F7. Deployment shape

New sites install only on 26.04: the Docker base image is
`ubuntu:26.04` (`maintenance_scripts/install_tools/Dockerfile.base:31`),
engine hosts refuse any other release (`docs/installation.md:318`). Bare metal
`install.sh server` still accepts 24.04 (`install.sh:3715-3733`,
`docs/installation.md:5`, `:101`). No managed node runs 24.04.

## Design

### R1. One hybrid KEM everywhere: X-Wing

X-Wing (draft-connolly-cfrg-xwing-kem-11, 2026-09-23) is the CFRG's
general-purpose hybrid of X25519 and ML-KEM-768. It is what Apple ships in
CryptoKit, what Filippo's Go package and the RustCrypto crate implement, and
what noble carries. Its bytes have been stable since draft 05 (the change log's
later entries are code points, ASN.1 and wording), so a library at draft 06 or
07 interoperates with one at 11.

| | Size | Layout |
|---|---|---|
| Decapsulation (secret) key | 32 bytes | a random seed |
| Encapsulation (public) key | 1216 bytes | `pk_M[1184] ‖ pk_X[32]` |
| Ciphertext | 1120 bytes | `ct_M[1088] ‖ ct_X[32]` |
| Shared secret | 32 bytes | `SHA3-256(ss_M ‖ ss_X ‖ ct_X ‖ pk_X ‖ "\.//^\")` |

Key generation expands the seed with SHAKE256 to 96 bytes: the first 64 are
ML-KEM's `d` and `z`, the last 32 the X25519 secret. Because the secret is a
seed, server-custody secrets and the recovery key keep their 32-byte shape,
and client-custody secrets shrink from 48-byte PKCS#8 to the same 32 bytes
(R3). Only public keys and ciphertexts grow.

The platform adds nothing of its own to the KEM. The seal is X-Wing plus one
AEAD, below.

### R2. One seal format, `v2`, for every runtime

Today's two v1 families existed because libsodium's sealed box has no WebCrypto
equivalent (`SealedBox.php:12-14`). X-Wing plus AES-256-GCM can be written
identically in all six runtimes, so v2 is one format:

```
raw   = ct[1120] ‖ iv[12] ‖ AES-256-GCM(plaintext) ‖ tag[16]
key   = HKDF-SHA256(ikm = ss, salt = '', info = 'joinery-seal:v2:' ‖ scope, 32 bytes)
AAD   = none (as both v1 families: a key seal binds to its row through the row's own AEAD, not here)
```

`scope` keeps every separation v1 has. A row DEK, a wrapped secret, a Drive
grant, a device handoff, a backup recipient and a sidecar use `dek`. Each
challenge uses its v1 label: `joinery-restore-approval:`,
`joinery-decommission-approval:`, `joinery-copy-export-approval:`, and the
recovery proof's `BROWSER_INFO`. So a restore approval's answer still cannot
open a decommission box (`approval.go:137`).

A box the browser only half-opens (a backup's data key, the break-glass key;
F1) is a v2 `dek` box like any other. The browser decapsulates in full,
applies HKDF with `joinery-seal:v2:dek` itself, and posts that 32-byte AES
key, never the X-Wing shared secret (reviewer2 WP1 B1): the shared secret
depends only on the ciphertext and the seed, so whoever staged the box could
substitute a pending approval challenge's ciphertext and turn the posted
secret into that scope's key. The dek-scope key opens only dek boxes. The
agent's opener opens the GCM with it (`SealedBox::openV2WithKey()` is the PHP
twin). So `openRecoverySealed` gains a v2 sibling, chosen by the box's
version, for its four callers. The posted public key is then only matched
against the box's fingerprint. The answer fields keep their names (`shared`);
for a v2 box the value is the dek-scope key.

v1's HKDF info carried `ephPub ‖ recipientPub`; v2's does not need them.
X-Wing's combiner already binds `ct_X` and `pk_X`, and ML-KEM's shared secret
binds its own ciphertext and encapsulation key.

Framing follows the frames the row layer already parses, so no parser learns a
new shape:

- `v2.seal.` + base64url(raw): a server-custody DEK or wrapped secret (today's
  `v1.seal.`).
- `v2.edgeseal.{scope}.` + base64(raw): a client-custody row DEK (today's
  `v1.edgeseal.{scope}.`); `VaultCrypto::parseEdgeScope()` and
  `clientCustodyScope()` accept both versions.
- bare base64(raw), where the recipient's key type is known from the key
  itself: the device handoff, a Drive file-key grant, a backup envelope
  recipient, the agent's sidecar, every challenge blob. A v2 public key only
  ever receives v2 blobs, so the sealer never guesses.
- raw bytes with no frame for a bulk Direct part (today's `sealBinary()`);
  `sealedSizeCeiling()` adds 1148 for a v2 recipient.

The backup envelope and the agent's sidecar envelope go to `version: 2`, where
each recipient carries `"alg": "xwing"`. Readers accept version 1 and 2; the
version-1 recipient has no `alg` and is a sodium sealed box.

**Sealers emit v2 only.** `sealDek()` and its siblings, in every runtime, seal
to a 1216-byte key and refuse a 32-byte one, naming the rotation the holder
owes. No code path mints a v1 keypair or writes a v1 blob after this ships. A
row, message or grant aimed at a holder who has not rotated fails until they
have.

**Openers keep v1 only for the rotation.** The rotation has to read what is
sealed to the old key (the vault resealers, the relay's spool, and an old
backup restored with the old recovery key), so the v1 open paths stay as they
are, and nothing new is built on them. An opener chooses by the blob's frame
or by the key's recorded version, never by secret length: a server-custody
v1 secret and a v2 seed are both 32 bytes, so the secret cannot name its
version. (On the client side length does tell them apart, 48-byte PKCS#8
against a 32-byte seed, R3, but the openers use the same rule there for one
code path.) `openDek()` reads the frame (`v1.seal.` or
`v2.seal.`; `unframeSeal()` learns `v2` and returns the version beside the
bytes). `openEdge()` and the browser's `openFromSecretKey()` are already
handed the recipient's public key beside the secret (`SealedBox.php:228`,
`vault-crypto.js:349`), so they branch on that key's length. `openBinary()`
and every other bare-base64 opener get neither a frame nor a public key, so
the caller passes the version. The Rust, Swift and Kotlin openers follow the
same rule. A `VaultKey` carries it: `PoolVaultKey::open()` takes the vault
row's public key, derives the candidate public half for that key's version
(32 bytes: X25519; 1216 bytes: X-Wing keygen), and refuses when it does not
match. The window slot stores the version with the secret, so `unseal()` and
`unsealEdge()` never guess.

**Where a vault key's version comes from (as built).** Deriving it from the vault
row's public key fails in one case the rotation hits: after a crash between the
flip and the retire, the old generation's public key is no longer on the row,
and `completePendingRotation()` must still open it. So the version travels with
the secret itself: the plaintext a wrapping holds, and the window slot, is
`v2.` + base64url(seed) for a v2 key and the bare base64url secret for a v1
key, which is every wrapping written before v2 (`PoolVaultKey::wrappedForm()`).
No vault-row lookup is needed and an old slot still reads as v1. Backup and sidecar openers match by fingerprint
(R3).

### R3. Keys and their text forms

- Public: 1216 raw bytes, base64 (standard or url, as the context already
  uses). Fingerprint stays `sha256(raw)`, now over 1216 bytes, so a v2 key's
  fingerprint can never collide with its own v1 predecessor's.
- Secret: the 32-byte seed, base64, 44 characters, in every custody. A pasted
  recovery key looks exactly as it does today, so the browser pages'
  `priv.length !== 32` checks stay. A client-custody v2 secret is the bare
  seed, not PKCS#8 (draft-11's ASN.1 module defines one, but one bare form in
every custody and runtime is simpler than carrying a DER wrapper). Every PKCS#8
  consumer listed in F4 changes: the browser's keypair, import,
  `publicKeyFromSecret()` (X-Wing keygen from the seed) and wrapping; the
  device handoff and its vector (`vault.seed_hex`); Rust's
  `secret_key_pkcs8`; iOS `scalar(fromPKCS8:)` and
  `DeviceVault.swift:310`; Android `pkcs8Encode`. A wrapping that opens to 48
  bytes is v1, and one that opens to 32 bytes is v2.
- The two 43-character regexes in `JobCommandBuilder` (`:3792`, `:6050`)
  check the recovery **public** key and change to the 1216-byte form.
- Telling a v1 secret from a v2 one, where both can be pasted (an old backup
  while retention holds it, a new one): derive both candidate public keys from
  the 32 bytes (X25519 base-point multiple; X-Wing keygen), fingerprint both,
  and match against the box's recorded fingerprint. The mismatch message
  already exists (`recovery-readiness.js:223`). No prefix on the secret.
- Decoys: `DirectDecoyKeys::publicKeyFor()` uses the HMAC output as an X-Wing
  seed and publishes the derived 1216-byte key, from the release on, and the
  seed is discarded as the scalar is today. An unrotated vault's address is
  answered with its decoy key, as an unknown address is
  (`DirectReceiver.php:228-230`), never with its 32-byte key. A plain refusal
  would show the address is real. So every key on the wire is 1216 bytes, and
  a decoy cannot be told from a real one. Decoy keygen costs about 0.1 ms
  more than the scalar multiply it replaces; the `accept` answer's timing was
  already the weak point, and this does not change it. The cost: Direct mail to that
  address until its owner rotates goes where decoy mail goes, and is not
  delivered.
- Columns: `mgn_backup_recovery_public_key` and `cvp_recovery_public_key`
  become `text` (schema from the field specification, no migration). The
  device enrol API field's `max_length` goes to 2048, as do the two mailbox
  `public_key` fields (F4). `ApiAuthEndpoint` and `VaultClientCustody::begin()`
  accept 1216 bytes.

### R4. PHP: ML-KEM through FFI to OpenSSL 3.5, X-Wing in PHP on top

A new core class `MlKem768` wraps five libcrypto calls over `FFI::cdef`:
`EVP_PKEY_fromdata` with the `seed` octet string (deterministic keygen),
`EVP_PKEY_get_raw_public_key`, `EVP_PKEY_new_raw_public_key_ex`,
`EVP_PKEY_encapsulate`, `EVP_PKEY_decapsulate`, plus SHAKE256 through
`EVP_DigestFinalXOF` (PHP's `hash()` has SHA3-256 but no SHAKE). It refuses to
construct when `OpenSSL_version_num()` is below 3.5 or FFI is unavailable, with
one plain message naming the floor. The C declarations are the public, stable
3.x API; nothing depends on a private struct beyond `OSSL_PARAM`, which is
documented.

`XWing` (keygen from seed, encapsulate, decapsulate) is forty lines over
`MlKem768`, `sodium_crypto_scalarmult`, `hash('sha3-256')`. `SealedBox::generateKeypair()`
(`:72-81`) changes to mint a v2 pair. `sealDek()`, `sealBinary()` and
`sealEdge()` grow the v2 branch, chosen by the public key's length. `openDek()`,
`openBinary()` and `openEdge()` grow theirs, chosen by frame or by the passed
version (R2). `PoolVaultKey` learns its version from the vault row (R2) and
keeps the expanded ML-KEM key for the window's life, so a page of rows
decapsulates without re-expanding.

`ffi.enable` must be `On` for the FPM SAPI; the CLI already allows FFI at the
default. `host_files_tune_php_ini` (`_host_files.sh:60`, called from
`install.sh:4346` on the FPM ini, `:4332`) writes it on a fresh install. That
is not enough for a server already running: `host_housekeeping` only runs the
tuner on a php.ini still byte-identical to `php.ini-production`, or one it is
rebuilding (`host_housekeeping.sh:696-724`), so every tuned server would keep
`preload`. So housekeeping gains one separate, idempotent step that runs on
every converge for each FPM php.ini: if `ffi.enable` is not `On`, set it and
restart that FPM. D3 records the alternative.

`SealedBox::pqReady()` answers whether this process can seal v2, and the
answer is shown: a setup step item, a line on the Recovery Readiness page, a
health check. Nothing degrades to v1 when the answer is no; a seal that needs
v2 refuses with the floor message. That is the one place the 26.04 floor is
enforced in code (R8 is the other, at install).

### R5. Browser

`assets/vendor/noble/` holds one bundled, hash-pinned build of
`@noble/post-quantum` (ML-KEM-768, SHA3, SHAKE256) loaded on demand the way
Argon2 is (`loadArgon2()`, `vault-crypto.js:155-169`). X-Wing is written in
`vault-crypto.js` over it and WebCrypto's X25519 (`deriveBits` gives the raw
shared secret, as today) unless the vendored build's `hybrids` export matches
the vectors, in which case it is used as is. `generateVaultKeypair()` mints a
v2 pair; `sealToPublicKey()` and `openFromSecretKey()` branch on the
recipient public key's length (both are handed it; R2);
`publicKeyFromSecret()` does X-Wing keygen from the seed (R3), so the
mailbox self-checks (F1) keep working; `isSupported()` also reports whether the
bundle loaded, and a page that cannot
says so plainly, as it does for a browser without X25519 (`:452-460`).

The three challenge pages (`recovery-readiness.js`, `backup_key_verify.js`,
`copy-key.js`) open a v2 challenge by decapsulating in full and, where the
agent finishes the open, post the 32-byte X-Wing shared secret and the
1216-byte public key. The agent's box carries `kem_ciphertext` (1120 bytes)
in place of `ephemeral_public`. The server-side shape checks
(`ApprovalChallenge:347`, `:394-397`, `CopyKeyHandoff:77`,
`DataRootEscrow::accept_unlock_value()`) accept 32 for the secret and 1216
for the key. The live-test harness's openers (`SiteCopyLiveRun.php:308-343`)
gain the same v2 path through `SealedBox`, not a third copy.

The node gates (`tests/vault/device_handoff_vector_gate.sh`,
`sync_crypto_parity.mjs`) load the same vendored bundle, so a change to it
fails the gate before a browser sees it.

### R6. Sync client, relay, agent, phones

- **Rust** (`sync/jd-crypto`): compose from `ml-kem`, `x25519-dalek`,
  `sha3`, `hkdf`, matching the vectors, rather than depend on a pre-release
  crate; `vault.rs` gains the v2 seal/open and seed keygen; `sync/jd-daemon/src/link.rs`
  mints a v2 device key (`:131`).
- **Go** (`relay-sealer`, `joinery-agent`): standard library only,
  `crypto/mlkem`, `crypto/ecdh`, `crypto/sha3`, `crypto/hkdf`. The relay
  sealer's `go.mod` goes to 1.24. `seal.go` seals in the format the map's key
  names; `direct_protocol.go`'s size ceiling adds 1148 for a v2 recipient, as
  the PHP one does; `dataroot.go` writes a version-2 sidecar and opens both;
  `approval.go` seals a v2 challenge when the proven recovery key is v2. The
  agent updates itself from release files (`primitives/release_files.go:73`,
  `:153`), so for a short window after the plane upgrades, a
  `data_root_recipient` with a 1216-byte key fails at `dataroot.go:154`. It
  succeeds on retry once the agent has updated.
- **The agent's own KEM key.** A copy export is sealed to the target agent's
  Ed25519 identity converted to X25519 (`copy_seal.go:146`). An Ed25519 key
  has no ML-KEM counterpart, so the agent holds a v2 KEM keypair beside its
  identity and reports the public half (new column
  `mgn_agent_kem_public_key text`). `sealToAgentKey()` (`copy_seal.go:147`)
  seals to that. A machine joining reports it in the join payload
  (`join.go:390-397`). A machine already joined never joins again, so the agent
  also mints the key on its first run without one and reports it at check-in.
  The plane fills the column from whichever arrives first.
  `JobCommandBuilder::build_copy_export_primitive()` (`:3075-3078`) reads the
  new column, and refuses a copy export to a target whose column is still
  empty, naming the agent upgrade it is waiting on.
- **iOS**: CryptoKit's `XWingMLKEM768X25519`, iOS 26 the floor (D4).
  `Package.swift` goes to `.iOS(.v26)`; `VaultCrypto.swift` and
  `DeviceKeyStore.swift` gain the v2 paths, with no availability gate and no
  second path. The package floor carries every app built on the kit:
  `joinery-member-ios` and `scrolldaddy-ios` both sit at
  `deploymentTarget: "16.0"` (`project.yml:23,66` and `project.yml:30`) and
  move to 26.0 with it (Q7).
- **Android**: Bouncy Castle 1.84 for ML-KEM-768 and SHA3; X-Wing composed in
  `VaultCrypto.kt`; `DeviceKeys.kt` mints v2.

Every port is held to the one vector file (R9), as the device handoff vector
holds them today.

### R7. Migration is rotation, forced at upgrade

No blob is converted in place, and nothing seals in two formats side by side
except the relay, briefly (Q8).
The upgrade makes every v1 key unusable for sealing (R2), and each key
rotates once to v2 through the path that already exists. Until a key has
rotated, whatever needs it refuses (owner, 2026-10-31).

Rotation is per vault, not per user. A user holds one vault per scope: `user`
(server custody), and `drive`, `mail`, `passwords`, `root` (client custody).
Dev has 29 vaults over 12 users today. `vault_rotate_verify_logic.php:71`
rotates one vault, and each client-custody ceremony costs its own passkey
re-adds and recovery codes (`docs/sealed_vault.md:1074-1079`).

- **Server-custody vaults**: from the upgrade, a v1 vault does not open. Its
  owner's next interactive unlock goes straight into `VaultCeremonies::rotate()`,
  which mints a v2 pair, and the registered resealers do the rest (F5). The
  ceremony costs what it costs today: new recovery codes to save, other
  passkeys to re-add (`docs/sealed_vault.md:1074-1079`). `rotate()` takes a
  passkey credential (`VaultCeremonies.php:278`), so it needs the user there.
  The refusal sits in `VaultUnlock::open()` and `arm()` (`VaultUnlock.php:449`,
  `:226`). `rotate()` still opens the v1 generation through
  `VaultUnlock::openKey()` (`VaultCeremonies.php:326-331`), which `open()`
  shares, so the refusal must sit above `openKey()`, not in it. No unattended
  unlock exists to refuse: a CLI process never holds a window
  (`VaultDeferredWork.php:1-16`).
- **What stops for an unrotated vault is sealing to it.** That happens with
  nobody present: every sealed-row save (`SystemBase::sealWrappingAssignments()`,
  `:1329-1337`), messenger key grants (`conversation_key_grants_class.php:80`),
  the mail index (`MailboxIndex.php:546`), and Direct identities
  (`protect_identity.php:150`). Those refuse with the rotation owed. For mail,
  that has a clock:
  - **Site inbound**: any failure defers (`InboundEmailRouter.php:467`,
    `:493`, `:686`), so senders retry for their queue life (days) and then
    bounce.
  - **Relay spool**: a failing entry is left un-acked
    (`RelaySpoolConsumer.php:192-195`), and only `held` entries age out
    (`:570-589`, 30 days), so the spool grows until the owner rotates.

  The admin dashboard counts vaults still on v1, by scope, and names the ones
  blocking mail: "N mail vaults are refusing inbound mail; senders start
  bouncing after their retry window."
- **Client-custody vaults**: the same refusal. The browser's rotation mints v2
  (`joinery-sealed.js:592-645`); server unchanged beyond R2.
- **Devices**: a rotation already means a new secret, so each linked device is
  re-linked, with its new v2 device key.
- **Backup recovery key**: backups refuse while the recovery key is v1 (the
  sealer refuses a 32-byte recipient), and Recovery Readiness says the operator
  owes a new key. The existing rotate ceremony (`RecoveryKeySetupPanel::renderRotate()`)
  generates the v2 pair in the browser. If a run starts in the minute between
  the upgrade and the converger replacing the site key, the site recipient is
  still 32 bytes, so the run refuses like any seal to a v1 key (inside
  `reseal_epochs()` that is caught at `BackupObjects.php:533`). The next run
  succeeds. No special case.
- **Site backup key**: the converger replaces it. `_config_secrets.sh`
  (`:87-106`, run at `:139-140`) mints a 32-byte seed in place of
  `sodium_crypto_box_keypair()`. When it finds a 64-byte v1 keypair in
  `backup_site_key`, it renames the file to `backup_site_key.retired` and
  mints the seed. No code reads the `.retired` file; it is there for an
  operator to pass by hand to `backup_envelope.php open --private`.
  `site_keypair()` reads only the seed form.
- **Old backups need no new code.** The next run's epoch check sees the site
  key no longer opens the stored envelope and the recovery fingerprint has
  changed, so it starts a new epoch sealed to the v2 keys
  (`BackupObjects::epoch_decision()`, `:386-406`). `reseal_epochs()` cannot
  open the old envelopes with the new site key, so it lists them as
  unopenable (`:520-523`). `write_retired_epochs()` records them, and Recovery
  Readiness reports them as opening only with a retired recovery key
  (`BackupRunner.php:2504-2526`). That is what happens today when a site key is
  lost. Old envelopes stay openable with the old recovery key the operator
  keeps, as rotation already promises (`RecoveryKeySetupPanel.php:154`), until
  they age out of retention.
- **Break-glass copies**: once the operator has a v2 recovery key, the
  management node pushes it with `data_root_recipient` and the agent re-seals.
  Until then the existing sidecar stays as it is.
- **The relay goes first (owner, 2026-10-31; Q8).** Today's sealer refuses any key
  that is not 32 bytes. Its map parser `decodePublicKey()`
  (`relay-sealer/seal.go:32-42`) is applied to the transport key
  (`merge.go:396`) and every recipient key (`:437`). A relay is rebuilt only
  when its operator chooses: a drain and re-image for cloud relays, a hand
  rebuild for hand-run ones, operator-managed for fleet slots
  (`plugins/mailbox/docs/overview.md:3270-3325`). On a relay not yet rebuilt,
  the transport key replacement never applies. Worse, a Fortress `mail`
  rotation can never commit: `RelayMapSync::rotationRefusal()`
  (`RelayMapSync.php:70-82`, `bootstrap.php:31-33`) holds the commit until the
  relay reports the new key, so that mailbox refuses forever. So:
  1. The new relay sealer (`RELAY_VERSION` above today's 3.15,
     `provision_relay.sh:122`) seals in the format each key names: 32 bytes
     v1, 1216 bytes v2.
  2. Every relay is rebuilt to it before any site it fronts upgrades. The
     rebuild happens while nothing is held, so the drain's "refuses to advance
     if anything is held" (`overview.md:3295-3300`) does not trap it.
  3. A site's upgrade refuses while its active relay reports an older version
     (`RelayVersion`), and names the relay rebuild owed.
- **Relay transport key**: the upgrade mints a v2 key
  (`ensureTransportKeypair()` mints v2, and an existing v1 key is replaced) and
  re-pushes the map. The consumer keeps the old secret only to open mail
  already in the spool (a v1 blob is a `crypto_box_seal`; R2 openers tell them
  apart). It drops the old secret at the first pull that finds no v1 blob.
- **Direct identities and every other consumer**: through the vault resealers.
- **Direct between an upgraded and an older instance**: an upgraded sender
  handed an older receiver's 32-byte key refuses to seal and reports the
  delivery failed with V1_KEY_REFUSAL's reason; an older sender cannot seal to
  a 1216-byte key and fails the same way on its side. Mail between them waits
  until both run the release (reviewer2 WP1 N2).
- **Decoys**: v2 from the release (R3).

### R8. The floor: Ubuntu 26.04 or later

`install.sh server` accepts Ubuntu 26.04 and every later release: the gate
reads `VERSION_ID` from `/etc/os-release` and compares it as a version
(`dpkg --compare-versions "$VERSION_ID" ge 26.04`), rather than matching a
fixed list of releases as the regex at `:3715` does today. The refusal text
names 26.04 (`:3715-3733`). `docs/installation.md:5` and `:101` say 26.04.
`--allow-unsupported-os` stays for the determined. Docker and engine hosts
already require it (F7). The floor's reasons live here, not in the docs.

### R9. Vectors and tests

`tests/vault/fixtures/xwing_vector.json`: the draft's Appendix C vectors
(seed, public key, encapsulation seed, ciphertext, shared secret) plus a
Joinery v2 seal vector (seed, encapsulation seed, IV, plaintext, blob)
generated by the PHP implementation. Draft-11 marks Appendix C "TODO: replace
with test vectors that re-use ML-KEM, X25519 values", so the file records the
draft revision its vectors came from, and a later draft's vectors are a
reviewed change to it.

Every runtime opens every blob and decapsulates every vector. Reproducing the
*encapsulation* bytes needs a way to fix the encapsulation randomness, and
the runtimes differ:

- PHP: OpenSSL's ML-KEM takes fixed encapsulation entropy only through a
  testing parameter (`ikme`). The 2026-10-31 probe did not try it, so WP0
  proves it before WP1 depends on it. If it does not work, PHP checks
  encapsulation by round trip like iOS, and the v2 seal vector is generated by
  the Go implementation instead.
- Go: `crypto/mlkem/mlkemtest` (in the 1.27 toolchain both modules build
  with).
- Node: noble's `encapsulate(pk, msg)`.
- Rust: the `ml-kem` crate's deterministic encapsulation.
- Android: Bouncy Castle with a fixed `SecureRandom`.
- iOS: CryptoKit has no way to supply the randomness. The Swift target
  decapsulates the vectors, opens the blobs, and round-trips its own
  encapsulations; it does not reproduce encapsulation bytes.

No vector asserts a particular error for an all-zero X25519 output.
WebCrypto's `deriveBits` throws on one, while OpenSSL, Go and CryptoKit return
it, so the runtimes disagree. Only a malicious `ct_X` produces one, and the GCM
tag fails either way.

The tests: PHP (`tests/vault/xwing_vector_test.php`), node
(`xwing_vector.mjs` under a shell gate, the device handoff pattern),
`cargo test`, `go test` in both modules, the Swift and JVM test targets. A
PHP suite also holds `SealedBox` to the v1 vectors it has, so old blobs keep
opening until the last rotation.

### R10. Out of scope

Signatures (Ed25519, P-256 release statements, DKIM RSA, passkey ES256): no
copy-now-open-later exposure; a later spec, ML-DSA. TLS on anything but 26.04.
HPKE. ML-KEM-1024.

## Decisions

**D1. Pure PHP or FFI. Decided: FFI, with the 26.04 floor.** The owner decided
2026-10-31 that a 24.04 fallback is a second implementation of one primitive,
with two code paths that must produce the same bytes, our own lattice
arithmetic to maintain, and a known-answer suite run against both. OpenSSL's
ML-KEM is audited and constant time; the binding is a few dozen lines.

**D2. X-Wing or a construction of our own.** X-Wing: a named draft with vectors,
four independent implementations to check against, Apple's native API. Its
catch: still a draft, which could change bytes again before publication; its
bytes have held since draft 05 and the platform's blobs are versioned, so a
change would be a `v3`, not a rewrite. A home-made combiner would avoid the
draft but lose every library. X-Wing.

**D3. `ffi.enable=On` or a preloaded FFI scope.** PHP's FFI extension lets a
script call a C function in a shared library after declaring its signature;
it is how `MlKem768` reaches the ML-KEM inside libcrypto that the openssl
extension does not expose. `ffi.enable` takes three values: `false`, off;
`true`, any script may declare and call bindings; `preload` (the default,
dev's `/etc/php/8.5/fpm/php.ini:1882`), bindings may be declared only by the
CLI and by the script `opcache.preload` runs once at FPM start, and a web
request may use those through `FFI::scope()` and declare none of its own. So
backups, the cron runner and the tests already work at the default; only the
web needs a choice.

- `On`: one line in each FPM php.ini, written by `host_files_tune_php_ini` at
  install and kept by a `host_housekeeping` step on servers already running
  (R4). The Docker base image gets it from the same writer. What it permits is
  PHP in the web process calling into any library on the box. The web
  process can already run any program: `disable_functions` is empty
  (`php.ini:323`), so `exec`, `proc_open` and friends are live. FFI adds no
  reach a shell did not have. One class in the tree declares a binding, and a
  mechanical test fails on a second.
- `preload`: a `joinery_preload.php` named by `opcache.preload`, running as
  `opcache.preload_user=www-data`, calling `FFI::load()` on a header that
  carries `FFI_SCOPE` and `FFI_LIB`; requests take `FFI::scope('joinery_mlkem')`.
  Catches: the preload runs at FPM start, so a changed header waits for the
  restart a deploy already does (`JobResultProcessor.php:2955`); a fault in
  the preload script stops FPM from starting at all, which turns a bad deploy
  of one feature into a site down; the CLI still has to declare its own, so
  `MlKem768` carries two ways of getting the same binding; and
  `opcache.preload` is a second early-boot hook to keep working on every
  release. PHP chose `preload` as the default for hosts running strangers'
  PHP. Every PHP on these hosts is ours.

**Decided by the owner, 2026-10-31: `On`.** `pqReady()` checks the extension
is loaded, the setting is effective in this SAPI, libcrypto is 3.5 or later,
and one encapsulate and decapsulate round trip passes.

**D4. iOS.** CryptoKit's X-Wing exists from iOS 26.0. swift-crypto, Apple's
open-source package, is not a way around that on a phone: its README says it
"compiles its entire API surface down to nothing and simply re-exports the
API of CryptoKit" on Apple platforms, so its X-Wing is CryptoKit's with the
same floor (and its BoringSSL path had CVE-2026-28815 until 4.3.1). iOS 26
runs on iPhone 11 and later; Apple's June 2026 figures put 79% of all iPhones
and 86% of those sold in the last four years on it. The phones that can never
reach it are the XR, XS and older.

Two ways to keep older phones:

- **Vendor a C ML-KEM as a SwiftPM C target.** `mlkem-native` (pq-code-package)
  is a C90 ML-KEM with CBMC memory-safety proofs, used by AWS-LC, carrying its
  own FIPS 202 (SHA3 and SHAKE), so it supplies everything CryptoKit lacks
  before 26 in one small library. X-Wing is forty lines of Swift over it and
  CryptoKit's X25519. The catch: a vendored copy we track and rebuild, and a
  C target in the kit's build. Not our own arithmetic.
- **An availability gate:** CryptoKit on iOS 26, nothing older. Older phones
  sign in and read what is not sealed but cannot hold a vault key.

**Decided by the owner, 2026-10-31: iOS 26 is the floor.** CryptoKit alone,
nothing vendored, one path. The vendored C target stays recorded here as the
way back if the floor ever has to drop.

**D5. Shared secret straight into AES, or through HKDF.** X-Wing's output is
uniform and already binds `ct_X` and `pk_X`. One HKDF step costs nothing and
gives the seal a label of its own, so a shared secret can never key anything
but a v2 seal. HKDF.

## Open items for the owner

- **Q1. Require every vault to rotate? Decided 2026-10-31: require, at upgrade, with no grace period** (R7).
- **Q2. iOS floor: decided 2026-10-31: iOS 26**, CryptoKit only (D4).
- **Q3. `ffi.enable`: decided 2026-10-31: `On`** (D3).
- **Q4. The agent's KEM key: decided 2026-10-31: now**, in WP3.
- **Q5. When decoys become v2: at release** (no transition, owner 2026-10-31;
  R3).
- **Q6. An unattended unlock of a v1 vault: refused** until its owner rotates
  (owner 2026-10-31; R7). Reviewer1 found that no unattended unlock exists. What
  happens with nobody present is sealing to the vault, and that refuses (R7).
- **Q7. ScrollDaddy iOS goes to iOS 26 with the kit: yes** (owner
  2026-10-31). One floor for every app on the kit (R6).
- **Q8. Relays and the cutover: relay first** (owner 2026-10-31). The new
  sealer seals whichever format each key names. Every relay is rebuilt to it
  before its sites upgrade, and a site's upgrade refuses until its relay is
  current (R7). An in-product gate was the alternative; it would have kept
  mail vaults on v1 inside upgraded sites.

## Work packages

Each is self-contained, tested on its own, and lands in this order because
each later one is checked against the PHP vectors of WP1.

**WP0. Floor and plumbing.** `install.sh` OS gate and text (R8);
`host_files_tune_php_ini` writes `ffi.enable=On`, and `host_housekeeping`'s
re-assert step (R4); `docs/installation.md`; `SealedBox::pqReady()` with its
setup, readiness and health lines; the `MlKem768` FFI class with a safe-tier
test that runs the ML-KEM known-answer vectors on dev, including one
deterministic encapsulation through `ikme` (R9). Acceptance: a fresh 26.04
install (bare metal and Docker) reports ready; dev, an already-tuned server,
reports ready after one converge; a 24.04 box is refused at install.

**WP1. PHP primitive and formats.** `XWing`; `SealedBox` v2 branches, with
openers choosing by frame or passed version and per-scope HKDF info (R2);
`VaultCrypto` frames; `PoolVaultKey` version from the vault row and in the
window slot; `BackupEnvelope` version 2 with `alg`, site key seed form, both-version opening, the public-pasted detection
at `:248-258` keyed on 1216; `BackupRecoveryKey` parsing, challenge and
browser challenge in v2; `DirectDecoyKeys` v2;
`DirectProtocol::sealedSizeCeiling()`; `ApprovalChallenge`, `CopyKeyHandoff`,
`DataRootEscrow` shape checks, and `ApprovalChallenge::key_box()`;
`ApiAuthEndpoint` `device_pubkey`, `VaultClientCustody::begin()` and the two
mailbox `public_key` caps at the v2 size; `SiteCopyLiveRun`'s openers; the two
`varchar(64)` columns; the vector file and `xwing_vector_test.php`; the
converger's site-key minter (`_config_secrets.sh`) in seed form;
`backup_envelope.php` (mint, build, open of both versions, `site-key`) and
`escrow_keypair.php` (opens both versions). Both standalone tools need OpenSSL
3.5 on the operator's machine for v2, and say so. Acceptance: every existing
vault and backup suite passes with its fixtures minting v2 keys; the v2 vector
round-trips; the existing v1 vectors still open; sealing to a 32-byte key
refuses and names the rotation owed; a v2 blob handed a v1 opener is refused
with the version named; a restore-scope answer does not open
a decommission-scope box; a fresh site's converger mints a 32-byte seed.

**WP2. Browser.** Vendored bundle and its pin; `vault-crypto.js` v2 with the
bare-seed client secret in place of PKCS#8 (keypair, import,
`publicKeyFromSecret()`, wrapping); the device handoff vector re-cut with
`vault.seed_hex`;
`joinery-sealed.js` frames; the three challenge pages, whose half-open of a
v2 key box posts the dek-scope key, not the X-Wing shared secret (R2); `device-link.js`;
node gates. Acceptance: the node gate reproduces the vector; the recovery key
ceremony, an approval, and a copy-key handoff each complete end to end on
localdev with a v2 key; `selfCheck()` passes in Chrome, Firefox and Safari.

**WP3. Go.** Relay sealer at Go 1.24, sealing either format by key length
(Q8), its map parser accepting 1216-byte keys, the v2 size ceiling in
`direct_protocol.go`, and a new `RELAY_VERSION`; `openRecoverySealed`'s v2
sibling for its four callers, taking the dek-scope key (R2); agent sidecar version 2, `data-root unseal` and `reseal`
for both; approval challenge v2 with each scope's info; the agent KEM key
(minted at join and on the first run of an already-joined agent, reported by
join or check-in), `mgn_agent_kem_public_key`, `copy_seal.go`;
`JobCommandBuilder` regexes and the copy export's key; `go test` vectors in
both modules. Acceptance: a throwaway encrypted-data-root machine is sealed to
a v2 recovery key, opened by the pasted key at the console, re-sealed after a
recipient rotation; an agent that joined before the upgrade reports its KEM key
at its next check-in and receives a copy export; a relay-sealed message to a
Standard mailbox and to a Fortress mailbox each open.

**WP4. Sync client.** `jd-crypto` v2 with the seed secret in place of `secret_key_pkcs8`, `jd-daemon/src/link.rs` v2 device key, `cargo test`
vectors. Acceptance: a device link on the Mac mini receives a v2-sealed vault
key and opens a sealed file.

**WP5. iOS.** `Package.swift` to iOS 26, the
`project.yml` deployment targets of `joinery-member-ios` and
`scrolldaddy-ios`, `VaultCrypto.swift`, `DeviceKeyStore.swift`, the seed secret in place of PKCS#8
(`scalar(fromPKCS8:)`, `DeviceVault.swift:310`), the Swift vector test
(decapsulate and open, R9). **WP6. Android.** Bouncy Castle, `VaultCrypto.kt` (seed secret in place of
`pkcs8Encode`),
`DeviceKeys.kt`, the JVM vector test. Acceptance for each: the Fortress phone
flow test passes against a v2 vault, including saving a draft through the mail
kit.

**WP7. The forced rotation.** A v1 vault refuses to open (above `openKey()`),
an interactive unlock goes into the rotation, seals to a v1 key refuse; the
per-scope dashboard count and the mail-blocking line; the site upgrade's relay
version refusal (Q8);
backups refuse on a v1 recovery key and Recovery Readiness says why; the
converger's v1 site-key replacement; the relay transport key replacement with
the spool drain; `data_root_recipient` with a 1216-byte key. Acceptance on
dev: after the upgrade a v1 vault refuses until rotated and then works; a
Fortress `mail` rotation commits behind the rebuilt relay; a
backup refuses until the recovery key is v2, then starts a new epoch, and
Recovery Readiness lists the old epochs as opening with the retired key; every
vault, the backup recovery key, the site key, the relay transport key and
dev's break-glass copy are v2; `SELECT count(*) FROM uev_user_encryption_vaults
WHERE length(uev_public_key) < 100` is 0; a backup made before the upgrade
restores with the old recovery key and one made after restores with the new;
mail already in the relay's spool at the upgrade arrives.

**WP8. Docs.** `docs/sealed_vault.md` (Crypto core, key rotation),
`docs/backups.md` (the disaster recipe becomes `joinery-agent data-root unseal`
and `escrow_keypair.php unseal`, both naming OpenSSL 3.5, and its description
of `config/backup_site_key` as "the base64 of the raw sodium keypair",
`:1000-1003`, becomes the seed form; recovery key setup),
`docs/installation.md`, `plugins/vault/docs/overview.md:57`,
`docs/drive_sync.md`, `docs/mobile_apps.md:774-785`, `docs/joinery_direct.md`.
Each reads as the end state (CLAUDE.md documentation rule).

## Acceptance for the whole

- No code path mints an X25519-only keypair; `grep -rn 'sodium_crypto_box_keypair\b'`
  outside tests and the v1 openers returns nothing.
- Every runtime reproduces `xwing_vector.json` byte for byte.
- Every seal the platform writes after the upgrade is `v2`, verified by the
  WP7 query and by a release-gate test that seals through each public entry
  point and inspects the frame.
- The hosted data promise spec's O7 is closed by reference to this spec.
