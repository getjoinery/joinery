<?php
/** @joinery-test
 * name: managed_start_page
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The start page (/server_manager/start): step 1 of a Managed site, the
 * account, offered as sign-in and sign-up side by side.
 *
 *  - A visitor with no session gets the page, and the session's return slot
 *    is pointed at the configure page so either handler lands there.
 *  - A member is past step 1 and goes straight to the configure page.
 *  - The sign-in form is handled by login_logic: a wrong password is shown
 *    on the page, in place; a right one goes to the configure page.
 *  - The sign-up form is handled by register_logic: a refusal is shown in
 *    place; a good sign-up lands on the configure page, signed in.
 *  - The configure page sends a visitor with no session to the start page,
 *    and carries the step strip (step 2 on the form).
 *
 * Run: php plugins/server_manager/tests/managed_start_page_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(__DIR__ . '/../../../tests/lib/logic.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/ManagedSiteDraft.php'));

harness_set_setting_mem('email_dry_run', '1');
harness_set_setting_mem('register_active', '1');
harness_set_setting_mem('anti_spam_answer', 'zen');

$configure = ManagedSiteDraft::CONFIGURE_URL;
$logic = 'plugins/server_manager/logic/start_logic.php';

$member = make_user('MspStart');
$member_email = $member->get('usr_email');
$member_pass = 'TestPassword_MspStart';

// Each sign-in and sign-up attempt gets its own documentation-range address,
// so the throttles never fire here and never touch a real client's budget.
$addr = 200;
function msp_start_call(array $input, string $method = 'POST') {
	global $addr, $logic;
	$_SERVER['REMOTE_ADDR'] = '192.0.2.' . (++$addr);
	return harness_call_logic($logic, 'start_logic', $input, $method);
}

// ---------------------------------------------------------------------------
section('A visitor with no session gets step 1; a member is past it');

$_SESSION = array();
$res = msp_start_call(array(), 'GET');
check($res->redirect === null && $res->error === null, 'a visitor gets the page', var_export($res->redirect, true));
check(($_SESSION['returnurl'] ?? '') === $configure, 'and the return slot points at the configure page');
check(($res->data['error'] ?? 'x') === '' && ($res->data['form'] ?? 'x') === '', 'with nothing refused yet');
check(strpos((string)($res->data['steps_html'] ?? ''), 'Step 1: Your account') !== false
	&& strpos((string)($res->data['steps_html'] ?? ''), 'aria-current="step"') !== false,
	'the step strip says this is step 1');

$_SESSION = array('loggedin' => 1, 'usr_user_id' => (int)$member->key, 'permission' => 0);
$res = msp_start_call(array(), 'GET');
check($res->redirect === $configure, 'a member goes straight to the configure page', var_export($res->redirect, true));

// ---------------------------------------------------------------------------
section('Sign in, on the page');

$_SESSION = array();
// The page's sign-in form uses the handler's lbx_* field names, so its ids
// never collide with the sign-up form beside it.
$res = msp_start_call(array('form' => 'login', 'lbx_email' => $member_email, 'lbx_password' => 'not-it'));
check($res->redirect === null && ($res->data['form'] ?? '') === 'login'
	&& stripos((string)($res->data['error'] ?? ''), 'incorrect') !== false,
	'a wrong password is refused on the page, under the sign-in form', var_export($res->data['error'] ?? null, true));
check(($res->data['values']['lbx_email'] ?? '') === $member_email, 'with the typed email kept');

$_SESSION = array();
$res = msp_start_call(array('form' => 'login', 'lbx_email' => $member_email, 'lbx_password' => $member_pass));
check($res->redirect === $configure, 'the right password goes to the configure page', var_export($res->redirect, true));
check((int)($_SESSION['usr_user_id'] ?? 0) === (int)$member->key, 'signed in as the member');

// ---------------------------------------------------------------------------
section('Create an account, on the page');

$token = bin2hex(random_bytes(4));
$new_email = 'msp_start_' . $token . '@example.com';
harness_defer(function () use ($new_email) {
	$user = User::GetByEmail($new_email);
	if (!$user) return;
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare('DELETE FROM act_activation_codes WHERE act_usr_user_id = ?');
	$q->execute(array($user->key));
	$user->permanent_delete();
});
$signup = array(
	'form'              => 'register',
	'usr_email'         => $new_email,
	'usr_first_name'    => 'Start',
	'usr_last_name'     => 'Page',
	'password'          => 'StartPage_Passw0rd',
	'antispam_question' => 'zen',
);

$_SESSION = array();
$res = msp_start_call(array_merge($signup, array('antispam_question' => 'nope')));
check($res->redirect === null && ($res->data['form'] ?? '') === 'register'
	&& stripos((string)($res->data['error'] ?? ''), 'anti-spam') !== false,
	'a refused sign-up is shown on the page, under the sign-up form', var_export($res->data['error'] ?? null, true));
check(User::GetByEmail($new_email) === null || User::GetByEmail($new_email) === false, 'and no account was made');

$_SESSION = array();
$res = msp_start_call($signup);
check($res->redirect === $configure, 'a good sign-up lands on the configure page', var_export($res->redirect, true)
	. ' error: ' . var_export($res->error, true));
$made = User::GetByEmail($new_email);
check($made && (int)($_SESSION['usr_user_id'] ?? 0) === (int)$made->key, 'signed in as the new account');
check(empty($_SESSION['returnurl']), 'and the return slot is spent');

// ---------------------------------------------------------------------------
section('The configure page sends a visitor to step 1 and shows step 2');

$_SESSION = array();
$res = harness_call_logic('plugins/server_manager/logic/profile_configure_logic.php', 'profile_configure_logic', array(), 'GET');
check($res->redirect === ManagedSiteDraft::START_URL, 'no session: the configure page sends the visitor to the start page',
	var_export($res->redirect, true));

$_SESSION = array('loggedin' => 1, 'usr_user_id' => (int)$member->key, 'permission' => 0);
$res = harness_call_logic('plugins/server_manager/logic/profile_configure_logic.php', 'profile_configure_logic', array(), 'GET');
check($res->redirect === null && ($res->data['mode'] ?? '') === 'form', 'a member gets the form');
check(strpos((string)($res->data['steps_html'] ?? ''), 'aria-current="step"><span class="sms-step-n">2</span>') !== false,
	'and the strip says step 2');

harness_finish();
