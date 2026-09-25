<?php
/** @joinery-test
 * name: vault_unlock_no_second_factor
 * tier: db
 * env: any
 * needs: [db]
 */
/**
 * A vault opens with a passkey, its passphrase or a recovery code — and the
 * account's sign-in second factor never takes part (docs/sealed_vault.md,
 * docs/account_security.md § Unlockers, ranked). An authenticator code confirms
 * sign-ins and sensitive changes; it opens nothing.
 *
 * So the two unlocks a person types — the passphrase and a recovery code —
 * must each open the vault on their own for an account that holds an
 * authenticator app and a passkey, with no step-up marker in the session. If
 * either answers "confirm your second factor first", the step-up page would
 * offer an authenticator code on the way into the vault, which is exactly what
 * this pins shut.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('tests/lib/vault_fixtures.php'));
require_once(PathHelper::getIncludePath('logic/vault_unlock_passphrase_logic.php'));
require_once(PathHelper::getIncludePath('logic/vault_unlock_recovery_logic.php'));

if (!vault_ensure_session()) {
	harness_skip('vault unlock suite', 'no session could be started on the CLI');
	harness_finish();
}
if (!vault_apcu_usable()) {
	harness_skip('vault unlock suite', 'an unlock arms a window; run with -d apc.enable_cli=1');
	harness_finish();
}

harness_set_setting_mem('passkeys_enabled', '1');
harness_set_setting_mem('email_dry_run', '1');

$phrase = 'a passphrase long enough for the setup ceremony';
$fx = vault_fixture_vault('NoSecondFactor', $phrase);
$user = $fx['user'];
$user->enable_totp('JBSWY3DPEHPK3PXP');
$user_id = (int)$user->key;

$sid = session_id();
harness_defer(function () use ($sid, $user_id) {
	VaultUnlock::lockAll($user_id);
	$db = DbConnector::get_instance()->get_db_link();
	$db->prepare("DELETE FROM pks_passkey_ceremonies WHERE pks_session_id = ?")->execute(array($sid));
	$db->prepare("DELETE FROM rql_request_logs WHERE rql_usr_user_id = ?")->execute(array($user_id));
});

$_SESSION['usr_user_id'] = $user_id;
$_SESSION['loggedin'] = true;
$_SESSION['permission'] = 0;
$session = SessionControl::get_instance();

// ---------------------------------------------------------------------------
section('The account holds a sign-in second factor and no fresh confirmation');

$fresh = new User($user_id, TRUE);
check($fresh->has_totp_enabled(), 'the account has an authenticator app');
check($session->user_has_second_factor($fresh), 'the account counts as holding a second factor');
check(!$session->has_recent_second_factor(), 'this session has confirmed no second factor');

// ---------------------------------------------------------------------------
section('The passphrase opens the vault on its own');

VaultUnlock::lockAll($user_id);
$res = vault_unlock_passphrase_logic(array('passphrase_kek' => SealedBox::b64url($fx['passphrase_kek'])));
check(empty($res->data['second_factor_required']),
	'the phrase is not sent to the second-factor step-up page');
check($res->error === null && !empty($res->data['unlocked']),
	'the phrase unlocks the vault',
	'error: ' . var_export($res->error, true));
check(VaultUnlock::isOpen($user_id), 'the unlock window is open');

// ---------------------------------------------------------------------------
section('A recovery code opens the vault on its own');

VaultUnlock::lockAll($user_id);
check(!VaultUnlock::isOpen($user_id), 'the vault is locked again before the code is tried');
$res = vault_unlock_recovery_logic(array('code_kek' => vault_fixture_code_kek($fx['recovery_codes'][0], $fx['root_salt'])));
check(empty($res->data['second_factor_required']),
	'the code is not sent to the second-factor step-up page');
check($res->error === null && !empty($res->data['unlocked']),
	'the code unlocks the vault',
	'error: ' . var_export($res->error, true));
check(VaultUnlock::isOpen($user_id), 'the unlock window is open');
check(!empty($res->data['root_wrapping']), 'and hands back the code\'s root twin for the browser');

// ---------------------------------------------------------------------------
section('Neither action takes the phrase or the code itself');

// The browser derives and posts only the account half of a KEK
// (specs/one_vault_experience.md § R6, R7); a raw phrase or code opens nothing.
VaultUnlock::lockAll($user_id);
$res = vault_unlock_passphrase_logic(array('passphrase' => $phrase));
check($res->error !== null && !VaultUnlock::isOpen($user_id), 'a raw passphrase is refused');
$res = vault_unlock_recovery_logic(array('code' => $fx['recovery_codes'][1]));
check($res->error !== null && !VaultUnlock::isOpen($user_id), 'a raw recovery code is refused');

harness_finish();
