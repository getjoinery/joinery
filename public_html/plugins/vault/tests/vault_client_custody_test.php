<?php
/** @joinery-test
 * name: vault_client_custody
 * tier: db
 * env: dev-only
 * needs: []
 *
 * The server-side contract for a client-custody vault scope (password manager /
 * Drive). The load-bearing property under test is ZERO-KNOWLEDGE: the server
 * stores and returns the browser's opaque blobs BYTE-FOR-BYTE and never
 * inspects, transforms, or re-wraps them. The crypto round-trips themselves are
 * browser-verified (WebCrypto/Argon2 can't run in CLI); this covers the storage
 * layer, scope isolation, the unlocker floor across scopes, and the plugin's
 * entry/keyring models.
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');
require_once(PathHelper::getIncludePath('includes/VaultClientCustody.php'));

// PasskeyService pulls in the WebAuthn library, which needs the composer
// autoloader (registered globally in web requests, not in the CLI harness).
$__autoload = Globalvars::get_instance()->get_setting('composerAutoLoad');
if ($__autoload) { require_once(PathHelper::getIncludePath($__autoload . 'autoload.php')); }
require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));
require_once(PathHelper::getIncludePath('plugins/vault/data/vault_entries_class.php'));
require_once(PathHelper::getIncludePath('plugins/vault/data/vault_keyring_class.php'));

// ---------------------------------------------------------------------------
section('Scope validation + PRF context mapping');
// ---------------------------------------------------------------------------
check(VaultClientCustody::assertClientScope('passwords') === 'passwords', 'passwords is a client-custody scope');
check(VaultClientCustody::assertClientScope('drive') === 'drive', 'drive is a client-custody scope (Drive reuses this layer)');
$threw = false;
try { VaultClientCustody::assertClientScope('user'); } catch (VaultClientCustodyException $e) { $threw = true; }
check($threw, "the server-custody 'user' scope is rejected here (it has its own actions)");
$threw = false;
try { VaultClientCustody::assertClientScope('nonsense'); } catch (VaultClientCustodyException $e) { $threw = true; }
check($threw, 'an unknown scope is rejected');
check(VaultClientCustody::contextForScope('passwords') === 'vault-passwords-kek', 'passwords derives its KEK under vault-passwords-kek');
check(VaultClientCustody::contextForScope('drive') === 'vault-drive-kek', 'drive derives its KEK under vault-drive-kek (isolated context)');
check(in_array('vault-passwords-kek', PasskeyService::ALLOWED_PRF_CONTEXTS, true), 'the passwords PRF context is registered with PasskeyService');

// ---------------------------------------------------------------------------
section('Zero-knowledge storage: blobs stored verbatim, never re-wrapped');
// ---------------------------------------------------------------------------
$user = make_user('ClientVault');
$passkey = vault_fixture_passkey((int)$user->key);

// A client-custody passwords vault, created the way the setup action does.
$vault = new UserEncryptionVault(NULL);
$vault->set('uev_usr_user_id', (int)$user->key);
$vault->set('uev_scope', UserEncryptionVault::SCOPE_PASSWORDS);
$vault->set('uev_custody', UserEncryptionVault::CUSTODY_CLIENT);
$vault->set('uev_public_key', 'BROWSER_GENERATED_PUBLIC_KEY_b64');
$vault->set('uev_salt', 'BROWSER_SALT_b64');
$vault->set('uev_kdf_params', VaultClientCustody::encodeKdfParams(['alg' => 'argon2id', 'mem' => 65536, 'time' => 3, 'parallelism' => 4, 'hashLen' => 32]));
$vault->set('uev_key_generation', 1);
$vault->save();
harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', (int)$vault->key);

// The browser produces these opaque blobs; the server must store them unchanged.
$passkey_blob = 'CLIENT_WRAPPED_SECRET_passkey_' . bin2hex(random_bytes(12));
$recovery_blob = 'CLIENT_WRAPPED_SECRET_recovery_' . bin2hex(random_bytes(12));
VaultClientCustody::persistWrappings((int)$user->key, $vault, [
	['unlocker_type' => 'passkey', 'credential_id' => (string)$passkey->get('pkc_credential_id'), 'wrapped_secret_key' => $passkey_blob, 'label' => 'My Laptop'],
	['unlocker_type' => 'recovery', 'wrapped_secret_key' => $recovery_blob, 'salt' => 'BROWSER_SALT_b64'],
]);

$stored = new MultiUserEncryptionWrapping(['vault_id' => (int)$vault->key]);
$stored->load();
$by_type = [];
foreach ($stored as $w) { $by_type[$w->get('uew_unlocker_type')] = $w; }
check(isset($by_type['passkey']) && $by_type['passkey']->get('uew_wrapped_secret_key') === $passkey_blob, 'passkey wrapping is stored byte-for-byte (no server-side re-wrap)');
check(isset($by_type['recovery']) && $by_type['recovery']->get('uew_wrapped_secret_key') === $recovery_blob, 'recovery wrapping is stored byte-for-byte');
check((int)$by_type['passkey']->get('uew_pkc_credential_id') === (int)$passkey->key, 'passkey wrapping resolves the b64url credential to the internal pkc id (for the floor + revoke cleanup)');
check($by_type['recovery']->get('uew_salt') === 'BROWSER_SALT_b64', 'recovery wrapping records the browser-supplied salt');

// ---------------------------------------------------------------------------
section('Passkey resolution refuses foreign / non-PRF credentials');
// ---------------------------------------------------------------------------
$threw = false;
try { VaultClientCustody::resolveOwnedPrfPasskeyId((int)$user->key, 'not-a-real-credential'); } catch (VaultClientCustodyException $e) { $threw = true; }
check($threw, 'a credential id that is not the user\'s is refused');

$other = make_user('ClientVaultOther');
$other_pk = vault_fixture_passkey((int)$other->key);
$threw = false;
try { VaultClientCustody::resolveOwnedPrfPasskeyId((int)$user->key, (string)$other_pk->get('pkc_credential_id')); } catch (VaultClientCustodyException $e) { $threw = true; }
check($threw, "another user's passkey cannot be wrapped into your vault");

$nonprf = vault_fixture_passkey((int)$user->key, 'Old Key');
$nonprf->set('pkc_prf_capable', false);
$nonprf->save();
$threw = false;
try { VaultClientCustody::resolveOwnedPrfPasskeyId((int)$user->key, (string)$nonprf->get('pkc_credential_id')); } catch (VaultClientCustodyException $e) { $threw = true; }
check($threw, 'a non-PRF passkey cannot back a wrapping (it can never derive the KEK)');

// ---------------------------------------------------------------------------
section('statusPayload: the keyring view, no secret material beyond blobs');
// ---------------------------------------------------------------------------
$fresh = make_user('ClientVaultFresh');
$empty = VaultClientCustody::statusPayload((int)$fresh->key, 'passwords');
check($empty['set_up'] === false, 'an un-set-up scope reports set_up=false');
check($empty['prf_context'] === 'vault-passwords-kek', 'status still names the scope PRF context when not set up');

$payload = VaultClientCustody::statusPayload((int)$user->key, 'passwords');
check($payload['set_up'] === true, 'a set-up scope reports set_up=true');
check($payload['public_key'] === 'BROWSER_GENERATED_PUBLIC_KEY_b64', 'status returns the public key');
check(is_array($payload['kdf_params']) && $payload['kdf_params']['mem'] === 65536, 'kdf_params round-trips as a decoded object');
check($payload['passkey_wrapping_count'] === 1, 'status counts the passkey wrapping');
check($payload['unused_recovery_code_count'] === 1, 'status counts unused recovery wrappings');
$blobs = array_map(function ($w) { return $w['wrapped_secret_key']; }, $payload['wrappings']);
check(in_array($passkey_blob, $blobs, true) && in_array($recovery_blob, $blobs, true), 'status returns the opaque blobs verbatim (the browser needs them to unwrap)');

// ---------------------------------------------------------------------------
section('The unlocker floor guards a client vault across scopes');
// ---------------------------------------------------------------------------
// Current state: 1 live passkey + 1 unused recovery -> revoking the passkey
// would strand the passwords vault (needs >=1 other passkey OR >=3 codes).
$threw = false;
try { VaultUnlock::assertRevocationSafe((int)$user->key, (int)$passkey->key); } catch (PasskeyRevocationVetoException $e) { $threw = true; }
check($threw, 'revoking the last passkey of the client PASSWORDS vault is vetoed (the cross-scope fix)');

// Add two more recovery wrappings -> 3 unused codes satisfies the floor.
foreach (['r2', 'r3'] as $tag) {
	VaultClientCustody::persistWrappings((int)$user->key, $vault, [
		['unlocker_type' => 'recovery', 'wrapped_secret_key' => 'CLIENT_' . $tag . '_' . bin2hex(random_bytes(8)), 'salt' => 'BROWSER_SALT_b64'],
	]);
}
$threw = false;
try { VaultUnlock::assertRevocationSafe((int)$user->key, (int)$passkey->key); } catch (PasskeyRevocationVetoException $e) { $threw = true; }
check(!$threw, 'with 3 unused recovery keys, revoking the passkey is allowed');

// ---------------------------------------------------------------------------
section('Plugin models: encrypted entries + the store keyring');
// ---------------------------------------------------------------------------
$entry = new VaultEntry(NULL);
$entry->set('vle_usr_user_id', (int)$user->key);
$entry->set('vle_ciphertext', 'OPAQUE_ENTRY_BLOB_' . bin2hex(random_bytes(16)));
$entry->save();
harness_register_row('vle_vault_entries', 'vle_vault_entry_id', (int)$entry->key);

$live = new MultiVaultEntry(['user_id' => (int)$user->key]);
check($live->count_all() === 1, 'a saved entry lists for its owner');
$other_list = new MultiVaultEntry(['user_id' => (int)$other->key]);
check($other_list->count_all() === 0, 'entries are owner-scoped (no cross-user read)');

$entry->soft_delete();
$after = new MultiVaultEntry(['user_id' => (int)$user->key]);
check($after->count_all() === 0, 'a trashed entry drops out of the live list');
$trash = new MultiVaultEntry(['user_id' => (int)$user->key, 'deleted' => true]);
check($trash->count_all() === 1, 'a trashed entry is still retrievable from trash (restore)');

check(VaultKeyring::loadForUser((int)$user->key) === null, 'no store keyring until the DEK is sealed');
$kr = new VaultKeyring(NULL);
$kr->set('vlk_usr_user_id', (int)$user->key);
$kr->set('vlk_wrapped_dek', 'SEALED_DEK_BLOB_' . bin2hex(random_bytes(16)));
$kr->save();
harness_register_row('vlk_vault_keyring', 'vlk_vault_keyring_id', (int)$kr->key);
$loaded = VaultKeyring::loadForUser((int)$user->key);
check($loaded !== null && (int)$loaded->key === (int)$kr->key, 'the store keyring loads for its owner once sealed');

harness_finish();
?>
