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

if (php_sapi_name() !== 'cli') {
	echo "This test must be run from the command line.\n";
	exit(1);
}

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/api_keys_class.php'));

$BASE_URL = isset($argv[1]) ? rtrim($argv[1], '/') : 'https://dev.getjoinery.com';
$ORIGIN_IP = isset($argv[2]) ? $argv[2] : '69.164.209.253';
$TEST_START_UTC = gmdate('Y-m-d H:i:s');

$passed = 0;
$failed = 0;

function check($condition, $label, $detail = '') {
	global $passed, $failed;
	if ($condition) {
		$passed++;
		echo "  PASS: $label\n";
	} else {
		$failed++;
		echo "  FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n";
	}
	return (bool)$condition;
}

function section($title) {
	echo "\n== $title ==\n";
}

/**
 * HTTP helper. Returns ['status' => int, 'json' => array|null, 'raw' => string].
 * $body as array → JSON body. $headers as ['Name: value', ...].
 */
function api_request($method, $path, $headers = array(), $body = null) {
	global $BASE_URL, $ORIGIN_IP;
	$ch = curl_init($BASE_URL . $path);
	$headers[] = 'Accept: application/json';
	if ($body !== null) {
		$payload = json_encode($body);
		$headers[] = 'Content-Type: application/json';
		curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
	}
	$host = parse_url($BASE_URL, PHP_URL_HOST);
	curl_setopt_array($ch, array(
		CURLOPT_CUSTOMREQUEST => strtoupper($method),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HTTPHEADER => $headers,
		CURLOPT_TIMEOUT => 30,
		// Pin DNS to the origin so requests bypass Cloudflare
		CURLOPT_RESOLVE => array($host . ':443:' . $ORIGIN_IP, $host . ':80:' . $ORIGIN_IP),
	));
	$raw = curl_exec($ch);
	$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	curl_close($ch);
	return array('status' => $status, 'json' => json_decode((string)$raw, true), 'raw' => (string)$raw);
}

function key_headers($public_key, $secret_key) {
	// Hyphen form survives Apache→FPM; the API normalizes both spellings
	return array('public-key: ' . $public_key, 'secret-key: ' . $secret_key);
}

function make_user($suffix, $permission = 0) {
	$user = new User(NULL);
	$user->set('usr_first_name', 'SessionKeyTest');
	$user->set('usr_last_name', 'User' . $suffix);
	$user->set('usr_email', 'session_key_test_' . strtolower($suffix) . '@getjoinery.com');
	$user->set('usr_password', User::GeneratePassword('TestPassword_' . $suffix));
	$user->set('usr_permission', $permission);
	$user->set('usr_terms_accepted_time', gmdate('Y-m-d H:i:s'));
	$user->save();
	$user->load();
	return $user;
}

function make_machine_key($user_id, $name) {
	$secret_plaintext = 'secret_' . LibraryFunctions::random_string(16);
	$key = new ApiKey(NULL);
	$key->set('apk_usr_user_id', $user_id);
	$key->set('apk_name', $name);
	$key->set('apk_public_key', 'public_' . LibraryFunctions::random_string(16));
	$key->set('apk_secret_key', ApiKey::GenerateKey($secret_plaintext));
	$key->set('apk_type', ApiKey::TYPE_MACHINE);
	$key->set('apk_permission', 4);
	$key->set('apk_is_active', TRUE);
	$key->save();
	$key->load();
	return array('api_key' => $key, 'secret_key' => $secret_plaintext);
}

function get_setting_raw($name) {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare("SELECT stg_value FROM stg_settings WHERE stg_name = ?");
	$q->execute([$name]);
	$row = $q->fetch(PDO::FETCH_ASSOC);
	return $row ? $row['stg_value'] : null;
}

function set_setting_raw($name, $value) {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare("UPDATE stg_settings SET stg_value = ? WHERE stg_name = ?");
	$q->execute([$value, $name]);
}

$settings = Globalvars::get_instance();
$cleanup_users = array();
$cleanup_key_ids = array();
$saved_min_versions = null;

try {
	$suffix = strtoupper(LibraryFunctions::random_string(6));
	echo "Base URL: $BASE_URL\nTest suffix: $suffix\n";

	// ------------------------------------------------------------------
	section('Setup');
	$user_a = make_user($suffix . 'A');           $cleanup_users[] = $user_a;
	$user_b = make_user($suffix . 'B');           $cleanup_users[] = $user_b;
	$superadmin = make_user($suffix . 'S', 10);   $cleanup_users[] = $superadmin;
	$password_a = 'TestPassword_' . $suffix . 'A';
	$password_s = 'TestPassword_' . $suffix . 'S';
	echo "  Created users " . $user_a->key . ", " . $user_b->key . ", superadmin " . $superadmin->key . "\n";

	$machine_a = make_machine_key($user_a->key, 'mkey-' . $suffix);
	$cleanup_key_ids[] = $machine_a['api_key']->key;
	$machine_s = make_machine_key($superadmin->key, 'mkeyS-' . $suffix);
	$cleanup_key_ids[] = $machine_s['api_key']->key;

	// ------------------------------------------------------------------
	section('Model: CreateSessionKey row shape');
	$minted = ApiKey::CreateSessionKey($user_a->key, "Jeremy's iPhone " . $suffix);
	$skey = $minted['api_key'];
	$cleanup_key_ids[] = $skey->key;
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
		$cleanup_key_ids[] = $phone_key_row->key;
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
		$cleanup_key_ids[] = $phone2_row->key;
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
	$cleanup_key_ids[] = $expiring['api_key']->key;
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
		$cleanup_key_ids[] = $sa_row->key;
	}

	foreach (array('/api/v1/management', '/api/v1/management/stats') as $mgmt_path) {
		$r = api_request('GET', $mgmt_path, key_headers($sa_pub, $sa_sec));
		check($r['status'] === 403, "superadmin SESSION key gets 403 on $mgmt_path", $r['raw']);
	}
	$r = api_request('GET', '/api/v1/management', key_headers($machine_s['api_key']->get('apk_public_key'), $machine_s['secret_key']));
	check($r['status'] === 200, 'superadmin MACHINE key still reaches management discovery', $r['raw']);

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
	$cleanup_key_ids[] = $b_session['api_key']->key;

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

	foreach ($cleanup_key_ids as $key_id) {
		try {
			$q = $db->prepare("DELETE FROM apk_api_keys WHERE apk_api_key_id = ?");
			$q->execute([$key_id]);
		} catch (Exception $e) {
			echo "  WARNING: could not delete api key $key_id: " . $e->getMessage() . "\n";
		}
	}
	echo "  Removed " . count($cleanup_key_ids) . " test API keys\n";

	foreach ($cleanup_users as $user) {
		try {
			$user->permanent_delete();
		} catch (Exception $e) {
			// permanent_delete can fail on FK sweep issues; roll back its
			// aborted transaction, then fall back to soft delete so test
			// accounts can never be logged into.
			echo "  WARNING: could not permanently delete user " . $user->key . " (" . $e->getMessage() . "); soft-deleting\n";
			if ($db->inTransaction()) {
				$db->rollBack();
			}
			try {
				$q = $db->prepare("UPDATE usr_users SET usr_delete_time = now() WHERE usr_user_id = ?");
				$q->execute([$user->key]);
			} catch (Exception $e2) {
				echo "  WARNING: soft delete also failed for user " . $user->key . ": " . $e2->getMessage() . "\n";
			}
		}
	}
	echo "  Removed " . count($cleanup_users) . " test users\n";
}

echo "\n================================\n";
echo "PASSED: $passed   FAILED: $failed\n";
exit($failed > 0 ? 1 : 0);
?>
