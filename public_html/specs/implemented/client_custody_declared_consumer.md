# Client custody as a declared consumer

**Status: IMPLEMENTED 2026-09-24 — WP0–WP9 built and walked on dev. As built, where it departs from the text below:**

- **WP7 page gate.** The device-link page stays on `drive_active`: every `drive_*` device-link action is gated on it (`tests/unit/api_action_feature_gate_test.php`), so a page open with Drive off could not approve. Other vaults ride beside Drive's checkbox.
- **WP8 Drive hook.** Drive's grants re-seal through a new `drive_key_grants_reseal` (own grants only, only while a rotation is pending), not `drive_key_grants_sync`, which is owner-only and replaces a file's whole grantee set.
- **WP8 hooks load by registration.** `VaultUnlock::clientReseal($scope, $classes, $scripts)` takes hook scripts; the rotation page (`needs_vault_rotation()`) loads them, since Drive's and the password manager's page scripts are not on the security page.
- **WP8 pending rotations are finished, never discarded.** There is no `vault_client_rotate_abandon`: keys a hook moved carry no generation, so the server cannot tell "nothing moved" (review B18). `begin` refuses while one is pending; `ensureUnlocked({pending})` opens the new key to finish. While pending, new material seals to the pending key (`UserEncryptionVault::sealingPublicKey()`, review B19).
- **WP8 progress.** The batch reports in the security card, not through `ceremony-batch.js`, which drives a server action and cannot do per-row browser crypto.
- **WP8 `vault_row_reseal`** also takes `rows: [...]` so a page is one request.
- **VaultConsumers had no unknown-key warning** (Facts list); none was added.
- Bugs found and fixed along the way: B5–B24 (memory `project_running_todos.md`; B18–B24 from the second review).

Origin: the developer-surface review of `specs/DEFERRED_client_custody_mail.md`
(its § Developer surface 2026-09-24 points here). Nothing in this spec depends
on Fortress mail being built; every item stands on its own and pays off in
shipped code today.

## For the executor — read this first

This section is the working brief. § Design gives the reasons; § Work packages
is the checklist. Do the packages in order: each is testable on its own and
the next depends on it. Every design decision is already made; there are no
open questions. When something in the tree contradicts a fact below, the tree
wins: stop, note the difference in your hand-back, and proceed on the tree.

**House rules that bind this work** (CLAUDE.md and project memory):

- **Never commit and never `git add`.** The owner runs git; the index is
  shared with other sessions.
- **Never edit `CLAUDE.md`, anything under `specs/implemented/`, or Apache
  config.** Never chmod (the host converger holds 644/755).
- **Schema changes go through `$field_specifications` only.** Four columns are
  added here, all in WP7 (`dlk_device_links`, `sde_sync_devices`,
  `uev_user_encryption_vaults`). Ask the owner before running
  `php utils/update_database.php`; naming the command in the ask is enough.
  No migration for a column.
- **Settings are declarative:** core in `settings.json` at the `public_html/`
  root, plugin in its `plugin.json`. `PluginManager::validateDeclaredSettings`
  (`includes/PluginManager.php:1708`) throws on a plugin setting whose name is
  also in core, so a setting that moves from plugin to core moves in one edit
  to both files (WP5).
- **Docs describe the current state only.** No "now", "previously",
  "replaces", "instead of".
- **Bump the `@version` header** of every file you touch, with a one-line
  changelog note. `plugins/vault/plugin.json` carries `version`; bump it when
  the plugin changes.
- **After every PHP edit:** `php -l <file>`, then
  `php /var/www/html/joinerytest/maintenance_scripts/dev_tools/validate_php_file.php <file>`,
  but only on class and function files (`includes/`, `data/`, `logic/`). The
  validator includes the file; views get `php -l` only.
- **Tests use the shared harness** (`tests/lib/harness.php`) and the
  `@joinery-test` header; copy the header of `tests/vault/seal_on_save_test.php`.
  Vault fixtures are in `tests/lib/vault_fixtures.php`
  (`vault_fixture_vault`, `vault_fixture_client_vault($user_id, $public_key,
  $scope)`, `vault_fixture_key`, `vault_fixture_open_window`,
  `vault_apcu_usable`, `vault_ensure_session`). `php tests/run.php --changed`
  is the working loop, `php tests/run.php db --changed` before handing back.
  Never run the runner as root.
- **Vanilla JS and CSS.** No framework. Browser-facing forms through
  FormWriter; a button that acts is a POST.
- **New server actions are API actions** (`logic/<name>_logic.php` with a
  `_logic_descriptor()`); page JS calls them through `joineryApi.post`. Never
  `/ajax/`.
- **Browser behavior is verified in the Playwright browser** on
  `https://dev.getjoinery.com` (login: memory `reference_credentials`, user
  4500 is vault-capable). Take a screenshot at each acceptance walk and say
  what it shows.
- **Secrets never appear in output.** No key bytes, no recovery codes, no
  wrapped blobs echoed into the transcript or a log.
- **The Rust sync client (`/var/www/html/joinerytest/sync/jd-daemon`) is not
  edited here.** WP7 is designed so its reading of `sealed_vault_key` keeps
  working unchanged.

**Stop points** (hand back to the owner; do not work around):

1. After WP0, with the browser confirmation of B1 (the security page's
   passkey enrolment reaches the confirm prompt).
2. Before `php utils/update_database.php` in WP7.
3. When everything is green, with the list of files touched, for commit.

**Facts you will need, verified 2026-09-24 against the tree:**

- **Sealing on the server** is in `includes/SystemBase.php`:
  `sealColumns($row_id, $vault, array $values, $reuse_dek)` (line ~875) seals
  each value with `$crypto->sealField($plaintext, $dek, static::sealAd($row_id,
  $col))` (line ~915) and, when it minted the DEK, appends
  `sealWrappingAssignments($crypto, $vault, $dek)` (line ~988), which writes
  `sealedKeyColumn() = $crypto->sealItemDek($dek, $vault->uev_public_key)`, the
  generation and the owner. `$vault` is a `UserEncryptionVault` row.
  `recordSealedKey()` (line ~954) is the blob-only sibling. `planSealOnSave()`
  (line ~1068) decides what `save()` seals and resolves the owner through
  `sealOwnerForWrite()`; `applySealOnSave()` (line ~1182) runs it.
  `resealRows()` (line ~1228) is the server rotation loop. `sealAd()` (line
  ~725) is `{prefix}:{id}:{field}`; some models override it with a legacy
  literal, and the browser must call the model's own rule, so the API
  representation carries `sealed_ad_prefix` (WP2) rather than the browser
  guessing.
- **Reading on the server:** `SystemBase::get()` routes a `$sealed_fields`
  column through `decryptSealedField()` → `decryptSealedFieldStatic($field,
  $ciphertext, array $row)` (line ~812), which returns a value untouched on an
  unsealed row, throws `RuntimeException` on a value without the `v1.aead.`
  prefix on a sealed row (line ~826), resolves the owner, fetches
  `VaultUnlock::secretKey($owner_id)` (`includes/VaultUnlock.php:315`, returns
  `?VaultKey`), and opens with `VaultCrypto::openItemDek` + `openField`.
- **API export:** `export_for_api()` (line ~1592) and `export_for_api_locked()`
  (line ~1627, sets `content_locked: true`, nulls fields the lock keeps
  closed). `CREDENTIAL_FIELD_PATTERN` (line 69, `/_(password|secret|key|token|hash)$/i`)
  makes any column ending in `_key` unreadable through the API, which is why
  the DEK travels as `sealed_dek`. Derived keys an `export_as_array()` override
  injects reach the API only if allow-listed (see the comment at line ~72).
- **Server crypto:** `includes/VaultCrypto.php` — `sealItemDek($dek,
  $public_key)` (line 60) calls `SealedBox::sealDek`; `openItemDek($sealed,
  VaultKey $key)` (line 99) strips the `v1.seal.` frame with
  `SealedBox::unframeSeal` and calls `$key->unseal([...])`; `sealField` (line
  156) and `openField` (line 204) are AEAD under the DEK with an AD, and
  `openField` calls `SealedEgressGuard::markHot($ad)`. `includes/SealedBox.php`
  holds the primitives (`sealDek` 75, `openDek` 90, `unframeSeal` 99,
  `openBinary` 135, `aeadEncrypt` 156, `aeadDecrypt` 170).
- **The key seam:** `includes/VaultKey.php` is an interface with `id()`,
  `publicKey()`, `unseal(array $sealed): array` (crypto_box_seal only). The
  only implementation is `includes/PoolVaultKey.php` (the unseal daemon of
  `specs/unseal_daemon.md` is unbuilt beyond this seam); it holds the base64url
  X25519 secret privately and `unseal()` calls `SealedBox::openBinary`.
  `tests/vault/sealed_read_paths_test.php` pins which files may call the
  asymmetric primitives (`$allowed` line ~66, `$secret_allowed` line ~79) and
  fails on a new caller; a new primitive joins those lists.
- **Browser crypto:** `assets/js/vault-crypto.js` — `sealToPublicKey(bytes,
  recipientPubB64)` (line 210) returns `base64(ephPub[32] ‖ IV[12] ‖ ct)`
  where the AES-256-GCM key is `HKDF-SHA256(shared, salt = empty, info =
  utf8('sealed-vault:dek') ‖ ephPub ‖ recipientPub)` (`ecdhAesKey` 197,
  `sealKdfInfo` 206); `openFromSecretKey` (221) is the inverse; `encrypt(str,
  dekKey)` / `decrypt(blob, dekKey)` (250/256) are `base64(IV ‖ ct)` with no
  AD today. `newDek()` returns `{dekBytes, dekKey}`; `importDek(bytes)` makes a
  non-extractable key. `isSupported()` probes X25519.
- **Browser keyring:** `assets/js/vault-keyring.js` — `status(scope)`,
  `derivePasskeyKek(scope)`, `setup(scope, opts)` → `{session, recoveryCodes,
  publicKey}`, `unlockWithPasskey/Passphrase/Recovery`, and `makeSession()`
  returns a closure `{scope, publicKey, locked(), openSealed(blob),
  sealTo(bytes, pub?), sealSecretKeyTo(pub), wrapUnder(kek, type, cred),
  lock()}`. It holds no sessions; the consumer keeps the closure.
- **Server keyring actions:** `logic/vault_client_{setup,status,prf_options,
  add_wrapping,remove_wrapping,replace_recovery,consume_recovery}_logic.php`,
  all `requires_browser_session`. `vault_client_setup` refuses when
  `passkeys_enabled` is off (line 17-20) regardless of unlocker.
  `includes/VaultClientCustody.php` — `assertClientScope`, `loadVault($user_id,
  $scope)`, `statusPayload($user_id, $scope)` (returns `set_up, public_key,
  salt, kdf_params, key_generation, passkey_wrapping_count,
  unused_recovery_code_count, has_passphrase, wrappings[]`).
- **Scopes:** `includes/VaultScopes.php` — `isClientCustody($scope)`,
  `labelFor($scope)`, `clientScopes()`, `prfContext($scope)`. Core scopes are
  in `vault_scopes.json` (`user` server, `drive` client); a plugin's under
  `vaultScopes` in its `plugin.json` (`plugins/vault/plugin.json` declares
  `passwords`). `UserEncryptionVault::loadForUser(int $user_id, string $scope
  = 'user')` (`data/user_encryption_vaults_class.php:73`).
- **Consumer obligations:** `includes/VaultConsumers.php` reads
  `vaultConsumer` from `plugin.json` and `vault_consumers.json` (core:
  `drive_sealed`, `direct_spool`, `api_idempotency`), knows `reseals` and
  `caches` (constants line 75-76, parsed line ~430, unknown keys warned at
  ~404). `VaultUnlock::onReseal(callable)` (line 633) and
  `modelReseal(array $classes)` (line 656) are the server rotation registry.
- **Lock chip and window:** `includes/PublicPageBase.php:795` emits the
  `joinery-vault` meta and `vault-lock.js` only when
  `UserEncryptionVault::loadForUser($user_id)` (scope `user`) exists.
  `assets/js/vault-lock.js` — `JoineryVaultLock.unlock()`, `.lock()`,
  `.collectUnlocker(purpose)` (line ~100, checks `window.JoineryModal`),
  events `joinery:vault-unlocked` / `joinery:vault-locked`, the chip renders
  into `[data-vault-lock-slot]` or floats. `assets/js/vault-presence.js` beats
  `vault_heartbeat` for the server window; it is not involved in client scopes.
- **Modal:** `JoineryModal` is a top-level `const` in `assets/js/base.js:124`
  (not on `window`), API `{confirm, confirmTyped, alert, prompt, open,
  alertAsync, confirmAsync, promptAsync}` (line 280); `open(contentEl,
  {buttons})` returns a handle with `.dialog`; one `<dialog>`, cannot nest.
- **Batch runner:** `assets/js/ceremony-batch.js` — `window.JoineryCeremonyBatch`
  drives a `[data-ceremony-batch]` card: repeated POSTs until `done`, with a
  status dot and templated text (`fill`, `run(card)`, `init(root)`).
- **Recovery dry run:** `assets/js/recovery-readiness.js` —
  `window.recoveryReadiness.openChallenge(challengeB64, pastedCode, publicKeyB64,
  infoPrefix)` proves a recovery code in the browser without spending it.
- **Consumers to convert:** `plugins/vault/assets/js/vault-manager.js` (1.0;
  sections Boot, ceremony `startCeremony`/`doSetup`/`showRecovery`/
  `checkRecoveryProof`/`downloadRecovery`, store DEK `initStoreDek`/
  `openStoreDek`/`loadStoreDek`, unlock `startUnlock`/`unlockPasskey`/
  `unlockPassphrase`/`unlockRecovery`/`postUnlock`, autolock
  `initAutolockControl`/`resetIdle`/`lock`, `pagehide` at line ~882) with its
  markup in `plugins/vault/views/profile/index.php` (sections
  `#jy-vault-loading`, `#jy-vault-unsupported`, `#jy-vault-ceremony`,
  `#jy-vault-unlock`, `#jy-vault-manager`; scripts at 215-216);
  `assets/js/drive.js` (`driveSession`, `fkCache` line 32, `ensureUnlocked` 77,
  `openVaultDialog`/`closeVaultDialog`/`vaultUnlocked`/`wireVaultDialog`/
  `doSetup`/`doUnlock` 90-180, `fileKeyFor` 183, `openSealed` 495, decrypted
  names in `it._name` 310/368/771, object URLs 335/527) with
  `<dialog id="drvVaultDialog">` at `views/drive.php:131-166` and scripts at
  367-369; `assets/js/device-link.js` (`dlkVaultDialog` line 90, unlock
  108-112, `sealVaultKeyFor` 122-130, `enable_vault` checkbox 47/157-158) with
  `views/profile/devices_link.php` (scripts 88-89).
- **Script paste sites** (six): `views/drive.php:367`, `views/share.php:62`,
  `views/profile/security.php:1194`, `views/profile/devices_link.php:88`,
  `adm/admin_recovery_readiness.php:230`, `plugins/vault/views/profile/index.php:215`.
- **Device handoff:** `logic/devices_link_logic.php` refuses without
  `drive_active` (line 26-28) and offers the checkbox when a `drive` vault
  exists; `logic/drive_device_link_approve_logic.php` takes `code`,
  `enable_vault`, `sealed_vault_key` (max 4096) and stores
  `dlk_sealed_vault_key`; `includes/ApiAuthEndpoint.php:304-307` copies it into
  the claim payload as `sealed_vault_key` then `scrub_secrets()`;
  `data/device_links_class.php` (`dlk_sealed_vault_key` text, scrubbed at
  ~181); `data/sync_devices_class.php` (`sde_device_pubkey` text, line 51);
  `logic/drive_vault_status_logic.php` refuses any scope but `drive`; its only
  callers are `tests/functional/drive/sync_contract_test.php:214-227` and the
  name list in `tests/unit/api_action_feature_gate_test.php:173`. The Rust
  client reads `sealed_vault_key` as `Option<String>`
  (`sync/jd-daemon/src/link.rs:91,139`).
- **Settings:** `settings.json` has `vault_unlock_idle_minutes` (default 30,
  group `vault_unlock`, line ~617) for the server window.
  `plugins/vault/plugin.json` declares `vault_autolock_minutes` (15) and
  `vault_clipboard_clear_seconds` (30); `plugins/vault/logic/vault_home_logic.php:16`
  reads the first into the page config; the JS keeps a per-browser override in
  `localStorage` under `jy_vault_autolock_{scope}` with choices 5/15/30/60.
- **Running to-do bugs** (memory `project_running_todos.md`, 2026-09-24
  entries B1-B4): B1 `window.JoineryModal` never assigned; B2 Drive offers
  passphrase-only setup the server refuses (`drive.js:101-102`); B3 the
  password manager's `lock()` cancels the clipboard timer without clearing the
  clipboard and has no `pageshow`; B4 `plugins/vault/docs/overview.md:90`
  claims a full-screen setup and `:109` a "fewer than three codes" prompt,
  neither built.

---

## Intent

A plugin that wants server-custody protection for its content declares three
things and writes one line (docs/plugin_developer_guide.md § Path 1). A plugin
that wants end-to-end protection today gets a keypair and a set of opaque-blob
actions and is told to "drive the crypto in the browser". The two consumers that
did (the password manager, Drive) plus the device-link page each built the same
five pieces themselves: a setup and unlock modal, a session holder with its own
idle lock, a per-consumer key-storage shape, a server surface for opaque blobs,
and the script tags. A fourth consumer would build them a fourth time.

After this spec, a plugin author writes **no crypto, no ceremony and no session
code**. The integration is a scope declaration, the sealed-field declaration
server custody already uses plus one hook that names the scope, a save action
shaped for the two-step browser write, and two browser calls:

```json
"vaultScopes": { "acme_notes": { "custody": "client", "label": "Acme notes vault" } }
```

```php
class AcmeNote extends SystemBase {
    public static $sealed_fields = array('acn_title', 'acn_body');
    // the four sealing columns, exactly as for server custody
    protected static function sealScopeForWrite(array $row): string {
        return $row['acn_end_to_end'] ? 'acme_notes' : 'user';
    }
}
```

```js
const note = await JoinerySealed.open(rowFromApi, refetch);   // plaintext fields, or an unlock prompt
await JoinerySealed.save('acme/note_save', values);           // create empty, seal in the browser, post ciphertext
```

The reader no longer knows which rung a row is on. The ceremony, the session,
the idle lock, the device handoff and the script tags are core.

## What exists, and what is duplicated

| Piece | Password manager | Drive | Device link | Core today |
|---|---|---|---|---|
| Setup / unlock / recovery-code modal | `vault-manager.js` (a centered card, its own markup and ids) | `drive.js` + `<dialog id="drvVaultDialog">` | `device-link.js` own dialog | `VaultKeyring` has the ceremony logic, no UI |
| Session holding + idle lock | own timer, pagehide hook, wipe list; `vault_autolock_minutes` | `driveSession` + `fkCache`, **no idle lock** | own | none: `makeSession` hands the closure to the consumer |
| Key storage shape | `vlk_vault_keyring` (one store DEK) + `vle_ciphertext` (whole record) | `FileKeyGrant` per reader | n/a | server custody: four columns + per-row DEK |
| Server surface | six blob-CRUD actions + page logic | drive_* actions | drive_device_link_* | `vault_client_*` keyring actions |
| Script tags | pasted | pasted | pasted | lock chip emitted by `PublicPageBase`, server-vault users only |
| Server seals to this key | never | never | never | `sealItemDek` emits libsodium; browser cannot open it |
| Rotation | none | none (`uev_key_generation` stuck at 1) | — | server scope only, via `modelReseal([classes])` |

`VaultScopes`, `VaultClientCustody`, `vault-crypto.js` and `vault-keyring.js` are
sound and stay. This spec adds on top of them and removes the copies.

## Design

### R1. One sealed-row shape for both custodies, custody chosen per row

Custody is a property of the row, not the model. Mail's message table holds a
Private mailbox's rows beside what would be a Fortress mailbox's; `File` already
mixes private and fortress rows per row. So the scope is a **write-side hook**:

```php
protected static function sealScopeForWrite(array $row): string { return 'user'; }
```

The default is server custody and nothing changes for any existing model. A
model that overrides it returns a registered client scope for the rows that
want it; an unregistered scope, or `user` from a plugin scope's point of view
is fine, but a plugin-declared server scope cannot exist (`VaultScopes` refuses
it), so the only check is `VaultScopes::isRegistered()` and it runs where
`assertSealingDeclared()` runs, so a typo fails on first use.

The four sealing columns are the same. The **sealed key is self-describing**,
which is what makes the read side know the scope with no new column:

| Blob | Server custody | Client custody |
|---|---|---|
| sealed DEK | `v1.seal.` + libsodium sealed box | `v1.edgeseal.{scope}.` + base64(ephPub ‖ IV ‖ ct), the `vault-crypto.js` ECIES to the scope's public key |
| field | `v1.aead.` + server AEAD | `v1.edge.` + base64(IV ‖ ct), AES-256-GCM under the DEK, AD = the model's `sealAd($row_id, $field)` |

The prefixes live at the **row layer**: the PHP row sealer
(`sealWrappingAssignments`, `sealColumns`) and `joinery-sealed.js`. The
primitives in `vault-crypto.js` and `VaultCrypto.php`/`SealedBox.php` keep
their raw output, because the Rust sync client, `FileKeyGrant` blobs,
`vlk_wrapped_dek` and every unlocker wrapping read that raw form today.
`vault-crypto.js` `encrypt()`/`decrypt()` gain an optional AD argument; the
no-AD form stays for the password manager.

**Reading on the server.** `get()` on a sealed field of a client-scope row
throws `VaultSealedForBrowserException` (new, in `includes/VaultUnlock.php`
beside `VaultLockedException`): not "wait for the window" but "no server code
reads this". `decryptSealedFieldStatic` (which the AI surface uses) throws the
same, so AI rule 3 of the protection ladder holds structurally. The one
sanctioned emitter of ciphertext is the API export: `export_for_api()` gains
the sibling of the locked branch and emits the sealed fields as stored,
`sealed_scope` parsed from the key prefix, `sealed_dek` (the key column's
value), and `sealed_ad_prefix` (the model's `sealAd(0, '')` with the trailing
`0:` removed, so the browser reproduces a legacy literal too). Those three
derived keys are allow-listed for the API export. The API export strips any
column ending in `_key`, so the DEK cannot travel under its column name.

**Writing from the server** (ingest, a webhook, anything on behalf of a
Fortress owner): unchanged from the caller's side. `save()` and `sealColumns()`
seal plaintext exactly as for server custody; only the sealer differs (R2). A
server writer never holds a client secret, so it cannot reuse an existing
row's DEK: a server-side update of a sealed field on an already-sealed
client-scope row is **refused** (`VaultSealedForBrowserException` from
`planSealOnSave`) unless the update supplies every sealed field the row has,
in which case it mints a new DEK and re-seals them all. A partial update would
orphan the omitted fields under a DEK nobody records. In practice server
writes to client-scope rows are creates.

**Writing from the browser.** The browser cannot know the row id, and so the
AD, before the row exists, so a browser-written row is two steps, mirroring
the server's own two-phase insert: create the row with its sealed columns
empty (an empty sealed column already reads as "nothing yet"), then post
ciphertext. Ciphertext enters only through
`SystemBase::acceptBrowserSealed($row_id, $sealed_dek, array $fields)`, which
checks the `v1.edgeseal.{scope}.` prefix, that the scope equals
`sealScopeForWrite($row)`, that every field is in `$sealed_fields` and carries
`v1.edge.`, and writes the four columns plus the fields in one statement (the
same shape as `sealColumns`'s UPDATE). `save()` **refuses** a value carrying
the `v1.edge.` prefix in a sealed field, because sealing it again would
double-seal; plaintext through `save()` is the server path and is sealed, so
the guarantee is "ciphertext cannot be mis-stored", not "plaintext cannot be
posted". The plugin's `*_save` logic keeps its own authorization and delegates
the write to `acceptBrowserSealed`.

**Rows written before the scope existed** are plaintext, as for server custody
today. Backfilling them once the owner sets the scope up is each consumer's
raise batch (mail's `backfill_seal_logic` shape), which R2 makes possible for a
client scope; it is not built here.

### R2. One PHP sealer and one opener per format

`VaultCrypto` gains `sealItemDekToBrowserKey(string $dek, string $public_key_b64)`
and `sealFieldForBrowser(string $plaintext, string $dek, string $ad)`, the
exact mirror of `vault-crypto.js` (`SealedBox` gains the primitives
`sealEdge`/`openEdge` and `aeadEncryptGcm`/`aeadDecryptGcm`; `VaultCrypto`
frames them). Primitives are all present: `sodium_crypto_scalarmult` for
X25519 (an ephemeral scalar from `random_bytes(32)`, its public half from
`sodium_crypto_scalarmult_base`), `hash_hkdf('sha256', $shared, 32, $info,
'')` with `$info = 'sealed-vault:dek' . $ephPub . $recipientPub`, and
`openssl_encrypt(..., 'aes-256-gcm', ..., $tag, $ad)` with a 12-byte IV and a
16-byte tag appended to the ciphertext (WebCrypto's layout). Roughly forty
lines, pinned by a shared test vector (WP1).

`sealWrappingAssignments()` and the field sealer in `sealColumns()` call the
browser-format pair when `sealScopeForWrite` names a client scope, and the
`$vault` they receive is that scope's `UserEncryptionVault` (the owner is
resolved as today; the scope selects which of the owner's vault rows).

The server also needs to **open** the edge format when a DEK is sealed to its
own key: the mail lowering batch has the browser re-seal each row's DEK to
the server-custody public key, in the format the browser can produce. The
`VaultKey` interface gains `unsealEdge(array $sealed): array` (the same
X25519 secret; `PoolVaultKey` implements it through `SealedBox::openEdge`,
and the daemon spec inherits a fifth operation when it is built).
`openItemDek()` opens whichever prefix it finds; `openField()` likewise opens
`v1.edge.` under a DEK the server holds. Readers open whichever prefix they
find; writers emit the format their custody dictates.

The relay's Go mirror (`relay-sealer/seal.go`) stays a mail build item.

### R3. The browser opens a row whichever rung it is on

`assets/js/joinery-sealed.js` (core, `window.JoinerySealed`) exports:

- `open(row, refetch)` — `row` is a model's API representation.
  `content_sealed` false: resolves the row. `content_locked` true (server
  custody, window closed; a single GET answers 423 and the caller passes the
  same fetch as `refetch`): runs `JoineryVaultLock.unlock()` and resolves
  `refetch()`. `sealed_scope` set: obtains the scope session (R4, R5), opens
  `sealed_dek` with `session.openSealed`, imports the DEK, decrypts every
  `v1.edge.` field with AD `sealed_ad_prefix + id + ':' + field`, resolves a
  copy of the row with plaintext in place. Opened rows are cached per scope
  session and dropped on lock.
- `seal(scope, rowId, adPrefix, values)` — mints a DEK, seals it to the scope
  public key from `vault_client_status` (`v1.edgeseal.{scope}.`), encrypts
  each field with its AD (`v1.edge.`), zeroes the DEK bytes, returns
  `{ sealed_dek, fields }`.
- `save(action, values, opts)` — the two-step browser write as one call: post
  `values` minus the sealed fields to `action`, read `id` and
  `sealed_ad_prefix` from the reply, `seal`, post `{id, sealed_dek, fields}` to
  the same `action`. `opts.sealedFields` names which keys of `values` are
  sealed; `opts.scope` names the scope.

Drive's `openSealed` (server) and `fileKeyFor` (client) become callers of this
module for key acquisition (`ensureUnlocked` → `JoinerySealed.session(scope)`);
Drive's file bytes keep `drive-crypto.js`.

### R4. The ceremony UI lives in core

`VaultKeyring.ensureUnlocked(scope, opts)` resolves an unlocked session. It
reads `vault_client_status`, and:

- not set up → runs setup (passkey, or passphrase only where the instance
  allows it: the server refuses setup with `passkeys_enabled` off, so the
  modal never offers what the server will refuse and says why), the
  permanent-loss acknowledgment, then the recovery codes with the
  type-one-back proof or the download;
- set up, no session → runs unlock (passkey, passphrase, recovery code; only
  the methods the keyring has; recovery shown behind a "use a recovery code"
  link as today);
- session live → resolves immediately.

`JoineryModal` is a singleton that cannot nest, so setup, recovery display and
unlock are steps rendered inside **one** `JoineryModal.open()` content
element. The label comes from the scope registry (`vault_client_status` gains
`label`); `opts.reason` reads in the prompt ("to open your Acme notes"). The
recovery proof accepts the last code typed back, normalized as
`vault-manager.js` `normalizeCode` does, or the download. The three views
delete their dialogs and ids; the password manager's centered first-run card
becomes the same modal opened on load.

### R5. Core holds the scope session and locks it

This is new behavior: the JS keyring holds no sessions today. After: one
session per scope per tab, held inside `joinery-sealed.js` (`JoinerySealed.session(scope)`
calls `VaultKeyring.ensureUnlocked` on a miss), never handed out as raw bytes.

- **Idle lock.** Core setting `vault_client_autolock_minutes` (default 15,
  group `vault_unlock`, label "Lock browser-held vaults after (minutes)",
  helptext naming the password vault and Fortress folders) in `settings.json`;
  `vault_autolock_minutes` is removed from `plugins/vault/plugin.json` **in the
  same edit**. It is a different setting from `vault_unlock_idle_minutes` (the
  server window) and the labels say which is which. `PublicPageBase` emits the
  value on the `joinery-vault` meta as `data-client-idle-minutes`. The
  per-browser override moves to core (`localStorage` `jy_vault_client_autolock`,
  choices 5/15/30/60) and the password manager's select drives it. The plugin
  keeps `vault_clipboard_clear_seconds`. Activity (`keydown`, `pointerdown`,
  `pointermove`) resets the timer; `pagehide` locks every scope; `pageshow`
  with `event.persisted` locks every scope (a bfcache restore must never show
  plaintext with a live key).
- **Lock contract.** `JoinerySealed.onLock(scope, fn)` registers a callback;
  `document` receives `joinery:vault-scope-locked` with `detail.scope`;
  `JoinerySealed.lock(scope)` and `lockAll()` are the explicit entries. Core
  drops the session and its opened-row cache. **The consumer owns wiping its
  own DOM and caches**: decrypted names written into elements, raw key bytes
  it kept (Drive's `fkCache`), inputs and detail panes, object URLs. The doc
  says so in those words.
- **Chip.** `PublicPageBase:795` widens its gate to "any `uev_` row for the
  user" (`MultiUserEncryptionVault(['user_id' => ...])` count > 0), emitting
  `data-server-vault="0|1"` so the chip knows whether a server window exists.
  The chip shows one state, open when the server window or any client scope
  session is open, and the popover lists what is open ("Mail & messages
  vault", "Password vault", "Drive vault") with a "Lock now" per line. A user
  with no server vault sees the chip only while a client scope is open.

### R6. The device handoff is per scope

Today: `drive_device_link_approve` stores one `dlk_sealed_vault_key`, the auth
endpoint hands it to the device as a string, `drive_vault_status` refuses any
scope but `drive`, the devices-link page is gated on `drive_active`, and the
approval checkbox says "encrypted folders".

After: the approval accepts `sealed_vault_keys: { scope: blob }` as a **new**
input beside the existing `sealed_vault_key`, which stays a bare string for
Drive because the shipped Rust client reads it as one. Storage: a new
`dlk_sealed_vault_keys` text column (JSON), scrubbed with the rest; the claim
payload gains `sealed_vault_keys` and keeps `sealed_vault_key`. No rows
migrate; link rows are scrubbed on claim. The approval page lists every client
scope the user has set up (label from `VaultScopes::labelFor`) with its own
checkbox, Drive's checkbox keeping its current name and field; each scope is
its own PRF context, so one passkey tap per chosen scope, through R4, then
`session.sealSecretKeyTo()`. The page's gate becomes "`drive_active`, or any
client scope set up for the user". `SyncDevice` gains
`sde_vault_scopes` (varchar(255), comma-separated scope names it received), so
the devices page and revocation read truthfully.

The lean probe is renamed and widened to `vault_client_probe(scope)` for any
registered client scope, same three facts, same session-key reachability
(`auth: capability read`), `requires_setting` dropped (it is not Drive's).
Nothing outside the sync contract test calls `drive_vault_status`, so there is
no alias; the test and the feature-gate name list move with it.

### R7. Scripts by declaration

There is no per-page script mechanism today (`styles` is plugin-wide, in
`plugin.json`), so this adds one, small: a page calls
`$page->needs_vault_client()` before output and `PublicPageBase` emits
`joinery-api.js` (if not already), `passkeys.js`, `vault-crypto.js`,
`vault-keyring.js` and `joinery-sealed.js` with cache-busting
(`asset_mtime`), next to where it emits the lock chip. Admin pages
(`AdminPage`) get the same method. The six paste sites go.

### R8. Rotation for a client scope

A client scope's keypair can be rotated only by the browser, and honestly it
costs: the browser does not hold the recovery codes, so rotation **mints new
codes** (shown once, as at setup), **re-asks the passphrase** if there is one,
and needs **one passkey tap per enrolled passkey** to re-wrap under each. Then
every sealed DEK in the scope is re-sealed to the new public key and
`uev_key_generation` advances.

What gets re-sealed is a registry, not a scan, mirroring the server side's
`modelReseal([classes])`:

- `$sealed_fields` models with rows in the scope: the plugin registers them
  in its bootstrap with `VaultUnlock::clientReseal('acme_notes',
  [AcmeNote::class])`; `vault_client_reseal_rows(scope, after_id, limit)`
  pages `{model, id, sealed_dek}` for the caller's rows whose key carries
  `v1.edgeseal.{scope}.`; the browser batch opens each `sealed_dek` with the
  old session and re-seals it with the new key, writing back through one
  generic core action `vault_row_reseal(model, id, sealed_dek)`, which
  verifies the caller owns the row, that the new blob's scope matches, and
  rewrites the key column and generation and nothing else.
- Consumers whose keys live outside those models register a browser hook:
  `JoinerySealed.onReseal(scope, async (oldSession, newPublicKeyB64, report) => ...)`.
  Drive re-wraps its `FileKeyGrant` rows (through `drive_key_grants_sync`), the
  password manager its `vlk_wrapped_dek` (a new create-or-replace path guarded
  by the rotation step; `keyring_save` stays create-only). A `vaultConsumer`
  obligation `client_reseals: ["scope", ...]` makes a missing registration
  refuse rotation, as `reseals` does for server custody.
- Linked devices hold the old secret key. Rotation clears
  `sde_vault_scopes` of that scope on every device and the device must re-link
  (the 2026-09-23 decision in the mail spec lists rotation as a re-link event).

The rotation ceremony is `vault_client_rotate_begin` (new vault row material:
public key, salt, wrappings for the new key, produced by the browser as at
setup, stored as generation N+1 **pending**), the batch, then
`vault_client_rotate_commit` (retires generation N's wrappings, marks N+1
current). A pending rotation that never commits is discarded by
`vault_client_rotate_abandon` or by the next `begin`. The batch UI runs on
`ceremony-batch.js`. Where the ceremony is offered (the security page's scope
card) is WP8's last item.

## What does not change

- **Server-custody consumers.** No declaration, column or call changes. The
  window, `VaultLockedException`, `onReseal` and the 423 contract are untouched.
- **Drive file rows.** Files have many readers and keep `FileKeyGrant`. Drive
  converts its ceremony, session, idle lock, device handoff and scripts.
- **The password manager's storage.** One store DEK and one blob per entry
  stay. It converts ceremony, session, idle lock and scripts; its six blob
  actions stay.
- **`vault_client_*` actions.** Unchanged in contract; `status` gains `label`.
- **The primitives.** `vault-crypto.js`, `VaultCrypto.php` and `SealedBox.php`
  output stays unprefixed; framing is the row layer's.
- **The Rust sync client.** Reads the same fields it reads today.

## Work packages

Each WP is independently reviewable and leaves the tree working. Acceptance
lines are what you run or walk; write the test named in each before the code
it tests where one is named.

### WP0. Prerequisite fixes (running to-do B1, B3, B4)

- `assets/js/base.js`: after the `JoineryModal` IIFE, `window.JoineryModal = JoineryModal;`.
  Bump the file version. Walk `/profile/security` in the browser: "Add a
  passkey" reaches the "Confirm it's you" prompt instead of the alert
  "Confirming is unavailable". Screenshot.
- `plugins/vault/assets/js/vault-manager.js` `lock()`: call `clearClipboard`
  logic (write `''` when the clipboard still holds the last copied value) and
  add a `pageshow` handler that locks when `event.persisted`. (WP5 later
  replaces both with core behavior; do the small fix now so the branch is
  never worse than today.)
- `plugins/vault/docs/overview.md`: line ~90 describes a centered first-run
  card, not full-screen; line ~109 drops the "fewer than three" prompt (it is
  not built; `regenerate_recommended` exists in the status payload and nothing
  reads it). Current state only.
- **Stop point 1.**

### WP1. Formats, the PHP sealer and opener (R2)

- `includes/SealedBox.php`: `sealEdge(string $bytes, string $recipient_pub_b64): string`
  (raw output, base64 standard alphabet as the browser's `b64encode`),
  `openEdge(string $blob_b64, string $secret_b64url, string $recipient_pub_b64): string`,
  `aeadEncryptGcm(string $plaintext, string $key, string $ad): string`
  (base64(IV ‖ ct ‖ tag)), `aeadDecryptGcm`. Public key input is the base64
  the browser stores in `uev_public_key`; check its decoded length is 32.
- `includes/VaultKey.php`: add `unsealEdge(array $sealed): array` with the
  same contract as `unseal`. `includes/PoolVaultKey.php`: implement it via
  `SealedBox::openEdge` with its own public half.
- `includes/VaultCrypto.php`: `sealItemDekToBrowserKey($dek, $public_key_b64,
  $scope)` returns `'v1.edgeseal.' . $scope . '.' . SealedBox::sealEdge(...)`;
  `sealFieldForBrowser($plaintext, $dek, $ad)` returns `'v1.edge.' . aeadEncryptGcm(...)`;
  `openItemDek`/`openItemDeks` detect `v1.edgeseal.` and route to
  `$key->unsealEdge` (the memo keys on the blob so nothing else changes);
  `openField` detects `v1.edge.` and routes to `aeadDecryptGcm`, still calling
  `SealedEgressGuard::markHot`. Add `parseEdgeScope(string $sealed): ?string`.
- `assets/js/vault-crypto.js`: `encrypt(plaintext, dekKey, adBytes?)` and
  `decrypt(blob, dekKey, adBytes?)` pass `additionalData` when given. Export
  a `selfCheck()` that seals and opens a fixed vector and returns true.
- `tests/vault/sealed_read_paths_test.php`: add the new primitives to the
  pinned lists so the caller set stays closed.
- **Test `tests/vault/edge_format_test.php`** (tier `safe`): PHP seals a DEK
  to a browser-generated keypair fixture and the browser format decodes
  byte-for-byte to the layout `ephPub[32] ‖ IV[12] ‖ ct`; PHP opens what PHP
  sealed; a field round-trips with AD and fails with the wrong AD;
  `openItemDek` opens both prefixes. Put the same vector (a fixed recipient
  secret, a fixed ephemeral scalar, a fixed IV, expected ciphertext hex) in
  `tests/vault/fixtures/edge_vector.json` and make `vault-crypto.js`
  `selfCheck()` open it, so both sides prove the same bytes.
- Acceptance: `php tests/run.php --changed` green; in the browser console on
  `/drive`, `VaultCrypto.selfCheck()` resolves `true`.

### WP2. Per-row scope on the model (R1)

- `includes/VaultUnlock.php`: `class VaultSealedForBrowserException extends RuntimeException`
  beside `VaultLockedException`.
- `includes/SystemBase.php`:
  - `protected static function sealScopeForWrite(array $row): string { return 'user'; }`
    and a validating wrapper `resolveSealScope($row)` that refuses an
    unregistered scope.
  - `planSealOnSave()`: resolve the scope; for a client scope load the owner's
    vault row for that scope (`UserEncryptionVault::loadForUser($owner, $scope)`,
    none → the row stays plaintext exactly as a member with no vault does
    today); on an already-sealed client-scope row, refuse a partial update
    (throw `VaultSealedForBrowserException` with the message "This row is
    sealed for the browser; the server can only rewrite all of its sealed
    fields at once"); refuse any incoming value with the `v1.edge.` prefix
    ("already sealed; use acceptBrowserSealed").
  - `sealWrappingAssignments($crypto, $vault, $dek)`: branch on
    `$vault->get('uev_custody') === 'client'` → `sealItemDekToBrowserKey($dek,
    $vault->uev_public_key, $vault->uev_scope)`. The field loop in
    `sealColumns()` branches the same way (`sealFieldForBrowser`). Both take
    the vault they are handed, so the scope decision is made once in the plan.
  - `decryptSealedFieldStatic()`: before the `v1.aead.` check, if the row's
    sealed key carries `v1.edgeseal.` throw `VaultSealedForBrowserException`.
    The existing plaintext-on-sealed-row `RuntimeException` accepts `v1.edge.`
    values as sealed.
  - `export_for_api()`: catch `VaultSealedForBrowserException` per field the
    way the locked export does; emit the stored ciphertext for sealed fields,
    plus `sealed_scope`, `sealed_dek`, `sealed_ad_prefix`; allow-list the three
    derived keys for the API export.
  - `acceptBrowserSealed(int $row_id, string $sealed_dek, array $fields): void`
    (public static), as specified in R1; one prepared UPDATE; refuse with a
    named reason for each check.
- Ownership at `acceptBrowserSealed` is the caller's job (the plugin's save
  logic); say so in its docblock.
- **Test `tests/vault/sealed_scope_model_test.php`** (tier `db`, dev-only, as
  `seal_on_save_test.php` is): a fixture model class declared in the test
  with a per-row scope; a `drive`-scope client vault fixture
  (`vault_fixture_client_vault` with a keypair the test generated through
  `SealedBox`); server save seals to the edge format and `get()` throws the
  browser exception; `export_for_api` carries the three derived keys and the
  ciphertext; `acceptBrowserSealed` stores verbatim and the row reads as
  sealed; a `v1.edge.` value through `save()` is refused; a partial server
  update is refused; a full server update re-seals under a new DEK; a server
  scope row on the same model behaves exactly as `seal_on_save_test.php`
  proves. Register every row with `harness_register_row()`.
- `tests/vault/sealed_read_paths_test.php`: assert no file outside the API
  export catches `VaultSealedForBrowserException` to return content.
- Acceptance: `php tests/run.php db --changed` green.

### WP3. `joinery-sealed.js` (R3)

- New `assets/js/joinery-sealed.js` (`window.JoinerySealed`): `open`, `seal`,
  `save`, `session(scope)` (delegates to `VaultKeyring.ensureUnlocked` until
  WP4 lands, so build WP3 with a temporary shim that reuses Drive's existing
  dialog, then delete the shim in WP6), the opened-row cache, `onLock`,
  `lock`, `lockAll`, and the `joinery:vault-scope-locked` dispatch.
- Acceptance: a throwaway page under `views/dev/` is **not** the way; use the
  Drive page: a Private file's HEAD 423 path and a Fortress file's key path
  both go through `JoinerySealed` (convert `openSealed` and `fileKeyFor` now,
  it is two call sites). Browser walk: open a Private file with the window
  closed (unlock prompt, then it opens); open a Fortress file (scope prompt,
  then it opens). Screenshots.

### WP4. Ceremony in core (R4)

- `assets/js/vault-keyring.js`: `ensureUnlocked(scope, opts)`, the three
  step renderers inside one `JoineryModal.open()` content element, the
  passkeys-disabled rule (read `passkeys_enabled` from
  `vault_client_status`, which gains it and `label`), the recovery proof and
  download, friendly PRF errors (`vault-manager.js` `friendly()`).
- `logic/vault_client_status_logic.php`: add `label` (`VaultScopes::labelFor`)
  and `passkeys_enabled` to the payload; the descriptor documents them.
- Acceptance: from `/drive` with the drive scope deleted on a test user (use
  a fresh user; never delete vault rows on user 4500), first open runs
  setup, shows codes, requires the proof; reload, open again runs unlock by
  passkey; with passkeys disabled in settings the setup modal explains it
  cannot proceed instead of offering passphrase-only. Screenshots of setup,
  recovery, unlock.

### WP5. Session, lock and scripts (R5, R7)

- `settings.json`: add `vault_client_autolock_minutes`; `plugins/vault/plugin.json`:
  remove `vault_autolock_minutes` in the same edit; `plugins/vault/logic/vault_home_logic.php`
  stops reading it. Bump the plugin version.
- `includes/PublicPageBase.php` (~795): widen the gate; emit
  `data-client-idle-minutes` and `data-server-vault`; add
  `needs_vault_client()` and the script emission; `includes/AdminPage.php` the
  same method (share the emitter).
- `assets/js/joinery-sealed.js`: idle timer, activity listeners, `pagehide`,
  `pageshow` + `persisted`, the `localStorage` override.
- `assets/js/vault-lock.js`: the popover lists open scopes (server window from
  the meta, client scopes from `JoinerySealed.openScopes()`), "Lock now" per
  line; the chip is open when anything is; a user without a server vault sees
  the chip only while a client scope is open (`data-server-vault="0"`: hide
  when nothing is open).
- Replace the six paste sites with `needs_vault_client()`.
- Acceptance: on `/drive` with a Fortress file open, wait past a 1-minute
  override → the file list re-seals and the chip closes; the chip popover
  names "Drive vault"; `/profile/vault` and `/profile/security` still load
  their scripts (no console errors). Screenshots.

### WP6. Convert the three consumers

- `plugins/vault/assets/js/vault-manager.js` + `views/profile/index.php`:
  delete the ceremony and unlock sections and their handlers; boot calls
  `JoinerySealed.session('passwords')` then the store-DEK and entries code as
  today; the autolock select drives the core override; register `onLock`
  that wipes the six inputs, the list, the detail pane and the clipboard timer
  (and the clipboard, from WP0). The `pagehide`/`pageshow` handlers from WP0
  go (core does it).
- `assets/js/drive.js` + `views/drive.php`: delete `drvVaultDialog` and the
  dialog code; `ensureUnlocked` becomes `JoinerySealed.session(SCOPE)`;
  register `onLock` that clears `fkCache` (zeroing `fkBytes`), `it._name` on
  every item, revokes object URLs it created, and re-renders the list.
- `assets/js/device-link.js` + `views/profile/devices_link.php`: delete
  `dlkVaultDialog`; unlock through `JoinerySealed.session(scope)`.
- Acceptance: browser walk of all three pages: password manager first-run,
  unlock, add an entry, lock, unlock; Drive Fortress folder open and idle
  lock; device link with the Drive checkbox. Screenshots. `grep -c drvVault
  views/drive.php` is 0; `grep -c 'jy-vault-ceremony' plugins/vault/views/profile/index.php` is 0.

### WP7. Device handoff per scope (R6)

- `data/device_links_class.php`: `dlk_sealed_vault_keys` (text, nullable),
  scrubbed in `scrub_secrets()`. `data/sync_devices_class.php`:
  `sde_vault_scopes` (varchar(255), nullable). Declare WP8's two columns in
  the same step (`data/user_encryption_vaults_class.php`:
  `uev_pending_public_key` text nullable, `uev_pending_key_generation` int4
  nullable) so the database is written once. **Stop point 2** before
  `php utils/update_database.php`.
- `logic/drive_device_link_approve_logic.php`: accept `sealed_vault_keys`
  (object, scope → blob, each ≤ 4096, each scope registered client and set up
  for the user); keep `sealed_vault_key`/`enable_vault` exactly as they are;
  record `sde_vault_scopes`.
- `includes/ApiAuthEndpoint.php` (~304): add `sealed_vault_keys` to the claim
  payload when present.
- `logic/devices_link_logic.php`: gate "drive_active or any client scope set
  up"; return the list of set-up client scopes with labels for the page.
- `views/profile/devices_link.php` + `assets/js/device-link.js`: one checkbox
  per scope (Drive's unchanged), one unlock and one `sealSecretKeyTo` per
  chosen scope.
- Rename `logic/drive_vault_status_logic.php` → `logic/vault_client_probe_logic.php`
  (`vault_client_probe`, any registered client scope, `requires_setting`
  dropped, descriptor updated); move the sync contract test's section and the
  entry in `tests/unit/api_action_feature_gate_test.php:173`.
- Drive devices page: show the scopes a device holds.
- Acceptance: contract test green; browser walk of the link page with a user
  who has Drive and passwords set up shows two checkboxes and asks for two
  taps; with only Drive set up the page reads as today. Screenshot.

### WP8. Rotation (R8)

- `includes/VaultUnlock.php`: `clientReseal(string $scope, array $classes)`
  registry + getter; `includes/VaultConsumers.php`: `client_reseals`
  obligation (array of scopes), warned/refused like `reseals`.
- `logic/vault_client_reseal_rows_logic.php`, `logic/vault_row_reseal_logic.php`,
  `logic/vault_client_rotate_{begin,commit,abandon}_logic.php`, all
  `requires_browser_session`. The pending generation lives in the two
  `uev_pending_*` columns declared in WP7; wrappings for the pending
  generation carry `uew_key_generation = N+1` and are retired or promoted at
  commit.
- `assets/js/joinery-sealed.js`: `onReseal(scope, fn)`, `resealScope(scope)`
  (begin → per-passkey taps and passphrase → batch over `vault_client_reseal_rows`
  and the registered hooks with `ceremony-batch.js` progress → commit).
- Drive hook (re-wrap `FileKeyGrant` rows through `drive_key_grants_sync`) and
  the password manager hook (new `keyring_replace` action accepting the new
  blob only while a rotation is pending).
- Security page: a "Rotate this vault's key" control on each client-scope
  card, stating the cost (new recovery codes, devices must re-link).
- **Test `tests/vault/client_reseal_batch_test.php`** (tier `db`): a fixture
  scope with rows across two test models plus one `FileKeyGrant`; the batch
  actions page correctly; `vault_row_reseal` refuses a row the caller does
  not own and a blob for another scope; commit retires the old wrappings;
  `sde_vault_scopes` loses the scope on a linked device fixture.
- Acceptance: tests green; browser walk of a rotation on a test user with a
  handful of Fortress files: files open after, the probe reports the new
  generation. Screenshot.

### WP9. Docs

- `docs/sealed_vault.md` § Client-custody scopes: the row shape, the two
  formats and prefixes, `sealScopeForWrite`, the browser exception, the API
  representation, `acceptBrowserSealed`, the two-step write, sessions and the
  lock contract, rotation and its cost, the device handoff.
- `docs/plugin_developer_guide.md` § Path 2: rewritten to the declared shape
  (the Intent block above is the template); the "reference implementation"
  paragraph names the password manager for storage and Drive for many-reader
  keys.
- `docs/drive_encryption.md` § Device custody and § Client modules;
  `docs/drive_sync.md` if it names `drive_vault_status`;
  `plugins/vault/docs/overview.md` (setting moved, ceremony shared).
- Current state only. **Stop point 3.**

## Tests

New: `tests/vault/edge_format_test.php` (WP1), `tests/vault/sealed_scope_model_test.php`
(WP2), `tests/vault/client_reseal_batch_test.php` (WP8). Changed:
`sealed_read_paths_test.php` (WP1, WP2), `tests/functional/drive/sync_contract_test.php`
and `tests/unit/api_action_feature_gate_test.php` (WP7),
`tests/vault/vault_registry_test.php` if it enumerates obligations (WP8).
Browser behavior is verified by walk on dev with screenshots at each WP's
acceptance line; the JS modules carry `selfCheck()`.

## Resolved during review

- Custody is per row (`sealScopeForWrite`), never per model.
- The server seals plaintext; the browser posts ciphertext through one
  accepting method; `save()` refuses already-sealed values.
- Server reads of a client-scope row throw a distinct exception; the API
  export is the one emitter of ciphertext, under names the credential floor
  does not strip.
- Prefixes are the row layer's; primitives stay raw.
- Content saves stay per plugin; key rewrites are one generic core action.
- The chip shows one state and lists what is open in its popover.
- Devices re-link after a rotation; the new key is not pushed to them.
