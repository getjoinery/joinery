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

section('reserve / storeWrapped: the wrapping comes from VaultUnlock::openKey()');
$kek = random_bytes(32);
$w = UserEncryptionWrapping::reserve($vault_id, UserEncryptionWrapping::TYPE_PASSKEY, (int)$passkey->key, 'label');
check((int)$w->get('uew_key_generation') === 3, 'generation default resolves to the vault CURRENT generation, not 1');
check($w->get('uew_wrapped_secret_key') === '', 'a reserved row holds no wrapping yet');
check($w->ad() === UserEncryptionWrapping::adFor($vault_id, (int)$w->key), 'ad() is the row-binding AD of the saved row');
// The wrapping is produced by the key holder: open the test keypair's secret
// under a throwaway unlocker with this row's entry in the wrap list.
$seed = vault_fixture_key($kp['secret']);
$throwaway_kek = random_bytes(32);
$throwaway_ad = 'vault-test:seed';
$opened = VaultUnlock::openKey((int)$user->key,
	['wrapped' => $box->wrapKey($kp['secret'], $throwaway_kek, $throwaway_ad), 'kek' => $throwaway_kek, 'ad' => $throwaway_ad],
	[$w->wrapEntry($kek)]);
check($opened['key']->id() === $seed->id(), 'openKey() under a valid unlocker yields the same key');
check(count($opened['wrappings']) === 1, 'one wrap entry, one wrapping back');
$w->storeWrapped($opened['wrappings'][0]);
$w = new UserEncryptionWrapping((int)$w->key, TRUE);
check($w->get('uew_salt') === null || $w->get('uew_salt') === '', 'passkey wrapping stores no salt');
$ad = UserEncryptionWrapping::adFor($vault_id, (int)$w->key);
check($box->unwrapKey($w->get('uew_wrapped_secret_key'), $kek, $ad) === $kp['secret'], 'wrapping unwraps under its own AD');
$threw = false;
try { $box->unwrapKey($w->get('uew_wrapped_secret_key'), $kek, UserEncryptionWrapping::adFor($vault_id, (int)$w->key + 1)); } catch (Exception $e) { $threw = true; }
check($threw, 'wrapping refuses another row\'s AD (splice defense)');

$threw = false;
try { $w->storeWrapped(''); } catch (Exception $e) { $threw = true; }
check($threw, 'an empty wrapping is refused');
$threw = false;
try {
	VaultUnlock::openKey((int)$user->key,
		['wrapped' => $w->get('uew_wrapped_secret_key'), 'kek' => random_bytes(32), 'ad' => $w->ad()]);
} catch (Exception $e) { $threw = true; }
check($threw, 'openKey() under the wrong KEK throws and yields nothing');
$reopened = VaultUnlock::openKey((int)$user->key, $w->unlocker($kek));
check($reopened['key']->id() === $seed->id(), 'the stored wrapping opens under its own KEK through unlocker()');

$w_salted = UserEncryptionWrapping::reserve($vault_id, UserEncryptionWrapping::TYPE_RECOVERY, null, null, 2, $salt);
$w_salted->storeWrapped(VaultUnlock::openKey((int)$user->key, $w->unlocker($kek), [$w_salted->wrapEntry($kek)])['wrappings'][0]);
check($w_salted->get('uew_salt') === $salt, 'recovery wrapping records the salt it was created under');
check((int)$w_salted->get('uew_key_generation') === 2, 'explicit generation wins over the default');

section('A reserved row that never got its wrapping is not enrolled');
// A request that dies between reserve() and storeWrapped() leaves a live row
// with an empty wrapping. It must count as nothing: not enrolled, not an
// unlock candidate, not a bar to re-enrolling the same credential.
$dead_passkey = vault_fixture_passkey((int)$user->key, 'Dead Row');
$dead = UserEncryptionWrapping::reserve($vault_id, UserEncryptionWrapping::TYPE_PASSKEY, (int)$dead_passkey->key, 'dead');
check($dead->isReserved(), 'the reserved row reports itself reserved');
UserEncryptionWrapping::forgetReservationsForTests();   // the request that reserved it is gone
$by_cred = new MultiUserEncryptionWrapping(['vault_id' => $vault_id, 'unlocker_type' => UserEncryptionWrapping::TYPE_PASSKEY, 'credential_id' => (int)$dead_passkey->key]);
check($by_cred->count_all() === 0, 'by default the collection does not see it (the add-passkey already-enrolled check stays false)');
$with = new MultiUserEncryptionWrapping(['vault_id' => $vault_id, 'unlocker_type' => UserEncryptionWrapping::TYPE_PASSKEY, 'credential_id' => (int)$dead_passkey->key, 'include_reserved' => true]);
check($with->count_all() === 1, 'include_reserved shows it');
check(!in_array((int)$dead_passkey->key, VaultUnlock::offerableCredentialIds((int)$user->key), true),
	'the unlock ceremony never offers the dead credential');
$gens = UserEncryptionWrapping::liveGenerations($vault_id);
sort($gens);
check($gens === [2, 3], 'liveGenerations ignores the reserved row', json_encode($gens));

// A fresh reservation for the same credential retires the dead one and its own
// wrapping lands on the new row.
$again = UserEncryptionWrapping::reserve($vault_id, UserEncryptionWrapping::TYPE_PASSKEY, (int)$dead_passkey->key, 'alive');
$dead_reloaded = new UserEncryptionWrapping((int)$dead->key, TRUE);
check($dead_reloaded->get('uew_delete_time') !== null && $dead_reloaded->get('uew_delete_time') !== '', 'reserve() retired the stale reserved row for the same unlocker');
$threw = false;
try { $dead->storeWrapped('v1.aead.x.y'); } catch (Exception $e) { $threw = true; }
check($threw, 'storing onto the retired reservation is refused');
$again->storeWrapped(VaultUnlock::openKey((int)$user->key, $w->unlocker($kek), [$again->wrapEntry($kek)])['wrappings'][0]);
$enrolled = (new MultiUserEncryptionWrapping(['vault_id' => $vault_id, 'unlocker_type' => UserEncryptionWrapping::TYPE_PASSKEY, 'credential_id' => (int)$dead_passkey->key]))->count_all();
check($enrolled === 1, 'the credential is enrolled once, on the row that received its wrapping', 'count=' . $enrolled . ' dead_delete=' . var_export($dead_reloaded->get('uew_delete_time'), true));
check(in_array((int)$dead_passkey->key, VaultUnlock::offerableCredentialIds((int)$user->key), true), 'and is now offered at unlock');
$again->soft_delete();

// A batch reserved in one request is never swept by its own later reserves.
$batch = [];
for ($i = 0; $i < 3; $i++) {
	$batch[] = UserEncryptionWrapping::reserve($vault_id, UserEncryptionWrapping::TYPE_RECOVERY, null, null, 3, $salt);
}
$live_batch = 0;
foreach ($batch as $row) {
	$r = new UserEncryptionWrapping((int)$row->key, TRUE);
	if ($r->get('uew_delete_time') === null || $r->get('uew_delete_time') === '') { $live_batch++; }
}
check($live_batch === 3, 'three recovery rows reserved in one request all survive to be wrapped');
foreach ($batch as $row) { $row->soft_delete(); }

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

// A recovery wrapping on generation 3, produced the platform way: reserve,
// open under the passkey wrapping's KEK with the new row in the wrap list, store.
$add_code = function () use ($vault_id, $user, $w, $kek, $salt): UserEncryptionWrapping {
	$row = UserEncryptionWrapping::reserve($vault_id, UserEncryptionWrapping::TYPE_RECOVERY, null, null, 3, $salt);
	$row->storeWrapped(VaultUnlock::openKey((int)$user->key, $w->unlocker($kek), [$row->wrapEntry($kek)])['wrappings'][0]);
	return $row;
};

// Add 2 unused codes: still refused (floor needs 3).
$c1 = $add_code();
$c2 = $add_code();
$threw = false;
try { VaultUnlock::assertWrappingDeleteSafe($vault_id, (int)$passkey->key); } catch (RuntimeException $e) { $threw = true; }
check($threw, 'two unused codes do not satisfy the floor');

// A third code satisfies it.
$c3 = $add_code();
$threw = false;
try { VaultUnlock::assertWrappingDeleteSafe($vault_id, (int)$passkey->key); } catch (RuntimeException $e) { $threw = true; }
check(!$threw, 'three unused codes allow revoking the last passkey');

// Removing one of exactly-3 codes when no passkey backs the floor: the doomed
// row itself must not count (it would pass with 3, then leave 2).
$threw = false;
try { VaultUnlock::assertWrappingDeleteSafe($vault_id, (int)$passkey->key, (int)$c3->key); } catch (RuntimeException $e) { $threw = true; }
check($threw, 'the wrapping being removed is excluded from its own floor count');

// A used code stops counting.
$c3->set('uew_is_used', true);
$c3->save();
$threw = false;
try { VaultUnlock::assertWrappingDeleteSafe($vault_id, (int)$passkey->key); } catch (RuntimeException $e) { $threw = true; }
check($threw, 'a consumed code no longer counts toward the floor');

// A second live passkey satisfies the floor without codes.
$passkey2 = vault_fixture_passkey((int)$user->key, 'Second Key');
$w2 = UserEncryptionWrapping::reserve($vault_id, UserEncryptionWrapping::TYPE_PASSKEY, (int)$passkey2->key, 'Second Key');
$w2->storeWrapped(VaultUnlock::openKey((int)$user->key, $w->unlocker($kek), [$w2->wrapEntry($kek)])['wrappings'][0]);
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
