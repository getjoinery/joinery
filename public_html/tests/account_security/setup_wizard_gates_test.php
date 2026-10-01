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

section('Leaving from the final checklist ends the interrupt but not the pill');
$leaver = make_user('SetupLeave', 10);
$leaver->set('usr_setup_dismissed_time', null);
$leaver->save();
$_SESSION['usr_user_id'] = (int)$leaver->key;
$_SESSION['loggedin'] = true;
$_SESSION['permission'] = 10;
SetupSteps::resetViewer(); // viewerUser() is cached per request; this is a new viewer
check(!SetupSteps::leftWizard($leaver), 'a fresh account has not left the wizard');
$res = harness_call_logic('logic/setup_logic.php', 'setup_logic', array('action' => 'leave'), 'POST');
check($res->redirect === '/admin', 'Go to your site lands the owner on the site',
	'redirect: ' . var_export($res->redirect, true) . ' error: ' . var_export($res->error, true));
$leaver->load();
check($leaver->get('usr_setup_reviewed_time') !== null && $leaver->get('usr_setup_dismissed_time') === null,
	'leaving records the review, not a dismissal — the header pill keeps counting the skipped steps',
	'reviewed: ' . var_export($leaver->get('usr_setup_reviewed_time'), true) . ' dismissed: ' . var_export($leaver->get('usr_setup_dismissed_time'), true));
check(SetupSteps::leftWizard($leaver), 'the login interrupt stops for a reviewed wizard');
$dismisser = make_user('SetupDismiss', 10);
check(SetupSteps::leftWizard($dismisser), 'and for a dismissed one (the fixture default)');
unset($_SESSION['usr_user_id'], $_SESSION['loggedin'], $_SESSION['permission']);
SetupSteps::resetViewer();

section('A fresh install\'s admin owes a password change and the terms, and can settle both');
// Found on the site copy's first live run (2026-10-01): every signed-in page
// runs the gates, and the terms gate sent the admin off the password page
// while the password gate sent them back, so a new site's admin could not
// sign in. The first gate owed holds its own page; nothing loops.
$fresh = make_user('GateFresh', 10);
$fresh->set('usr_force_password_change', 1);
$fresh->set('usr_terms_accepted_time', null);
$fresh->save();
$_SESSION['usr_user_id'] = (int)$fresh->key;
$_SESSION['loggedin'] = true;
$_SESSION['permission'] = 10;
$session = SessionControl::get_instance();
$owe = function () { unset($_SESSION['force_password_change'], $_SESSION['terms_accepted']); };
$settles = function ($start) use ($session) {
	$path = $start;
	for ($i = 0; $i < 4; $i++) {
		$to = $session->navigation_gate_target($path);
		if ($to === NULL) { return $path; }
		$path = (string)parse_url($to, PHP_URL_PATH);
	}
	return 'loop from ' . $start;
};

$owe();
check($session->navigation_gate_target('/change-password-required') === NULL,
	'owing both, the password page renders');
foreach (array('/', '/terms-accept', '/setup', '/profile/security', '/admin') as $path) {
	check($settles($path) === '/change-password-required', "owing both, $path settles on the password page",
		$settles($path));
}

$fresh->set('usr_force_password_change', 0);
$fresh->save();
$owe();
check($session->navigation_gate_target('/terms-accept') === NULL, 'owing the terms, the terms page renders');
foreach (array('/', '/change-password-required', '/setup', '/profile/security') as $path) {
	check($settles($path) === '/terms-accept', "owing the terms, $path settles on the terms page", $settles($path));
}
check($session->navigation_gate_target('/logout') === NULL, 'and /logout is always open');
unset($_SESSION['usr_user_id'], $_SESSION['loggedin'], $_SESSION['permission']);
$owe();

harness_finish();
