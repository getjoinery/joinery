<?php
/** @joinery-test
 * name: setup_encryption_key_step
 * tier: db
 * env: any
 * needs: [db]
 */
/**
 * The setup wizard's encryption_key step. The key is mandatory — no decline is
 * offered — but an account that CANNOT hold one (U2F-only passkey, or no
 * account password) records a decision so it can still finish setup. These
 * check that fallback: it settles the step, the wizard can still tell it from
 * a real key, a real key outranks the row either way, and the decline itself
 * is refused server-side for any account that can comply.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/SetupSteps.php'));
require_once(PathHelper::getIncludePath('data/setup_decisions_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));

$user = make_user('SetupKey');
$step = SetupSteps::get('encryption_key');

/** A credential row good enough for capability questions. */
function setupkey_make_passkey(int $user_id, bool $prf_capable, bool $prf_failed = false) {
	$passkey = new Passkey(NULL);
	$passkey->set('pkc_usr_user_id', $user_id);
	$passkey->set('pkc_credential_id', 'cred_' . bin2hex(random_bytes(8)));
	$passkey->set('pkc_source_json', json_encode(array('uvInitialized' => true)));
	$passkey->set('pkc_prf_capable', $prf_capable);
	if ($prf_failed) {
		$passkey->set('pkc_prf_failed_time', gmdate('Y-m-d H:i:s'));
	}
	$passkey->set('pkc_transports', json_encode(array('internal')));
	$passkey->save();
	harness_register_row('pkc_passkey_credentials', 'pkc_passkey_credential_id', $passkey->key);
	return $passkey;
}

section('Registry');
check(is_array($step), 'the encryption_key step is registered');
check(($step['decision'] ?? '') === 'user', 'it accepts a per-user decision row',
	'the capability fallback needs it — without one, an account that cannot hold a key never reaches all-green');
check(isset($step['real_status']), 'it declares real_status',
	'needed to tell "declined" from "done"');

section('No vault, no decision');
check(SetupSteps::statusFor($step, $user) === SetupSteps::STATUS_NONE,
	'the step is outstanding');
check(SetupSteps::isDeclinedOnly($step, $user) === false,
	'and is not reported as declined');

section('The decline is gated server-side');
check(isset($step['can_decline']), 'the step declares can_decline',
	'without it, a direct decline_step POST settles the mandatory step for any account');
check(SetupSteps::canDecline($step, $user) === false,
	'a passworded account with no passkeys cannot decline — it is sent to enrol one');

$capable_user = make_user('SetupKeyCap');
setupkey_make_passkey((int)$capable_user->key, true);
check(SetupSteps::canDecline($step, $capable_user) === false,
	'an account with a capable passkey cannot decline',
	'the mandatory step must hold at the handler, not the hidden button');

$blocked_user = make_user('SetupKeyBlk');
setupkey_make_passkey((int)$blocked_user->key, false, true);
check(SetupSteps::canDecline($step, $blocked_user) === true,
	'a proven-incapable account can decline');

$nopass_user = make_user('SetupKeyNoPw');
$nopass_user->set('usr_password', '');
$nopass_user->save();
$nopass_user->load();
check(SetupSteps::canDecline($step, $nopass_user) === true,
	'so can an account with no password yet — the other exit the platform cannot solve');

section('Blocked account takes the fallback');
SetupSteps::recordDecision('encryption_key', (int)$user->key);
$decisions = new MultiSetupDecision(array('step_key' => 'encryption_key', 'user_id' => (int)$user->key));
foreach ($decisions as $row) {
	harness_register_row('sud_setup_decisions', 'sud_setup_decision_id', $row->key);
}
check(SetupSteps::statusFor($step, $user) === SetupSteps::STATUS_GREEN,
	'the step counts as green — the wizard measures decided, not enabled');
check(SetupSteps::isDeclinedOnly($step, $user) === true,
	'but it is flagged declined, so the wizard keeps offering the ceremony',
	'a declined step must not render "already done" over a key that does not exist');
check(SetupSteps::recordDecision('encryption_key', (int)$user->key) === null
	&& (new MultiSetupDecision(array('step_key' => 'encryption_key', 'user_id' => (int)$user->key)))->count_all() === 1,
	'declining twice does not stack rows');

section('Real state wins');
$box = new SealedBox();
$keypair = $box->generateKeypair();
$vault = new UserEncryptionVault(NULL);
$vault->set('uev_usr_user_id', (int)$user->key);
$vault->set('uev_scope', UserEncryptionVault::SCOPE_USER);
$vault->set('uev_custody', 'server');
$vault->set('uev_public_key', $keypair['public']);
$vault->set('uev_salt', $box->generateSalt());
$vault->save();
harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', $vault->key);

check(SetupSteps::statusFor($step, $user) === SetupSteps::STATUS_GREEN,
	'a vault holder is green');
check(SetupSteps::isDeclinedOnly($step, $user) === false,
	'and is no longer declined — creating the key later makes the row irrelevant');

harness_finish();
