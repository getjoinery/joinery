<?php
/**
 * An API action runs at the level its request was authorized at
 * (ApiLogicEndpoint::actingPermission(), spec declared_permissions B3, B34).
 *
 * The browser credential acts at the SESSION's level, which can be below the
 * user's stored one: a login-as session, an IP-change-zeroed session, or a
 * session signed in before the account was raised. B34: the action face
 * simulated its session with the stored level instead, so such a session ran
 * actions as the raised account. Pinned here with the last case: a member signs
 * in, the account is raised to 10 in the database, and the member's session
 * edits another user's phone number through the action. A key for the same
 * user acts at its stored level and may.
 *
 * Run: php tests/functional/api/api_acting_level_test.php [base_url] [origin_ip]
 *
 * @version 1.0.0
 */

/** @joinery-test
 * name: api_acting_level
 * tier: db
 * env: dev-only
 * needs: []
 */
require_once(__DIR__ . '/api_test_harness.php');
api_test_boot($argv);

require_once(PathHelper::getIncludePath('data/phone_numbers_class.php'));

$suffix = strtoupper(LibraryFunctions::random_string(6));

/** The stored value of a phone number row. */
function aal_number($id) {
	$q = DbConnector::get_instance()->get_db_link()->prepare(
		'SELECT phn_phone_number FROM phn_phone_numbers WHERE phn_phone_number_id = ?');
	$q->execute(array($id));
	return (string)$q->fetchColumn();
}

try {
	section('Setup: a member who signs in, then is raised to 10');
	$actor = make_user($suffix . 'AC', 0);
	$other = make_user($suffix . 'OT', 0);
	$phone = new PhoneNumber(NULL);
	$phone->set('phn_usr_user_id', $other->key);
	$phone->set('phn_phone_number', '5550000001');
	$phone->save();
	harness_register_row('phn_phone_numbers', 'phn_phone_number_id', $phone->key);

	$jar = harness_jar_new('aal');
	$csrf = harness_web_login($jar, $actor->get('usr_email'), 'TestPassword_' . $suffix . 'AC');
	check($csrf !== null, 'the member signs in over the web form');

	DbConnector::get_instance()->get_db_link()
		->prepare('UPDATE usr_users SET usr_permission = 10 WHERE usr_user_id = ?')
		->execute(array($actor->key));

	// The session keeps the level it signed in at: an admin page still refuses it.
	$admin = harness_request('GET', '/admin/admin_users', array('jar' => $jar));
	check($admin['status'] !== 200,
		'precondition: the session is still a member (the admin page does not open)', 'status ' . $admin['status']);

	section('The browser session acts at its own level');
	$r = harness_request('POST', '/api/v1/action/phone_numbers_edit', array(
		'jar' => $jar, 'headers' => harness_csrf_header($csrf),
		'body' => array('edit_primary_key_value' => $phone->key, 'phn_phone_number' => '5550000002'),
	));
	check($r['status'] >= 400 && aal_number($phone->key) === '5550000001',
		'its session may not edit another user\'s phone number, whatever the account was raised to',
		'status ' . $r['status'] . ', number ' . aal_number($phone->key));

	section('A key acts at the stored level');
	$k = make_machine_key($actor->key, 'aal-' . $suffix);
	$r = api_request('POST', '/api/v1/action/phone_numbers_edit',
		key_headers($k['api_key']->get('apk_public_key'), $k['secret_key']),
		array('edit_primary_key_value' => $phone->key, 'phn_phone_number' => '5550000003'));
	check($r['status'] === 200 && aal_number($phone->key) === '5550000003',
		'the same user\'s key, at 10, may (so the refusal above is the level, not the action)',
		'status ' . $r['status'] . ', number ' . aal_number($phone->key));
} catch (Throwable $e) {
	check(false, 'suite ran to completion — ' . get_class($e) . ': ' . $e->getMessage());
}

harness_finish();
