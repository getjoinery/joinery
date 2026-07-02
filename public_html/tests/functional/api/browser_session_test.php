<?php
/**
 * Browser-session credential functional suite.
 *
 * Proves the second /api/v1 credential end to end against a running site:
 * a real web login (cookie jar), the joinery-api-csrf meta tag scraped from a
 * rendered page, and API calls authenticated by cookie + X-Joinery-Csrf.
 *
 *   1. Logged-in cookie + valid token → sessioned endpoints work as the user
 *      (auth/session, form face, and a mutating action verified in the DB).
 *   2. Missing or wrong token → 403 even with a valid cookie.
 *   3. Key headers take precedence when both credentials are present.
 *   4. The management API rejects browser sessions (machine-key gate),
 *      even for a permission-10 user.
 *   5. auth/logout is a website concern for browser sessions (403).
 *   6. Anonymous keyless requests (no cookie) fail exactly as before (400).
 *
 * Usage: php browser_session_test.php [base_url] [origin_ip]
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/api_test_harness.php');

harness_boot($argv);

/**
 * Curl with a cookie jar. Form-encodes $body when $form, else JSON. Does not
 * follow redirects (the web login answers 302 on success). Returns
 * ['status', 'json', 'raw'].
 */
function cookie_request($method, $path, $cookie_file, $headers = array(), $body = null, $form = false) {
	global $BASE_URL, $ORIGIN_IP;
	$ch = curl_init($BASE_URL . $path);
	if ($body !== null) {
		if ($form) {
			$payload = http_build_query($body);
			$headers[] = 'Content-Type: application/x-www-form-urlencoded';
		} else {
			$payload = json_encode($body);
			$headers[] = 'Content-Type: application/json';
		}
		curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
	}
	$host = parse_url($BASE_URL, PHP_URL_HOST);
	curl_setopt_array($ch, array(
		CURLOPT_CUSTOMREQUEST => strtoupper($method),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HTTPHEADER => $headers,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_COOKIEJAR => $cookie_file,
		CURLOPT_COOKIEFILE => $cookie_file,
		CURLOPT_RESOLVE => array($host . ':443:' . $ORIGIN_IP, $host . ':80:' . $ORIGIN_IP),
	));
	$raw = curl_exec($ch);
	$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	curl_close($ch);
	return array('status' => $status, 'json' => json_decode((string)$raw, true), 'raw' => (string)$raw);
}

/**
 * Web-login a user (make_user() password convention) into a fresh cookie jar,
 * then scrape the joinery-api-csrf meta tag from a rendered page.
 * Returns [$cookie_file, $token]; either check may fail the suite.
 */
function web_login_and_scrape_token($user, $suffix) {
	// The WEB login enforces the activation_required_login gate (the API login
	// does not) — activate the account so the form login succeeds.
	$user->set('usr_is_activated', TRUE);
	$user->save();

	$cookie_file = tempnam(sys_get_temp_dir(), 'jybrw');
	$r = cookie_request('POST', '/login', $cookie_file, array(), array(
		'email' => $user->get('usr_email'),
		'password' => 'TestPassword_' . $suffix,
	), true);
	check(in_array($r['status'], array(301, 302, 303)), "web login for $suffix redirects (logged in)", 'status ' . $r['status']);

	$page = cookie_request('GET', '/', $cookie_file);
	$token = null;
	if (preg_match('/<meta name="joinery-api-csrf" content="([0-9a-f]{64})"/', $page['raw'], $m)) {
		$token = $m[1];
	}
	check($token !== null, "joinery-api-csrf meta tag present on a rendered page for $suffix");
	return array($cookie_file, $token);
}

function csrf_header($token) {
	return array('X-Joinery-Csrf: ' . $token);
}

$cookie_files = array();

try {

	section('Setup: users and web sessions');
	// Suffixes must be unique ACROSS runs, not just within one: teardown can
	// leave soft-deleted users behind (permanent_delete FK-sweep failures), and
	// the web login's GetByEmail would match such a ghost — same email, same
	// deterministic password, but unactivated → login fails.
	$run_id = substr(md5(uniqid('', true)), 0, 6);
	$member = make_user('BrwA' . $run_id);
	$other  = make_user('BrwB' . $run_id);
	$admin  = make_user('BrwC' . $run_id, 10);
	list($member_jar, $member_token) = web_login_and_scrape_token($member, 'BrwA' . $run_id);
	list($admin_jar, $admin_token)   = web_login_and_scrape_token($admin, 'BrwC' . $run_id);
	$cookie_files = array($member_jar, $admin_jar);

	section('1. Cookie + valid token authenticates as the session user');
	$r = cookie_request('GET', '/api/v1/auth/session', $member_jar, csrf_header($member_token));
	check($r['status'] === 200, 'auth/session returns 200', $r['raw']);
	check((int)($r['json']['data']['user_id'] ?? 0) === (int)$member->key,
		'auth/session identifies the web-session user', $r['raw']);

	$r = cookie_request('GET', '/api/v1/form/account_edit', $member_jar, csrf_header($member_token));
	check($r['status'] === 200, 'sessioned form face (GET form/account_edit) returns 200', $r['raw']);

	$r = cookie_request('POST', '/api/v1/action/account_edit', $member_jar, csrf_header($member_token), array(
		'usr_first_name' => 'Browserized',
		'usr_last_name' => 'Member',
		'usr_timezone' => 'America/New_York',
	));
	check($r['status'] === 200, 'sessioned action (POST action/account_edit) returns 200', $r['raw']);
	$member->load();
	check($member->get('usr_first_name') === 'Browserized',
		'action executed AS the session user (DB shows the new first name)',
		'got: ' . $member->get('usr_first_name'));

	section('2. Missing or wrong token is refused despite a valid cookie');
	$r = cookie_request('GET', '/api/v1/auth/session', $member_jar);
	check($r['status'] === 403, 'no X-Joinery-Csrf header → 403', 'status ' . $r['status'] . ' ' . $r['raw']);
	$r = cookie_request('GET', '/api/v1/auth/session', $member_jar, csrf_header(str_repeat('0', 64)));
	check($r['status'] === 403, 'wrong X-Joinery-Csrf token → 403', 'status ' . $r['status'] . ' ' . $r['raw']);

	section('3. Key headers take precedence over the browser session');
	$other_key = make_machine_key($other->key, 'BrwPrecedence');
	$headers = array_merge(
		key_headers($other_key['api_key']->get('apk_public_key'), $other_key['secret_key']),
		csrf_header($member_token)
	);
	$r = cookie_request('GET', '/api/v1/auth/session', $member_jar, $headers);
	check($r['status'] === 200, 'both credentials present → request authenticates', $r['raw']);
	check((int)($r['json']['data']['user_id'] ?? 0) === (int)$other->key,
		'the KEY user wins, not the cookie user', $r['raw']);

	section('4. Management API rejects browser sessions (even permission 10)');
	$r = cookie_request('GET', '/api/v1/management', $admin_jar, csrf_header($admin_token));
	check($r['status'] === 403, 'superadmin browser session → 403 on management discovery',
		'status ' . $r['status'] . ' ' . $r['raw']);
	check(strpos($r['raw'], 'machine key') !== false,
		'403 names the machine-key requirement', $r['raw']);

	section('5. auth/logout is a website concern for browser sessions');
	$r = cookie_request('POST', '/api/v1/auth/logout', $member_jar, csrf_header($member_token), array());
	check($r['status'] === 403, 'browser session logout via API → 403', 'status ' . $r['status'] . ' ' . $r['raw']);
	// JSON-encoded body escapes slashes, so match on the surrounding phrase.
	check(strpos($r['raw'], 'sign out on the website') !== false, '403 points at the website /logout', $r['raw']);
	// And the session is still alive — logout must not have side effects.
	$r = cookie_request('GET', '/api/v1/auth/session', $member_jar, csrf_header($member_token));
	check($r['status'] === 200, 'web session still authenticates after the refused logout', $r['raw']);

	section('6. Anonymous keyless requests are unchanged');
	$r = api_request('GET', '/api/v1/auth/session');
	check($r['status'] === 400, 'no cookie, no keys → 400 (pre-existing shape)', 'status ' . $r['status'] . ' ' . $r['raw']);
	check(strpos($r['raw'], 'keys not present') !== false,
		'error body unchanged for anonymous callers', $r['raw']);

} finally {
	harness_teardown_data();
	foreach ($cookie_files as $f) {
		if ($f && file_exists($f)) unlink($f);
	}
}

harness_finish();
