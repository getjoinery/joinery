<?php
/** @joinery-test
 * name: machine_account
 * tier: db
 * env: dev-only
 * needs: [db, dev-web]
 */
/**
 * A machine account belongs to a program, not a person (machine_accounts.md).
 * No door gives it a website session, and one it already holds ends at its next
 * request; the second-factor notice leaves it out; it stays in the admin alert
 * audience; "Log in as user" refuses it before its audit row; and only a
 * superadmin marks it, never on their own account.
 *
 * The API side (machine key works, session key and auth/login refused) is in
 * tests/functional/api/session_keys_test.php.
 */

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/http.php');
harness_boot();
harness_http_boot($argv);
require_once(PathHelper::getIncludePath('adm/logic/admin_user_login_as_logic.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_users_edit_logic.php'));

$mark = function ($user_id, bool $machine) {
	$u = new User($user_id, TRUE);
	$u->set('usr_is_machine', $machine);
	$u->save();
};
// Signed in when the account page answers with the account's own address;
// signed out, it sends the browser to sign in.
$signed_in = function ($jar, User $user) {
	$r = harness_request('GET', '/profile/account_edit', array('jar' => $jar, 'accept' => null));
	return $r['status'] === 200 && strpos((string)$r['body'], (string)$user->get('usr_email')) !== false;
};

// ---------------------------------------------------------------------------
section('Website: a session ends when the account is marked, and none starts');
$person = make_user('machine_web');
$jar = harness_jar_new();
$csrf = harness_web_login($jar, $person->get('usr_email'), 'TestPassword_machine_web');
check($csrf !== null && $signed_in($jar, $person), 'before: an ordinary account signs in');
$mark($person->key, true);
check(!$signed_in($jar, $person), 'marked: its open session is signed out at the next request');
$jar2 = harness_jar_new();
check(harness_web_login($jar2, $person->get('usr_email'), 'TestPassword_machine_web') === null,
	'marked: the right password no longer signs it in');

$threw = '';
try {
	SessionControl::get_instance()->store_session_variables(new User($person->key, TRUE));
} catch (SystemDisplayablePermanentError $e) {
	$threw = $e->getMessage();
}
check(stripos($threw, 'machine account') !== false, 'store_session_variables() refuses it, saying why', $threw);

$mark($person->key, false);
$jar3 = harness_jar_new();
check(harness_web_login($jar3, $person->get('usr_email'), 'TestPassword_machine_web') !== null,
	'unmarked: it signs in again');

// ---------------------------------------------------------------------------
section('The second-factor notice skips it; the admin alert audience keeps it');
$machine_admin = make_user('machine_admin', 5);
$person_admin = make_user('machine_person_admin', 5);
$mark($machine_admin->key, true);
$missing_ids = array_column(AdminSecondFactorNotice::adminsWithoutSecondFactor(), 'id');
check(!in_array((int)$machine_admin->key, $missing_ids, true), 'a level-5 machine account is not named');
check(in_array((int)$person_admin->key, $missing_ids, true), 'a level-5 person with no factor still is');
$audience = array_map('intval', Notify::audience_ids('admins'));
check(in_array((int)$machine_admin->key, $audience, true), 'the machine account is still in the admins audience');

// ---------------------------------------------------------------------------
section('Log in as user refuses it before writing the audit row');
$super = make_user('machine_super', 10);
$session = SessionControl::get_instance();
$session->set_api_user($super->key, null, 10);
$_SERVER['REQUEST_METHOD'] = 'POST';
$db = DbConnector::get_instance()->get_db_link();
$count_rows = function () use ($db, $machine_admin) {
	$q = $db->prepare("SELECT COUNT(*) FROM rql_request_logs WHERE rql_feature = 'admin' AND rql_action = 'login_as'
		AND rql_note LIKE ?");
	$q->execute(array('%as user #' . (int)$machine_admin->key));
	return (int)$q->fetchColumn();
};
$before = $count_rows();
$res = admin_user_login_as_logic(array('usr_user_id' => $machine_admin->key));
check($res->error !== null && stripos((string)$res->error, 'machine account') !== false, 'it is refused, saying why',
	(string)$res->error);
check($count_rows() === $before, 'and no login_as row is written');

// ---------------------------------------------------------------------------
section('Only a superadmin marks an account, never their own');
$target = make_user('machine_target', 0);
$res = admin_users_edit_logic(array('edit_primary_key_value' => $target->key, 'usr_is_machine' => '1'));
check((new User($target->key, TRUE))->is_machine_account(), 'a superadmin marks another account');
$res = admin_users_edit_logic(array('edit_primary_key_value' => $target->key, 'usr_is_machine' => '0'));
check(!(new User($target->key, TRUE))->is_machine_account(), 'and unmarks it');
$res = admin_users_edit_logic(array('edit_primary_key_value' => $super->key, 'usr_is_machine' => '1'));
check($res->error !== null && !(new User($super->key, TRUE))->is_machine_account(), 'their own account is refused',
	(string)$res->error);
$session->clear_api_user();

$staff = make_user('machine_staff', 8);
$session->set_api_user($staff->key, null, 8);
$refused = false;
try {
	admin_users_edit_logic(array('edit_primary_key_value' => $target->key, 'usr_is_machine' => '1'));
} catch (SystemAuthenticationError $e) {
	$refused = true;
}
check($refused && !(new User($target->key, TRUE))->is_machine_account(), 'a level-8 admin is refused, not ignored');
$session->clear_api_user();
unset($_SERVER['REQUEST_METHOD']);

harness_finish();
