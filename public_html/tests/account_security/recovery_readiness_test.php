<?php
/** @joinery-test
 * name: recovery_readiness
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 */
/**
 * Recovery Readiness: the verification ledger, the vault-code DRY RUN (the
 * whole point: a correct code passes WITHOUT being consumed), custody
 * refusal (client-custody codes never verify server-side), and the ceremony
 * verifier's failure path.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();

require_once(PathHelper::getIncludePath('includes/RecoveryReadiness.php'));
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
require_once(PathHelper::getIncludePath('data/recovery_verifications_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_wrappings_class.php'));

$db = DbConnector::get_instance()->get_db_link();

// A real user with no 'user'-scope vault, so the fixture vault is unambiguous.
$q = $db->query(
	"SELECT u.usr_user_id FROM usr_users u
	  WHERE NOT EXISTS (SELECT 1 FROM uev_user_encryption_vaults v
	                     WHERE v.uev_usr_user_id = u.usr_user_id AND v.uev_scope = 'user')
	  LIMIT 1");
$fixture_user_id = (int)$q->fetchColumn();
if (!$fixture_user_id) {
	harness_skip('no fixture user available', 'test DB has no user without a user-scope vault');
	harness_finish();
}

$cleanup_vaults = array();

// ── Ledger ──────────────────────────────────────────────────────────────
section('Verification ledger');

$row = RecoveryVerification::record('rr_test_item', RecoveryVerification::METHOD_ATTESTED, $fixture_user_id, true);
check($row->key > 0, 'ledger row saves');
$latest = RecoveryVerification::latest_passed(array('rr_test_item'), $fixture_user_id);
check(isset($latest['rr_test_item']), 'latest_passed finds the passed row');
$latest_other = RecoveryVerification::latest_passed(array('rr_test_item'), $fixture_user_id + 999999);
check(!isset($latest_other['rr_test_item']), 'latest_passed is scoped per user');
RecoveryVerification::record('rr_test_item_fail', RecoveryVerification::METHOD_DRY_RUN, $fixture_user_id, false);
$latest_fail = RecoveryVerification::latest_passed(array('rr_test_item_fail'), $fixture_user_id);
check(!isset($latest_fail['rr_test_item_fail']), 'failed rows never count as verified');

$threw = false;
try {
	RecoveryVerification::record('rr_test_item', 'bogus_method', $fixture_user_id, true);
} catch (Exception $e) {
	$threw = true;
}
check($threw, 'unknown verification method is refused');

// ── Fixture vault + wrapping (server custody) ───────────────────────────
section('Vault-code dry run: pass without consuming');

$box = new SealedBox();
$code = 'RRTEST-' . bin2hex(random_bytes(4));
$salt = base64_encode(random_bytes(16));
$secret = $box->generateKeypair()['secret']; // the dry run opens it through VaultUnlock::openKey(), which wants a real X25519 secret

$vault = new UserEncryptionVault(NULL);
$vault->set('uev_usr_user_id', $fixture_user_id);
$vault->set('uev_scope', UserEncryptionVault::SCOPE_USER);
$vault->set('uev_custody', UserEncryptionVault::CUSTODY_SERVER);
$vault->set('uev_public_key', 'rr-test-public-key');
$vault->set('uev_salt', $salt);
$vault->save();
$vault->load();
$cleanup_vaults[] = (int)$vault->key;

// AD binds (vault_id, wrapping_id), so the row exists before it is wrapped.
$wrapping = new UserEncryptionWrapping(NULL);
$wrapping->set('uew_uev_user_encryption_vault_id', $vault->key);
$wrapping->set('uew_unlocker_type', UserEncryptionWrapping::TYPE_RECOVERY);
$wrapping->set('uew_wrapped_secret_key', 'placeholder');
$wrapping->set('uew_salt', $salt);
$wrapping->set('uew_is_used', false);
$wrapping->save();
$wrapping->load();

$kek = $box->kekFromRecoveryCode($code, $salt);
$ad = UserEncryptionWrapping::adFor((int)$vault->key, (int)$wrapping->key);
$wrapping->set('uew_wrapped_secret_key', $box->wrapKey($secret, $kek, $ad));
$wrapping->save();

$outcome = RecoveryReadiness::dryRunVaultCode($fixture_user_id, UserEncryptionVault::SCOPE_USER, $code);
check($outcome['ok'] === true, 'correct code passes the dry run', $outcome['message']);

// The single guarantee this page makes is non-consumption.
$q = $db->prepare('SELECT uew_is_used::text FROM uew_user_encryption_wrappings WHERE uew_user_encryption_wrapping_id = ?');
$q->execute(array((int)$wrapping->key));
check($q->fetchColumn() === 'false', 'the code was NOT consumed by the dry run');

$outcome = RecoveryReadiness::dryRunVaultCode($fixture_user_id, UserEncryptionVault::SCOPE_USER, $code);
check($outcome['ok'] === true, 'the same code still passes a second dry run (still unconsumed)');

// ── Staleness follows the codes, not only the clock ─────────────────────
section('A check older than the codes is stale');

// The member-facing items for the fixture user: one card per vault. Read the
// ledger's view of it through the same path the security page uses.
$rr_items = function () use ($fixture_user_id) {
	$m = new ReflectionMethod('RecoveryReadiness', 'attachLedger');
	$m->setAccessible(true);
	$v = new ReflectionMethod('RecoveryReadiness', 'vaultItems');
	$v->setAccessible(true);
	$out = array();
	foreach ($m->invoke(null, $v->invoke(null, $fixture_user_id), $fixture_user_id) as $item) {
		$out[$item['key']] = $item;
	}
	return $out;
};
$item_key = 'vault_codes_' . UserEncryptionVault::SCOPE_USER;

$item = $rr_items()[$item_key] ?? null;
check($item !== null && $item['codes_since'] !== null, 'a vault item says when its newest live recovery code was made');
check($item !== null && $item['last_verified'] === null && $item['stale'], 'never checked reads as stale');

RecoveryVerification::record($item_key, RecoveryVerification::METHOD_DRY_RUN, $fixture_user_id, true);
$item = $rr_items()[$item_key];
check($item['last_verified'] !== null && !$item['stale'] && !$item['codes_changed'],
	'a check made after the codes were made is current');

// The codes are regenerated (or retired by a key rotation): the old wrapping
// goes, a new one arrives, and the check that proved the old one is stale.
$db->prepare('UPDATE uew_user_encryption_wrappings SET uew_delete_time = now() WHERE uew_user_encryption_wrapping_id = ?')
	->execute(array((int)$wrapping->key));
$db->prepare("INSERT INTO uew_user_encryption_wrappings (uew_uev_user_encryption_vault_id, uew_unlocker_type, uew_wrapped_secret_key, uew_salt, uew_is_used, uew_key_generation, uew_create_time)
	VALUES (?, 'recovery', 'newer-placeholder', ?, false, 1, now() + interval '2 seconds')")->execute(array((int)$vault->key, $salt));
$item = $rr_items()[$item_key];
check($item['codes_changed'] && $item['stale'], 'after the codes change, the earlier check is stale');

RecoveryVerification::record($item_key, RecoveryVerification::METHOD_DRY_RUN, $fixture_user_id, true);
$db->prepare("UPDATE rcv_recovery_verifications SET rcv_verify_time = now() + interval '5 seconds' WHERE rcv_item_key = ? AND rcv_usr_user_id = ? AND rcv_passed = true")
	->execute(array($item_key, $fixture_user_id));
$item = $rr_items()[$item_key];
check(!$item['codes_changed'] && !$item['stale'], 'a fresh check of the new codes is current again');

// Put the fixture wrapping back for the failure-path section below.
$db->prepare("DELETE FROM uew_user_encryption_wrappings WHERE uew_wrapped_secret_key = 'newer-placeholder' AND uew_uev_user_encryption_vault_id = ?")->execute(array((int)$vault->key));
$db->prepare('UPDATE uew_user_encryption_wrappings SET uew_delete_time = NULL WHERE uew_user_encryption_wrapping_id = ?')->execute(array((int)$wrapping->key));
$db->prepare('DELETE FROM rcv_recovery_verifications WHERE rcv_item_key = ? AND rcv_usr_user_id = ?')->execute(array($item_key, $fixture_user_id));

section('Vault-code dry run: failure paths');

$outcome = RecoveryReadiness::dryRunVaultCode($fixture_user_id, UserEncryptionVault::SCOPE_USER, 'WRONG-CODE');
check($outcome['ok'] === false, 'wrong code fails the dry run');
$outcome = RecoveryReadiness::dryRunVaultCode($fixture_user_id, UserEncryptionVault::SCOPE_USER, '');
check($outcome['ok'] === false, 'empty code is refused');
$outcome = RecoveryReadiness::dryRunVaultCode($fixture_user_id, 'drive', 'ANY-CODE');
check($outcome['ok'] === false && strpos($outcome['message'], 'vault') !== false, 'missing scope vault is refused');

// Client custody must be refused server-side: the code never travels here.
$client_vault = new UserEncryptionVault(NULL);
$client_vault->set('uev_usr_user_id', $fixture_user_id);
$client_vault->set('uev_scope', 'drive');
$client_vault->set('uev_custody', UserEncryptionVault::CUSTODY_CLIENT);
$client_vault->set('uev_public_key', 'rr-test-client-public-key');
$client_vault->set('uev_salt', $salt);
$client_vault->save();
$client_vault->load();
$cleanup_vaults[] = (int)$client_vault->key;

$outcome = RecoveryReadiness::dryRunVaultCode($fixture_user_id, 'drive', $code);
check($outcome['ok'] === false && stripos($outcome['message'], 'client') !== false,
	'client-custody scope refuses a server-side check');

// ── Ceremony verifier (failure path is deterministic everywhere) ────────
section('Recovery key ceremony verifier');

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/RecoveryReadinessItems.php'));
$outcome = RecoveryReadinessItems::verify_recovery_key(array('escrow_proof' => 'not the proof sentence'));
check($outcome['ok'] === false, 'a wrong proof never verifies');
$outcome = RecoveryReadinessItems::verify_recovery_key(array());
check($outcome['ok'] === false, 'a missing proof never verifies');

// ── Cleanup ─────────────────────────────────────────────────────────────
foreach ($cleanup_vaults as $vault_id) {
	$db->prepare('DELETE FROM uew_user_encryption_wrappings WHERE uew_uev_user_encryption_vault_id = ?')->execute(array($vault_id));
	$db->prepare('DELETE FROM uev_user_encryption_vaults WHERE uev_user_encryption_vault_id = ?')->execute(array($vault_id));
}
$db->prepare("DELETE FROM rcv_recovery_verifications WHERE rcv_item_key LIKE 'rr_test_item%'")->execute();

harness_finish();
