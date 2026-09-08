<?php
/** @joinery-test
 * name: setup_wizard_gates
 * tier: db
 * env: any
 * needs: []
 */

/**
 * The setup wizard's gates, found on testing day 2026-09-07/08
 * (specs/testing_day_agent_wizard_install.md, defects B3 and B15):
 *
 *  - /setup never calls check_permission(), so it rendered and accepted a
 *    save before the forced first-login password change. The wizard now
 *    applies the same two gates itself, in check_permission()'s order.
 *  - The login interrupt sends every page to /setup while a step is open,
 *    which made "Add a passkey elsewhere" (the encryption step's route for a
 *    passkey that cannot derive a key) a dead end: the security page was
 *    bounced back into the wizard. The exemption list is one function.
 */

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/logic.php');
harness_boot();

section('The login interrupt leaves the routes the wizard itself depends on alone');
foreach (array('/setup', '/logout', '/profile/security', '/verify-stepup', '/api/v1/passkey_register_options') as $path) {
	check(SetupSteps::interruptExempt($path), "$path is exempt");
}
foreach (array('/', '/profile', '/profile/security/other', '/admin/server_manager', '/api/v2/x', '/setupx') as $path) {
	check(!SetupSteps::interruptExempt($path), "$path is interrupted");
}

section('The wizard stands behind the forced password change');
$user = make_user('SetupGate', 10);
$_SESSION['usr_user_id'] = (int)$user->key;
$_SESSION['loggedin'] = true;
$user->set('usr_force_password_change', 1);
$user->save();
unset($_SESSION['force_password_change']);
$res = harness_call_logic('logic/setup_logic.php', 'setup_logic', array(), 'GET');
check($res->redirect === '/change-password-required',
	'an account that must change its password is sent there before the wizard renders',
	'redirect: ' . var_export($res->redirect, true) . ' error: ' . var_export($res->error, true));

$user->set('usr_force_password_change', 0);
$user->save();
unset($_SESSION['force_password_change']);
$res = harness_call_logic('logic/setup_logic.php', 'setup_logic', array(), 'GET');
check($res->redirect !== '/change-password-required',
	'once the password is changed the wizard no longer redirects there',
	'redirect: ' . var_export($res->redirect, true));
unset($_SESSION['usr_user_id'], $_SESSION['loggedin'], $_SESSION['force_password_change']);

harness_finish();
