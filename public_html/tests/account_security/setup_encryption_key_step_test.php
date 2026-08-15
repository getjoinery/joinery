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
 * a real key, and a real key outranks the row either way.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/SetupSteps.php'));
require_once(PathHelper::getIncludePath('data/setup_decisions_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));

$user = make_user('SetupKey');
$step = SetupSteps::get('encryption_key');

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
