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
 *  - Someone who left the wizard is brought back once for a step never shown
 *    to them, and walks just those steps.
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
check($res->redirect === '/', 'Go to your site lands the owner on the site\'s home page',
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

section('An account that owes a password change and the terms can settle both');
// Found on the site copy's first live run (2026-10-01): every signed-in page
// runs the gates, and the terms gate sent the admin off the password page
// while the password gate sent them back, so a new site's admin could not
// sign in. The first gate owed holds its own page; nothing loops. A member
// owes the site's own terms (an admin of a self-hosted site owes none:
// terms_owed_test), so the site names some for the run.
harness_set_setting_mem('terms_url', 'https://example.org/terms');
$fresh = make_user('GateFresh', 0);
$fresh->set('usr_force_password_change', 1);
$fresh->set('usr_terms_accepted_time', null);
$fresh->save();
$_SESSION['usr_user_id'] = (int)$fresh->key;
$_SESSION['loggedin'] = true;
$_SESSION['permission'] = 0;
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

section('A step never shown to someone who left the wizard brings it back once, at just that step');
// Steps of this test's own, so the cases do not hang on what the real steps'
// status is on this database.
$sw_state = array('sw_new_user' => false, 'sw_done_user' => true, 'sw_new_site' => false, 'sw_gated' => false);
$sw_on = false;
$sw_status = function ($key) use (&$sw_state) {
	return function () use (&$sw_state, $key) { return $sw_state[$key] ? SetupSteps::STATUS_GREEN : SetupSteps::STATUS_NONE; };
};
$sw_keys = function (array $steps) { return array_map(function ($st) { return $st['key']; }, $steps); };
$sw_user = make_user('SetupSeen', 0);
check(SetupSteps::leftWizard($sw_user) && !SetupSteps::unseenSteps($sw_user, 0),
	'a fixture has left the wizard and seen every step there is');

SetupSteps::register('sw_new_user', array('title' => 'New user step', 'scope' => 'user', 'order' => 991, 'status' => $sw_status('sw_new_user')));
SetupSteps::register('sw_done_user', array('title' => 'Done user step', 'scope' => 'user', 'order' => 992, 'status' => $sw_status('sw_done_user')));
SetupSteps::register('sw_new_site', array('title' => 'New site step', 'scope' => 'site', 'order' => 993, 'status' => $sw_status('sw_new_site')));
SetupSteps::register('sw_gated', array('title' => 'Gated step', 'scope' => 'user', 'order' => 994, 'status' => $sw_status('sw_gated'),
	'active' => function () use (&$sw_on) { return $sw_on; }));

check($sw_keys(SetupSteps::unseenSteps($sw_user, 0)) === array('sw_new_user'),
	'a step added since they left, not done, is unseen; one already done and a site step for a member are not',
	json_encode($sw_keys(SetupSteps::unseenSteps($sw_user, 0))));
check(in_array('sw_new_site', $sw_keys(SetupSteps::unseenSteps($sw_user, 10)), true),
	'the site step becomes unseen once they are an owner (a promotion)');
$sw_on = true;
check(in_array('sw_gated', $sw_keys(SetupSteps::unseenSteps($sw_user, 0)), true),
	'a step whose feature is switched on is unseen');
$sw_on = false;

$_SESSION['usr_user_id'] = (int)$sw_user->key;
$_SESSION['loggedin'] = true;
$_SESSION['permission'] = 0;
SetupSteps::resetViewer();
$res = harness_call_logic('logic/setup_logic.php', 'setup_logic', array(), 'GET');
check($res->redirect === null && ($res->data['current_key'] ?? '') === 'sw_new_user' && (int)($res->data['total'] ?? 0) === 1,
	'the wizard opens at the unseen step, as a walk of one step',
	'redirect: ' . var_export($res->redirect, true) . ' current: ' . var_export($res->data['current_key'] ?? null, true)
	. ' total: ' . var_export($res->data['total'] ?? null, true));
$res = harness_call_logic('logic/setup_logic.php', 'setup_logic', array('step' => 'sw_done_user'), 'GET');
check(($res->data['current_key'] ?? '') === 'sw_done_user' && (int)($res->data['total'] ?? 0) > 1,
	'a step asked for by name opens in the whole walk',
	'current: ' . var_export($res->data['current_key'] ?? null, true));

$res = harness_call_logic('logic/setup_logic.php', 'setup_logic', array('action' => 'leave'), 'POST');
$sw_user->load();
SetupSteps::resetViewer();
check($res->redirect === '/' && !SetupSteps::unseenSteps($sw_user, 0),
	'leaving records it as seen: it does not bring the wizard back again, though it is still not done',
	'unseen: ' . json_encode($sw_keys(SetupSteps::unseenSteps($sw_user, 0))));

$sw_user->set('usr_setup_seen_steps', null);
$sw_user->save();
$sw_user->load();
check(in_array('sw_new_user', $sw_keys(SetupSteps::unseenSteps($sw_user, 0)), true)
	&& !in_array('sw_done_user', $sw_keys(SetupSteps::unseenSteps($sw_user, 0)), true),
	'an account that left before the record existed has seen nothing: every step not done is unseen once');
$res = harness_call_logic('logic/setup_logic.php', 'setup_logic', array('action' => 'dismiss', 'understand' => '1'), 'POST');
$sw_user->load();
check(!SetupSteps::unseenSteps($sw_user, 0), 'Finish later records what was shown as well');
unset($_SESSION['usr_user_id'], $_SESSION['loggedin'], $_SESSION['permission']);
SetupSteps::resetViewer();

harness_finish();
