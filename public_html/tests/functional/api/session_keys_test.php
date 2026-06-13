<?php
/**
 * User Session API Keys — functional test suite
 *
 * Covers the spec's test list (specs/implemented/user_session_api_keys.md):
 *   - Management boundary (LOAD-BEARING): a session key owned by a
 *     permission-10 user gets 403 on management/* endpoints; a machine key
 *     owned by the same user still works. This test is the enforcement
 *     mechanism for the single-table design — never delete it.
 *   - Model: CreateSessionKey row shape; SHA-256 verify path; machine-key
 *     verify path unchanged; expiry honored.
 *   - Login happy path returns a working pair; wrong password counts toward
 *     the auth rate limit and locks out at the threshold.
 *   - Session-key requests reach CRUD and action endpoints as the right user;
 *     object write ownership enforced across users.
 *   - Expired and revoked keys get 401; logout revokes only the presented key
 *     and refuses machine keys; password change revokes all session keys and
 *     no machine keys (and a silent hash upgrade revokes nothing).
 *   - Sessioned action smoke test: account_edit via session key persists.
 *   - Version handshake: below-minimum client_version gets 426 on every
 *     endpoint including auth/login; requests with no client headers are
 *     unaffected.
 *
 * USAGE (CLI only — the rate-limit test deliberately locks out the caller's
 * IP for the api_auth feature and then cleans up its own log rows):
 *   php tests/functional/api/session_keys_test.php [base_url] [origin_ip]
 *
 * Default base_url: https://dev.getjoinery.com, pinned to the origin IP so
 * requests bypass Cloudflare (stable REMOTE_ADDR for the rate-limit test).
 * Custom headers are sent in hyphen form (public-key) because Apache→FPM
 * drops header names containing underscores; the API accepts both forms.
 *
 * Creates its own users and keys and removes them afterwards. Requires the
 * apk_type / apk_last_used_time / rql_api_key_type columns (run
 * update_database after deploying the feature).
 */


require_once(__DIR__ . '/api_test_harness.php');
harness_boot($argv);

$settings = Globalvars::get_instance();
$saved_min_versions = null;

try {
	$suffix = strtoupper(LibraryFunctions::random_string(6));
	echo "Base URL: $BASE_URL\nTest suffix: $suffix\n";

	// ------------------------------------------------------------------
	section('Setup');
	$user_a = make_user($suffix . 'A');
	$user_b = make_user($suffix . 'B');
	$superadmin = make_user($suffix . 'S', 10);
	$password_a = 'TestPassword_' . $suffix . 'A';
	$password_s = 'TestPassword_' . $suffix . 'S';
	echo "  Created users " . $user_a->key . ", " . $user_b->key . ", superadmin " . $superadmin->key . "\n";

	$machine_a = make_machine_key($user_a->key, 'mkey-' . $suffix);
	$machine_s = make_machine_key($superadmin->key, 'mkeyS-' . $suffix);

	// ------------------------------------------------------------------
	section('Model: CreateSessionKey row shape');
	$minted = ApiKey::CreateSessionKey($user_a->key, "Jeremy's iPhone " . $suffix);
	$skey = $minted['api_key'];
	harness_register_key_id($skey->key);
	check($skey->get('apk_type') === ApiKey::TYPE_SESSION, 'type is session');
	check($skey->is_session(), 'is_session() true');
	check((int)$skey->get('apk_permission') === 4, 'permission is 4');
	check((bool)$skey->get('apk_is_active'), 'active');
	check(strpos($skey->get('apk_public_key'), 'sess_') === 0, 'public key prefixed sess_');
	check(strlen($minted['secret_key']) === 64, 'secret plaintext is 64 hex chars (256-bit)');
	check($skey->get('apk_secret_key') === hash('sha256', $minted['secret_key']), 'stored secret is SHA-256 of plaintext');
	check($skey->get('apk_ip_restriction') === NULL, 'no IP restriction');
	check($skey->get('apk_start_time') === NULL, 'no start time');
	$lifetime_days = (int)($settings->get_setting('api_session_key_lifetime_days') ?: 365);
	$expected_expiry_day = LibraryFunctions::time_shift(gmdate('Y-m-d H:i:s'), $lifetime_days . ' days', 'Y-m-d');
	check(substr($skey->get('apk_expires_time'), 0, 10) === $expected_expiry_day,
		'expiry honors api_session_key_lifetime_days', $skey->get('apk_expires_time'));
	check($skey->get('apk_name') === substr("Jeremy's iPhone " . $suffix, 0, 32), 'device label stored in apk_name');

	// ------------------------------------------------------------------
	section('Model: secret verification paths');
	check($skey->check_secret_key($minted['secret_key']), 'session key verifies via SHA-256');
	check(!$skey->check_secret_key('wrong-secret'), 'session key rejects wrong secret');
	check($machine_a['api_key']->check_secret_key($machine_a['secret_key']), 'machine key verifies via phpass (unchanged)');
	check(!$machine_a['api_key']->check_secret_key($minted['secret_key']), 'machine key rejects wrong secret');

	// ------------------------------------------------------------------
	section('auth/login happy path');
	$r = api_request('POST', '/api/v1/auth/login', array(), array(
		'email' => $user_a->get('usr_email'),
		'password' => $password_a,
		'device_label' => 'Test Phone ' . $suffix,
	));
	check($r['status'] === 200, 'login returns 200', $r['raw']);
	$login_data = $r['json']['data'] ?? array();
	check(!empty($login_data['public_key']) && strpos($login_data['public_key'], 'sess_') === 0, 'login returns public_key');
	check(!empty($login_data['secret_key']), 'login returns secret_key plaintext');
	check(!empty($login_data['expires_time']), 'login returns expires_time');
	check(($login_data['user']['user_id'] ?? null) == $user_a->key, 'login returns user summary with user_id');
	check(array_key_exists('tier', $login_data['user'] ?? array()), 'user summary includes tier');
	$phone_pub = $login_data['public_key'] ?? '';
	$phone_sec = $login_data['secret_key'] ?? '';
	$phone_key_row = ApiKey::GetByColumn('apk_public_key', $phone_pub);
	if ($phone_key_row && $phone_key_row->key) {
		harness_register_key_id($phone_key_row->key);
	}

	// ------------------------------------------------------------------
	section('auth/session round-trip (acceptance #1)');
	$r = api_request('GET', '/api/v1/auth/session', key_headers($phone_pub, $phone_sec));
	check($r['status'] === 200, 'auth/session returns 200', $r['raw']);
	check(($r['json']['data']['user_id'] ?? null) == $user_a->key, 'session reports the logged-in user');
	check(($r['json']['data']['email'] ?? null) === $user_a->get('usr_email'), 'session reports email');

	$r = api_request('GET', '/api/v1/auth/session', key_headers($machine_a['api_key']->get('apk_public_key'), $machine_a['secret_key']));
	check($r['status'] === 200, 'auth/session works for machine keys too');

	// ------------------------------------------------------------------
	section('Bad login');
	$r = api_request('POST', '/api/v1/auth/login', array(), array(
		'email' => $user_a->get('usr_email'),
		'password' => 'definitely-wrong',
	));
	check($r['status'] === 401, 'wrong password returns 401', $r['raw']);
	check(($r['json']['errortype'] ?? '') === 'AuthenticationError', 'wrong password errortype AuthenticationError');

	// ------------------------------------------------------------------
	section('CRUD via session key (acceptance #2)');
	$r = api_request('GET', '/api/v1/User/' . $user_a->key, key_headers($phone_pub, $phone_sec));
	check($r['status'] === 200, 'GET own User returns 200', $r['raw']);
	check(($r['json']['data']['usr_user_id'] ?? null) == $user_a->key, 'GET own User returns own row');

	// Object-level write authorization across users (authenticate_write)
	$r = api_request('PUT', '/api/v1/User/' . $user_b->key . '?usr_first_name=Hacked', key_headers($phone_pub, $phone_sec));
	check($r['status'] >= 400, 'PUT another user is rejected', $r['raw']);
	$user_b_check = new User($user_b->key, TRUE);
	check($user_b_check->get('usr_first_name') !== 'Hacked', 'other user row unchanged');

	// ------------------------------------------------------------------
	section('Sessioned action via session key: account_edit (acceptance #2)');
	// account_edit strips non-letter characters from names — submit letters only
	$r = api_request('POST', '/api/v1/action/account_edit', key_headers($phone_pub, $phone_sec), array(
		'usr_first_name' => 'RenamedByPhone',
		'usr_last_name' => $user_a->get('usr_last_name'),
		'usr_email' => $user_a->get('usr_email'),
		'usr_timezone' => 'America/Chicago',
		'btn_submit' => 'Save',
	));
	check($r['status'] === 200, 'account_edit via session key returns 200', $r['raw']);
	$user_a_check = new User($user_a->key, TRUE);
	check($user_a_check->get('usr_first_name') === 'RenamedByPhone', 'account_edit persisted as the right user',
		'got: ' . $user_a_check->get('usr_first_name'));

	// ------------------------------------------------------------------
	section('Sessionless action dispatch (no key headers)');
	$r = api_request('POST', '/api/v1/action/password_reset_1', array(), array(
		'usr_email' => 'nonexistent_' . strtolower($suffix) . '@getjoinery.com',
	));
	check(($r['json']['errortype'] ?? '') !== 'AuthenticationError',
		'password_reset_1 without keys is not rejected for missing keys', $r['raw']);
	check(in_array($r['status'], array(200, 422)), 'password_reset_1 without keys reaches the logic layer', $r['raw']);

	// ------------------------------------------------------------------
	section('Logout (acceptance #4 surface)');
	// Mint a second phone session; logout must revoke only the presented key.
	$r = api_request('POST', '/api/v1/auth/login', array(), array(
		'email' => $user_a->get('usr_email'),
		'password' => $password_a,
		'device_label' => 'Second Phone ' . $suffix,
	));
	$phone2_pub = $r['json']['data']['public_key'] ?? '';
	$phone2_sec = $r['json']['data']['secret_key'] ?? '';
	$phone2_row = ApiKey::GetByColumn('apk_public_key', $phone2_pub);
	if ($phone2_row && $phone2_row->key) {
		harness_register_key_id($phone2_row->key);
	}

	$r = api_request('POST', '/api/v1/auth/logout', key_headers($machine_a['api_key']->get('apk_public_key'), $machine_a['secret_key']));
	check($r['status'] === 403, 'machine key on logout gets 403', $r['raw']);

	$r = api_request('POST', '/api/v1/auth/logout', key_headers($phone2_pub, $phone2_sec));
	check($r['status'] === 200, 'session key logout returns 200', $r['raw']);

	$r = api_request('GET', '/api/v1/auth/session', key_headers($phone2_pub, $phone2_sec));
	check($r['status'] === 401, 'revoked (logged-out) key gets 401', $r['raw']);

	$r = api_request('GET', '/api/v1/auth/session', key_headers($phone_pub, $phone_sec));
	check($r['status'] === 200, 'other session key still works after logout of sibling');

	// ------------------------------------------------------------------
	section('Expired key gets 401');
	$expiring = ApiKey::CreateSessionKey($user_a->key, 'Expiring ' . $suffix);
	harness_register_key_id($expiring['api_key']->key);
	$expiring['api_key']->set('apk_expires_time', LibraryFunctions::time_shift(gmdate('Y-m-d H:i:s'), '-1 hour', 'Y-m-d H:i:s'));
	$expiring['api_key']->save();
	$r = api_request('GET', '/api/v1/auth/session',
		key_headers($expiring['api_key']->get('apk_public_key'), $expiring['secret_key']));
	check($r['status'] === 401, 'expired session key gets 401', $r['raw']);

	// ------------------------------------------------------------------
	section('Management boundary (LOAD-BEARING — acceptance #6)');
	// Superadmin logs in on a "phone" — that session key must never reach the
	// management API, while the same user's machine key still works.
	$r = api_request('POST', '/api/v1/auth/login', array(), array(
		'email' => $superadmin->get('usr_email'),
		'password' => $password_s,
		'device_label' => 'Superadmin Phone ' . $suffix,
	));
	check($r['status'] === 200, 'superadmin can log in', $r['raw']);
	$sa_pub = $r['json']['data']['public_key'] ?? '';
	$sa_sec = $r['json']['data']['secret_key'] ?? '';
	$sa_row = ApiKey::GetByColumn('apk_public_key', $sa_pub);
	if ($sa_row && $sa_row->key) {
		harness_register_key_id($sa_row->key);
	}

	foreach (array('/api/v1/management', '/api/v1/management/stats') as $mgmt_path) {
		$r = api_request('GET', $mgmt_path, key_headers($sa_pub, $sa_sec));
		check($r['status'] === 403, "superadmin SESSION key gets 403 on $mgmt_path", $r['raw']);
	}
	$r = api_request('GET', '/api/v1/management', key_headers($machine_s['api_key']->get('apk_public_key'), $machine_s['secret_key']));
	check($r['status'] === 200, 'superadmin MACHINE key still reaches management discovery', $r['raw']);

	// ------------------------------------------------------------------
	section('Capability boundaries — non-monotonic apk_permission (ApiAuth::authorize)');
	// apk_permission is NOT a linear scale: permission 1 is read-only and
	// permission 2 is write-only. These pins catch a regression in the
	// capability mapping that a "minimum level" refactor would silently cause.
	$readonly_key  = make_machine_key($user_a->key, 'ro-' . $suffix, 1);  // read-only
	$writeonly_key = make_machine_key($user_a->key, 'wo-' . $suffix, 2);  // write-only
	$ro_h = key_headers($readonly_key['api_key']->get('apk_public_key'), $readonly_key['secret_key']);
	$wo_h = key_headers($writeonly_key['api_key']->get('apk_public_key'), $writeonly_key['secret_key']);

	// Read capability: permission 1 may read; permission 2 (write-only) may NOT.
	$r = api_request('GET', '/api/v1/User/' . $user_a->key, $ro_h);
	check($r['status'] === 200, 'read-only key (perm 1) may GET own object', $r['raw']);
	$r = api_request('GET', '/api/v1/User/' . $user_a->key, $wo_h);
	check($r['status'] === 403, 'write-only key (perm 2) is denied reads (non-monotonic)', $r['raw']);
	check(($r['json']['errortype'] ?? '') === 'AuthenticationError', 'write-only read denial is AuthenticationError');

	// Write capability: permission 2 may run an action; permission 1 may NOT.
	$r = api_request('POST', '/api/v1/action/account_edit', $ro_h, array(
		'usr_first_name' => 'ReadOnlyAttempt', 'usr_last_name' => $user_a->get('usr_last_name'),
		'usr_email' => $user_a->get('usr_email'), 'usr_timezone' => 'America/Chicago', 'btn_submit' => 'Save',
	));
	check($r['status'] === 403, 'read-only key (perm 1) is denied actions (write capability)', $r['raw']);
	$r = api_request('POST', '/api/v1/action/account_edit', $wo_h, array(
		'usr_first_name' => 'WriteOnlyOk', 'usr_last_name' => $user_a->get('usr_last_name'),
		'usr_email' => $user_a->get('usr_email'), 'usr_timezone' => 'America/Chicago', 'btn_submit' => 'Save',
	));
	check($r['status'] === 200, 'write-only key (perm 2) may run an action (write capability)', $r['raw']);

	// ------------------------------------------------------------------
	section('Password change revokes session keys, not machine keys (acceptance #3)');
	$user_a_fresh = new User($user_a->key, TRUE);
	$user_a_fresh->set('usr_password', User::GeneratePassword('NewPassword_' . $suffix));
	$user_a_fresh->save();

	$phone_row_check = new ApiKey($phone_key_row->key, TRUE);
	check($phone_row_check->get('apk_delete_time') !== NULL, 'session key soft-deleted on password change');
	$machine_row_check = new ApiKey($machine_a['api_key']->key, TRUE);
	check($machine_row_check->get('apk_delete_time') === NULL, 'machine key survives password change');
	$r = api_request('GET', '/api/v1/auth/session', key_headers($phone_pub, $phone_sec));
	check($r['status'] === 401, 'revoked-by-password-change key gets 401 over HTTP', $r['raw']);
	$r = api_request('GET', '/api/v1/auth/session', key_headers($machine_a['api_key']->get('apk_public_key'), $machine_a['secret_key']));
	check($r['status'] === 200, 'machine key still authenticates after password change');

	// ------------------------------------------------------------------
	section('Silent hash upgrade does NOT revoke session keys');
	require_once(PathHelper::getIncludePath('includes/PasswordHash.php'));
	$hasher = new PasswordHash(8, TRUE);
	$legacy_plain = 'LegacyPassword_' . $suffix;
	$user_b_fresh = new User($user_b->key, TRUE);
	$user_b_fresh->set('usr_password', $hasher->HashPassword($legacy_plain));
	$user_b_fresh->save();

	$b_session = ApiKey::CreateSessionKey($user_b->key, 'B Phone ' . $suffix);
	harness_register_key_id($b_session['api_key']->key);

	$user_b_login = new User($user_b->key, TRUE);
	check($user_b_login->check_password($legacy_plain), 'legacy password verifies (triggers rehash)');
	$user_b_after = new User($user_b->key, TRUE);
	check(strpos($user_b_after->get('usr_password'), '$argon2id$') === 0, 'hash silently upgraded to Argon2id');
	$b_session_check = new ApiKey($b_session['api_key']->key, TRUE);
	check($b_session_check->get('apk_delete_time') === NULL, 'session key SURVIVES silent hash upgrade');

	// ------------------------------------------------------------------
	section('Client version handshake (426 UpgradeRequired)');
	$saved_min_versions = get_setting_raw('api_min_client_versions');
	set_setting_raw('api_min_client_versions', json_encode(array('joinery-test-app' => '2.0.0')));

	$version_headers = array('client-app: joinery-test-app', 'client-version: 1.0.0');
	$r = api_request('POST', '/api/v1/auth/login', $version_headers, array(
		'email' => $user_b_after->get('usr_email'), 'password' => $legacy_plain,
	));
	check($r['status'] === 426, 'below-minimum client gets 426 on auth/login', $r['raw']);
	check(($r['json']['errortype'] ?? '') === 'UpgradeRequired', 'errortype is UpgradeRequired');

	$r = api_request('GET', '/api/v1/auth/session',
		array_merge(key_headers($machine_s['api_key']->get('apk_public_key'), $machine_s['secret_key']), $version_headers));
	check($r['status'] === 426, 'below-minimum client gets 426 on authenticated endpoints too', $r['raw']);

	$r = api_request('GET', '/api/v1/auth/session',
		array_merge(key_headers($machine_s['api_key']->get('apk_public_key'), $machine_s['secret_key']),
			array('client-app: joinery-test-app', 'client-version: 2.0.0')));
	check($r['status'] === 200, 'at-minimum client version passes');

	$r = api_request('GET', '/api/v1/auth/session',
		key_headers($machine_s['api_key']->get('apk_public_key'), $machine_s['secret_key']));
	check($r['status'] === 200, 'request with no client headers is unaffected by the setting');

	set_setting_raw('api_min_client_versions', $saved_min_versions === null ? '{}' : $saved_min_versions);
	$saved_min_versions = null;

	// ------------------------------------------------------------------
	section('Failed-auth rate limit lockout (acceptance #5 — runs LAST)');
	$auth_limit = (int)($settings->get_setting('api_auth_rate_limit_requests') ?: 10);
	$locked_out = false;
	for ($i = 0; $i < $auth_limit + 2; $i++) {
		$r = api_request('POST', '/api/v1/auth/login', array(), array(
			'email' => $user_b_after->get('usr_email'),
			'password' => 'wrong-' . $i,
		));
		if ($r['status'] === 429) {
			$locked_out = true;
			break;
		}
	}
	check($locked_out, "repeated bad logins lock the IP out (429) within " . ($auth_limit + 2) . " attempts");

} catch (Exception $e) {
	$failed++;
	echo "\nEXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
} finally {
	section('Cleanup');
	if ($saved_min_versions !== null) {
		set_setting_raw('api_min_client_versions', $saved_min_versions);
	}

	$db = DbConnector::get_instance()->get_db_link();

	// Remove the failed-auth log rows this test created so the lockout does
	// not bleed into subsequent runs or other API use from this IP.
	$q = $db->prepare("DELETE FROM rql_request_logs
		WHERE rql_feature = 'api_auth' AND rql_was_success = FALSE AND rql_create_time >= ?");
	$q->execute([$TEST_START_UTC]);
	echo "  Removed " . $q->rowCount() . " failed-auth log rows from this run\n";

	harness_teardown_data();
}

harness_finish();
