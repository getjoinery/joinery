<?php
/** @joinery-test
 * name: signup_fields
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 120
 */
/**
 * A plugin can add a field to the forms that create an account and act on it
 * once the account exists (spec email_lists_plugin G5, SignupFieldRegistry).
 *
 * What is pinned here:
 *  - the registration form and the admin add-user form show a contributed
 *    field, each only for the contexts it asks for;
 *  - after a registration, the contributor's handler gets the new account and
 *    the posted value;
 *  - the admin add-user save does the same, with its own context;
 *  - a handler that throws does not stop the registration.
 *
 * Run: php tests/run.php db --filter=signup_fields
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/logic.php');
harness_boot();

if (session_id() === '') @session_start();

harness_set_setting_mem('email_dry_run', '1');
harness_set_setting_mem('register_active', '1');
harness_set_setting_mem('use_honeypot', '0');
harness_set_setting_mem('use_captcha', '0');

$token = bin2hex(random_bytes(4));
$seen = array();

SignupFieldRegistry::register('hts_optin', array(
	'render' => function ($fw, string $context, array $input) {
		$fw->checkboxinput('hts_optin', 'HarnessTest opt-in for ' . $context, array('value' => 'yes'));
	},
	'handle' => function (User $user, array $input, string $context) use (&$seen) {
		$seen[] = array('user' => (int)$user->key, 'value' => $input['hts_optin'] ?? null, 'context' => $context);
	},
));
SignupFieldRegistry::register('hts_register_only', array(
	'contexts' => array(SignupFieldRegistry::CONTEXT_REGISTER),
	'render'   => function ($fw, string $context, array $input) {
		$fw->checkboxinput('hts_register_only', 'HarnessTest register only', array('value' => 'yes'));
	},
	'handle'   => function (User $user, array $input, string $context) {
		throw new RuntimeException('HarnessTest handler failure');
	},
));

$cleanup = function (string $email) {
	harness_defer(function () use ($email) {
		$user = User::GetByEmail($email);
		if (!$user) return;
		$q = DbConnector::get_instance()->get_db_link()->prepare('DELETE FROM act_activation_codes WHERE act_usr_user_id = ?');
		$q->execute(array($user->key));
		$user->permanent_delete();
	});
};
harness_defer(function () {
	$db = DbConnector::get_instance()->get_db_link();
	$db->prepare("DELETE FROM rql_request_logs WHERE rql_feature = 'register' AND rql_ip_address = '192.0.2.211'")->execute();
});

section('The forms show contributed fields');
ob_start();
$fw = new FormWriterV2HTML5('form1');
register_logic_form_loader();
register_logic_form($fw, null, array());
$register_html = ob_get_clean() . $fw->end_form();
check(strpos($register_html, 'hts_optin') !== false && strpos($register_html, 'HarnessTest opt-in for register') !== false,
	'the registration form shows the field, told its context');
check(strpos($register_html, 'hts_register_only') !== false, 'and the register-only field');

ob_start();
$fw2 = new FormWriterV2HTML5('form2');
SignupFieldRegistry::render($fw2, SignupFieldRegistry::CONTEXT_ADMIN_ADD, array());
$admin_html = ob_get_clean() . $fw2->end_form();
check(strpos($admin_html, 'HarnessTest opt-in for admin_add') !== false, 'the admin add-user form shows the field');
check(strpos($admin_html, 'hts_register_only') === false, 'but not one that asked only for registration');

section('A registration hands the new account to the handler');
$email = 'signupfields_' . $token . '@example.com';
$cleanup($email);
$_SESSION = array();
$_SERVER['REMOTE_ADDR'] = '192.0.2.211';
new FormWriterV2HTML5('form1');
$res = harness_call_logic('logic/register_logic.php', 'register_logic', array(
	'usr_email'         => $email,
	'usr_first_name'    => 'Signup',
	'usr_last_name'     => 'Fields',
	'password'          => 'SignupFields_Passw0rd',
	'antispam_question' => (string)Globalvars::get_instance()->get_setting('anti_spam_answer'),
	'privacy'           => 'yes',
	'hts_optin'         => 'yes',
	'_csrf_token'       => $_SESSION['csrf_tokens']['form1']['token'] ?? '',
), 'POST');
$user = User::GetByEmail($email);
check($user !== null, 'the account was created, though a handler threw', var_export($res->error ?? null, true));
check($user && count($seen) === 1 && $seen[0]['user'] === (int)$user->key && $seen[0]['value'] === 'yes' && $seen[0]['context'] === 'register',
	'the handler got the account, the value and the context', json_encode($seen));

section('The admin add-user save does the same');
$seen = array();
$admin = make_user('SignupFieldsAdmin', 10);
$_SESSION = array();
SessionControl::get_instance()->store_session_variables($admin);
$email2 = 'signupfields_admin_' . $token . '@example.com';
$cleanup($email2);
harness_call_logic('adm/logic/admin_user_add_logic.php', 'admin_user_add_logic', array(
	'usr_email'      => $email2,
	'usr_first_name' => 'Signup',
	'usr_last_name'  => 'Admin',
	'password'       => 'SignupFields_Passw0rd',
	'hts_optin'      => 'yes',
), 'POST');
$user2 = User::GetByEmail($email2);
check($user2 && count($seen) === 1 && $seen[0]['user'] === (int)$user2->key && $seen[0]['context'] === 'admin_add',
	'the handler got the admin-made account with context admin_add', json_encode($seen));
$_SESSION = array();

harness_finish();

/** register_logic_form lives in the logic file. */
function register_logic_form_loader(): void {
	require_once(PathHelper::getIncludePath('logic/register_logic.php'));
}
