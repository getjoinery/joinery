<?php
/** @joinery-test
 * name: vault_wrappings_floor
 * tier: db
 * env: dev-only
 * needs: []
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/vault_fixtures.php');

if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}

$box = new SealedBox();
$user = make_user('VaultWrap');
$passkey = vault_fixture_passkey((int)$user->key);

// A bare vault row (no ceremony) so this file controls every wrapping.
$kp = $box->generateKeypair();
$salt = $box->generateSalt();
$vault = new UserEncryptionVault(NULL);
$vault->set('uev_usr_user_id', $user->key);
$vault->set('uev_scope', UserEncryptionVault::SCOPE_USER);
$vault->set('uev_custody', UserEncryptionVault::CUSTODY_SERVER);
$vault->set('uev_public_key', $kp['public']);
$vault->set('uev_salt', $salt);
$vault->set('uev_key_generation', 3); // deliberately not 1
$vault->save();
harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', (int)$vault->key);
$vault_id = (int)$vault->key;

section('createWrapped');
$kek = random_bytes(32);
$w = UserEncryptionWrapping::createWrapped($vault_id, UserEncryptionWrapping::TYPE_PASSKEY, $kp['secret'], $kek, (int)$passkey->key, 'label');
check((int)$w->get('uew_key_generation') === 3, 'generation default resolves to the vault CURRENT generation, not 1');
check($w->get('uew_salt') === null || $w->get('uew_salt') === '', 'passkey wrapping stores no salt');
$ad = UserEncryptionWrapping::adFor($vault_id, (int)$w->key);
check($box->unwrapKey($w->get('uew_wrapped_secret_key'), $kek, $ad) === $kp['secret'], 'wrapping unwraps under its own AD');
$threw = false;
try { $box->unwrapKey($w->get('uew_wrapped_secret_key'), $kek, UserEncryptionWrapping::adFor($vault_id, (int)$w->key + 1)); } catch (Exception $e) { $threw = true; }
check($threw, 'wrapping refuses another row\'s AD (splice defense)');

$w_salted = UserEncryptionWrapping::createWrapped($vault_id, UserEncryptionWrapping::TYPE_RECOVERY, $kp['secret'], $kek, null, null, 2, $salt);
check($w_salted->get('uew_salt') === $salt, 'recovery wrapping records the salt it was created under');
check((int)$w_salted->get('uew_key_generation') === 2, 'explicit generation wins over the default');

section('liveGenerations');
$gens = UserEncryptionWrapping::liveGenerations($vault_id);
sort($gens);
check($gens === [2, 3], 'reports each generation with a live wrapping', json_encode($gens));
$w_salted->soft_delete();
check(UserEncryptionWrapping::liveGenerations($vault_id) === [3], 'soft-deleted wrappings do not count');

section('The unlocker floor');
// State: 1 live passkey wrapping (gen 3), 0 recovery codes -> deleting the
// passkey must be refused.
$threw = false;
try { VaultUnlock::assertWrappingDeleteSafe($vault_id, (int)$passkey->key); } catch (RuntimeException $e) { $threw = true; }
check($threw, 'refuses to strip the last passkey with no recovery codes');

// Add 2 unused codes: still refused (floor needs 3).
$c1 = UserEncryptionWrapping::createWrapped($vault_id, UserEncryptionWrapping::TYPE_RECOVERY, $kp['secret'], $kek, null, null, 3, $salt);
$c2 = UserEncryptionWrapping::createWrapped($vault_id, UserEncryptionWrapping::TYPE_RECOVERY, $kp['secret'], $kek, null, null, 3, $salt);
$threw = false;
try { VaultUnlock::assertWrappingDeleteSafe($vault_id, (int)$passkey->key); } catch (RuntimeException $e) { $threw = true; }
check($threw, 'two unused codes do not satisfy the floor');

// A third code satisfies it.
$c3 = UserEncryptionWrapping::createWrapped($vault_id, UserEncryptionWrapping::TYPE_RECOVERY, $kp['secret'], $kek, null, null, 3, $salt);
$threw = false;
try { VaultUnlock::assertWrappingDeleteSafe($vault_id, (int)$passkey->key); } catch (RuntimeException $e) { $threw = true; }
check(!$threw, 'three unused codes allow revoking the last passkey');

// A used code stops counting.
$c3->set('uew_is_used', true);
$c3->save();
$threw = false;
try { VaultUnlock::assertWrappingDeleteSafe($vault_id, (int)$passkey->key); } catch (RuntimeException $e) { $threw = true; }
check($threw, 'a consumed code no longer counts toward the floor');

// A second live passkey satisfies the floor without codes.
$passkey2 = vault_fixture_passkey((int)$user->key, 'Second Key');
$w2 = UserEncryptionWrapping::createWrapped($vault_id, UserEncryptionWrapping::TYPE_PASSKEY, $kp['secret'], $kek, (int)$passkey2->key, 'Second Key');
$threw = false;
try { VaultUnlock::assertWrappingDeleteSafe($vault_id, (int)$passkey->key); } catch (RuntimeException $e) { $threw = true; }
check(!$threw, 'another live passkey wrapping satisfies the floor');

// A wrapping whose credential row is soft-deleted must NOT count.
$passkey2->soft_delete();
$threw = false;
try { VaultUnlock::assertWrappingDeleteSafe($vault_id, (int)$passkey->key); } catch (RuntimeException $e) { $threw = true; }
check($threw, 'a wrapping for a dead credential does not satisfy the floor');

section('Post-revoke cleanup');
VaultUnlock::cleanupRevokedCredential((int)$user->key, (int)$passkey2->key);
$after = new MultiUserEncryptionWrapping(['vault_id' => $vault_id, 'credential_id' => (int)$passkey2->key]);
check($after->count_all() === 0, 'every wrapping of the revoked credential is soft-deleted');
$survivor = new MultiUserEncryptionWrapping(['vault_id' => $vault_id, 'credential_id' => (int)$passkey->key]);
check($survivor->count_all() === 1, 'other credentials\' wrappings survive the cleanup');

harness_finish();
?>
