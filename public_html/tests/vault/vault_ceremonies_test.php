<?php
/** @joinery-test
 * name: vault_ceremonies
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/vault_fixtures.php');

if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}

// Session must start before the harness emits any output (CLI sessions
// cannot start once headers/output are sent) — capture window availability
// now for the kill-switch section at the bottom.
$apcu = vault_apcu_usable() && vault_ensure_session();

$box = new SealedBox();
$ceremonies = new VaultCeremonies();

section('Setup happy path');
$fx = vault_fixture_vault('CerA', 'a sufficiently long passphrase', 7);
$vault = $fx['vault'];
check((int)$vault->get('uev_key_generation') === 1, 'a fresh vault is generation 1');
$wrappings = vault_live_wrappings((int)$vault->key);
$counts = ['passkey' => 0, 'recovery' => 0, 'passphrase' => 0];
$salts_ok = true;
foreach ($wrappings as $w) {
	$counts[$w->get('uew_unlocker_type')]++;
	$type = $w->get('uew_unlocker_type');
	$s = (string)$w->get('uew_salt');
	if ($type === UserEncryptionWrapping::TYPE_PASSKEY && $s !== '') { $salts_ok = false; }
	if ($type !== UserEncryptionWrapping::TYPE_PASSKEY && $s !== (string)$vault->get('uev_salt')) { $salts_ok = false; }
}
check($counts === ['passkey' => 1, 'recovery' => 7, 'passphrase' => 1], 'one passkey + 7 codes + passphrase wrappings', json_encode($counts));
check($salts_ok, 'recovery/passphrase wrappings record the vault salt; the passkey records none');
check(count($fx['recovery_codes']) === 7, 'the codes were returned for display');

section('Key file reconstructibility');
// The backup payload alone + one known recovery code must reconstruct the
// secret - that is its entire reason to exist.
$kf = $fx['key_file'];
check(count($kf['wrappings']) === 9, 'key file carries every wrapping row');
$code = $fx['recovery_codes'][0];
$recovered = null;
foreach ($kf['wrappings'] as $row) {
	if ($row['unlocker_type'] !== 'recovery') { continue; }
	try {
		$kek = $box->kekFromRecoveryCode($code, $row['salt']);
		$recovered = $box->unwrapKey($row['wrapped_secret'], $kek, UserEncryptionWrapping::adFor($kf['vault_id'], $row['id']));
		break;
	} catch (Exception $e) { continue; }
}
check($recovered !== null, 'a recovery code + the key file reconstruct the secret key');
check($recovered !== null && SealedBox::b64url(sodium_crypto_box_publickey_from_secretkey(SealedBox::b64url_decode($recovered))) === $kf['public_key'],
	'the reconstructed secret matches the advertised public key');

section('Setup refusals');
$threw = '';
try { $ceremonies->setup($fx['user'], (int)$fx['passkey']->key, 'x', random_bytes(32), '', 10, false); } catch (VaultCeremonyException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'already set up') !== false, 'a second setup is refused');

$user_b = make_user('VaultCerB');
$pk_b = vault_fixture_passkey((int)$user_b->key);
$threw = '';
try { $ceremonies->setup($user_b, (int)$pk_b->key, 'x', random_bytes(32), 'short', 10, false); } catch (VaultCeremonyException $e) { $threw = $e->getMessage(); }
check(strpos($threw, '12 characters') !== false, 'a short passphrase is refused at setup');

section('Setup atomicity');
// A 16-byte KEK passes no validation until the FIRST wrapping is sealed -
// by then the vault row is saved inside the transaction. The failure must
// roll everything back: no vault, no wrappings, and setup can run again.
$threw = false;
try { $ceremonies->setup($user_b, (int)$pk_b->key, 'x', random_bytes(16), '', 10, false); } catch (VaultCeremonyException $e) { $threw = true; }
check($threw, 'a mid-ceremony failure surfaces as an error');
$leftover = new MultiUserEncryptionVault(['user_id' => $user_b->key, 'scope' => UserEncryptionVault::SCOPE_USER]);
check($leftover->count_all() === 0, 'no vault row survives the rollback - never a vault with zero unlockers');
$retry = $ceremonies->setup($user_b, (int)$pk_b->key, 'x', random_bytes(32), '', 5, false);
harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', (int)$retry['vault']->key);
check((int)$retry['vault']->key > 0, 'setup runs cleanly after the rolled-back attempt');
check(count($retry['recovery_codes']) === 5, 'code_count floor of 5 honored');

section('Code count clamps');
$user_c = make_user('VaultCerC');
$pk_c = vault_fixture_passkey((int)$user_c->key);
$clamped = $ceremonies->setup($user_c, (int)$pk_c->key, 'x', random_bytes(32), '', 50, false);
harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', (int)$clamped['vault']->key);
check(count($clamped['recovery_codes']) === 20, 'code_count caps at 20');

section('Passphrase unlock');
$secret = $ceremonies->unlockWithPassphrase($fx['user'], $fx['vault'], 'a sufficiently long passphrase');
check($recovered !== null && $secret === $recovered, 'passphrase unwraps the same secret the key file reconstructed');
$threw = false;
try { $ceremonies->unlockWithPassphrase($fx['user'], $fx['vault'], 'the wrong passphrase entirely'); } catch (VaultCeremonyException $e) { $threw = true; }
check($threw, 'a wrong passphrase is refused');
$threw = false;
try { $ceremonies->unlockWithPassphrase($user_c, $clamped['vault'], 'a sufficiently long passphrase'); } catch (VaultCeremonyException $e) { $threw = true; }
check($threw, 'a vault with no passphrase enrolled refuses');

// Per-wrapping salt: retag the wrapping under a DIFFERENT vault salt and it
// must still unlock (the wrapping's own salt wins).
$old_salt = (string)$vault->get('uev_salt');
$vault->set('uev_salt', $box->generateSalt());
$vault->save();
$secret2 = $ceremonies->unlockWithPassphrase($fx['user'], new UserEncryptionVault((int)$vault->key, TRUE), 'a sufficiently long passphrase');
check($secret2 === $secret, 'passphrase unlock survives a uev_salt change (per-wrapping salt)');
$vault->set('uev_salt', $old_salt);
$vault->save();

section('Recovery unlock');
$res = $ceremonies->unlockWithRecoveryCode($fx['user'], $fx['vault'], $fx['recovery_codes'][1], $apcu);
check($res['regenerate_recommended'] === false, 'plenty of codes left: no regenerate nag');
$threw = false;
try { $ceremonies->unlockWithRecoveryCode($fx['user'], $fx['vault'], $fx['recovery_codes'][1], false); } catch (VaultCeremonyException $e) { $threw = true; }
check($threw, 'a consumed code never unlocks again');
$typo = strtr($fx['recovery_codes'][2], ['0' => 'O', '1' => 'l']);
$res = $ceremonies->unlockWithRecoveryCode($fx['user'], $fx['vault'], $typo, false);
check(is_array($res), 'a mistranscribed code (O for 0, l for 1) unlocks');
$threw = false;
try { $ceremonies->unlockWithRecoveryCode($fx['user'], $fx['vault'], 'AAAAA-AAAAA-AAAAA-AAAAA-AAAAA-A', false); } catch (VaultCeremonyException $e) { $threw = true; }
check($threw, 'a wrong code is refused');

// Burn down to fewer than 3 unused: the nag flips on. Of 7 codes, 1 and 2
// are already consumed; burning 3, 4, and 6 leaves only 0 and 5 unused.
$ceremonies->unlockWithRecoveryCode($fx['user'], $fx['vault'], $fx['recovery_codes'][3], false);
$ceremonies->unlockWithRecoveryCode($fx['user'], $fx['vault'], $fx['recovery_codes'][4], false);
$res = $ceremonies->unlockWithRecoveryCode($fx['user'], $fx['vault'], $fx['recovery_codes'][6], false);
check($res['regenerate_recommended'] === true, 'fewer than 3 unused codes recommends regeneration');

section('Recovery kill-switch');
if (!$apcu) {
	harness_skip('APCu unavailable', 'kill-switch ordering needs a live window store; run with -d apc.enable_cli=1');
} else {
	$uid = (int)$fx['user']->key;
	// A pre-existing window on another session (the thief's, say).
	apcu_store('vault:stolen-session:' . $uid . ':user', 'stolen-secret', 3600);
	$ceremonies->unlockWithRecoveryCode($fx['user'], $fx['vault'], $fx['recovery_codes'][5], true);
	check(apcu_fetch('vault:stolen-session:' . $uid . ':user') === false, 'every pre-existing window died first');
	check(VaultUnlock::isOpen($uid), 'and a fresh window opened for the recovering session only');
	VaultUnlock::lockAll($uid);
}

harness_finish();
?>
