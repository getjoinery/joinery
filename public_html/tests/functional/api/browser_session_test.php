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

/** @joinery-test
 * name: api_browser_session
 * tier: db
 * env: dev-only
 * needs: []
 */
require_once(__DIR__ . '/api_test_harness.php');

api_test_boot($argv);

/**
 * A cookie-jar request against the browser-session API. $body defaults to JSON.
 */
function browser_request($method, $path, $jar, $headers = array(), $body = null) {
	return harness_request($method, $path, array(
		'jar' => $jar, 'headers' => $headers, 'body' => $body));
}

/**
 * Web-login a user (make_user() password convention) into a fresh jar, then
 * scrape the joinery-api-csrf meta tag from a rendered page.
 * Returns [$jar, $token]; the check may fail the suite.
 */
function web_login_and_scrape_token($user, $suffix) {
	// The WEB login enforces the activation_required_login gate (the API login
	// does not) — activate the account so the form login succeeds.
	$user->set('usr_is_activated', TRUE);
	$user->save();

	$jar = harness_jar_new('jybrw');
	$token = harness_web_login($jar, $user->get('usr_email'), 'TestPassword_' . $suffix);
	check($token !== null,
		"web login for $suffix succeeds and the joinery-api-csrf meta tag is present");
	return array($jar, $token);
}

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

	section('1. Cookie + valid token authenticates as the session user');
	$r = browser_request('GET', '/api/v1/auth/session', $member_jar, harness_csrf_header($member_token));
	check($r['status'] === 200, 'auth/session returns 200', $r['raw']);
	check((int)($r['json']['data']['user_id'] ?? 0) === (int)$member->key,
		'auth/session identifies the web-session user', $r['raw']);

	$r = browser_request('GET', '/api/v1/form/account_edit', $member_jar, harness_csrf_header($member_token));
	check($r['status'] === 200, 'sessioned form face (GET form/account_edit) returns 200', $r['raw']);

	$r = browser_request('POST', '/api/v1/action/account_edit', $member_jar, harness_csrf_header($member_token), array(
		'usr_first_name' => 'Browserized',
		'usr_last_name' => 'Member',
		'usr_timezone' => 'America/New_York',
	));
	check($r['status'] === 200, 'sessioned action (POST action/account_edit) returns 200', $r['raw']);
	$member->load();
	check($member->get('usr_first_name') === 'Browserized',
		'action executed AS the session user (DB shows the new first name)',
		'got: ' . $member->get('usr_first_name'));

	section('1b. Idempotency-Key replays for browser sessions (user-scoped)');
	require_once(PathHelper::getIncludePath('data/api_idempotency_keys_class.php'));
	$idem_key = 'brw-idem-' . $run_id;
	$idem_body = array('usr_first_name' => 'IdemBrw', 'usr_last_name' => 'Member',
		'usr_timezone' => 'America/New_York');
	$idem_headers = array_merge(harness_csrf_header($member_token), array('Idempotency-Key: ' . $idem_key));
	$r1 = browser_request('POST', '/api/v1/action/account_edit', $member_jar, $idem_headers, $idem_body);
	check($r1['status'] === 200, 'first request with Idempotency-Key executes', $r1['raw']);
	$member->load();
	check($member->get('usr_first_name') === 'IdemBrw', 'first request reached the DB');
	// Mutate out-of-band; the retry must replay, not re-execute over this.
	$member->set('usr_first_name', 'MutatedBrw');
	$member->save();
	$r2 = browser_request('POST', '/api/v1/action/account_edit', $member_jar, $idem_headers, $idem_body);
	check($r2['status'] === 200 && $r2['raw'] === $r1['raw'],
		'retry replays the stored response verbatim', $r2['raw']);
	$member->load();
	check($member->get('usr_first_name') === 'MutatedBrw', 'retry did NOT re-execute (DB untouched)');
	$aik_rows = new MultiApiIdempotencyKey(array('credential_scope' => 'user:' . $member->key));
	$aik_rows->load();
	foreach ($aik_rows as $aik) {
		harness_register_row(ApiIdempotencyKey::$tablename, ApiIdempotencyKey::$pkey_column, $aik->key);
	}

	section('2. Missing or wrong token is refused despite a valid cookie');
	$r = browser_request('GET', '/api/v1/auth/session', $member_jar);
	check($r['status'] === 403, 'no X-Joinery-Csrf header → 403', 'status ' . $r['status'] . ' ' . $r['raw']);
	$r = browser_request('GET', '/api/v1/auth/session', $member_jar, harness_csrf_header(str_repeat('0', 64)));
	check($r['status'] === 403, 'wrong X-Joinery-Csrf token → 403', 'status ' . $r['status'] . ' ' . $r['raw']);

	section('3. Key headers take precedence over the browser session');
	$other_key = make_machine_key($other->key, 'BrwPrecedence');
	$headers = array_merge(
		key_headers($other_key['api_key']->get('apk_public_key'), $other_key['secret_key']),
		harness_csrf_header($member_token)
	);
	$r = browser_request('GET', '/api/v1/auth/session', $member_jar, $headers);
	check($r['status'] === 200, 'both credentials present → request authenticates', $r['raw']);
	check((int)($r['json']['data']['user_id'] ?? 0) === (int)$other->key,
		'the KEY user wins, not the cookie user', $r['raw']);

	section('4. Management API rejects browser sessions (even permission 10)');
	$r = browser_request('GET', '/api/v1/management', $admin_jar, harness_csrf_header($admin_token));
	check($r['status'] === 403, 'superadmin browser session → 403 on management discovery',
		'status ' . $r['status'] . ' ' . $r['raw']);
	check(strpos($r['raw'], 'machine key') !== false,
		'403 names the machine-key requirement', $r['raw']);

	section('5. auth/logout is a website concern for browser sessions');
	$r = browser_request('POST', '/api/v1/auth/logout', $member_jar, harness_csrf_header($member_token), array());
	check($r['status'] === 403, 'browser session logout via API → 403', 'status ' . $r['status'] . ' ' . $r['raw']);
	// JSON-encoded body escapes slashes, so match on the surrounding phrase.
	check(strpos($r['raw'], 'sign out on the website') !== false, '403 points at the website /logout', $r['raw']);
	// And the session is still alive — logout must not have side effects.
	$r = browser_request('GET', '/api/v1/auth/session', $member_jar, harness_csrf_header($member_token));
	check($r['status'] === 200, 'web session still authenticates after the refused logout', $r['raw']);

	section('6. Anonymous keyless requests are unchanged');
	$r = api_request('GET', '/api/v1/auth/session');
	check($r['status'] === 400, 'no cookie, no keys → 400 (pre-existing shape)', 'status ' . $r['status'] . ' ' . $r['raw']);
	check(strpos($r['raw'], 'keys not present') !== false,
		'error body unchanged for anonymous callers', $r['raw']);

} finally {
	harness_teardown_data();
}

harness_finish();
