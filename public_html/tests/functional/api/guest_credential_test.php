<?php
/**
 * Anonymous browser credential functional suite
 * (specs/anonymous_browser_credential.md).
 *
 * Proves the guest credential end to end against a running site:
 *
 *   1. An anonymous page view distributes the joinery_api_csrf mirror cookie,
 *      and the anonymous HTML carries no per-visitor CSRF meta tag.
 *   2. Anonymous cookie + valid X-Joinery-Csrf invokes an allow_guest action
 *      (consent_record), and the write lands in the DB.
 *   3. auth.session_write persists $_SESSION mutations across guest requests
 *      (coupon applied in request 1 is still on the cart in request 2).
 *   4. checkout_check_email answers as a guest (false and true cases).
 *   5. Fail-closed negatives: non-guest action 401, CRUD 401, form 401,
 *      auth/session 401, wrong token 403, no token 400 (unchanged shape),
 *      API key on a requires_browser_session guest action 403.
 *   6. Cache interaction: the mirror cookie still arrives on X-Cache HIT
 *      responses (the cache-serve path runs SessionControl).
 *
 * Usage: php guest_credential_test.php [base_url] [origin_ip]
 *
 * @version 1.0.0
 */

/** @joinery-test
 * name: api_guest_credential
 * tier: db
 * env: dev-only
 * needs: []
 */
require_once(__DIR__ . '/api_test_harness.php');

api_test_boot($argv);

/**
 * Curl with a cookie jar, returning response headers too. JSON-encodes $body.
 * $extra_cookies rides CURLOPT_COOKIE alongside the jar (e.g. visitor_id).
 */
function guest_request($method, $path, $cookie_file, $headers = array(), $body = null, $extra_cookies = null) {
	global $BASE_URL, $ORIGIN_IP;
	$ch = curl_init($BASE_URL . $path);
	if ($body !== null) {
		$headers[] = 'Content-Type: application/json';
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
	}
	$host = parse_url($BASE_URL, PHP_URL_HOST);
	$response_headers = array();
	curl_setopt_array($ch, array(
		CURLOPT_CUSTOMREQUEST => strtoupper($method),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HTTPHEADER => $headers,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_COOKIEJAR => $cookie_file,
		CURLOPT_COOKIEFILE => $cookie_file,
		CURLOPT_RESOLVE => array($host . ':443:' . $ORIGIN_IP, $host . ':80:' . $ORIGIN_IP),
		CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$response_headers) {
			$response_headers[] = trim($line);
			return strlen($line);
		},
	));
	if ($extra_cookies !== null) {
		curl_setopt($ch, CURLOPT_COOKIE, $extra_cookies);
	}
	$raw = curl_exec($ch);
	$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	curl_close($ch);
	return array('status' => $status, 'json' => json_decode((string)$raw, true),
		'raw' => (string)$raw, 'headers' => $response_headers);
}

/** The joinery_api_csrf value from a Netscape cookie jar, or null. */
function jar_csrf_token($cookie_file) {
	foreach (file($cookie_file) as $line) {
		$parts = preg_split('/\t/', trim($line));
		if (count($parts) >= 7 && $parts[5] === 'joinery_api_csrf') {
			return $parts[6];
		}
	}
	return null;
}

function guest_csrf_header($token) {
	return array('X-Joinery-Csrf: ' . $token);
}

function response_header_matches($headers, $pattern) {
	foreach ($headers as $h) {
		if (preg_match($pattern, $h)) return true;
	}
	return false;
}

$cookie_files = array();

try {

	section('Setup: anonymous session with mirror cookie');
	$run_id = substr(md5(uniqid('', true)), 0, 6);
	$jar = tempnam(sys_get_temp_dir(), 'jygst');
	$cookie_files[] = $jar;

	$page = guest_request('GET', '/', $jar);
	check($page['status'] === 200, 'anonymous GET / renders', 'status ' . $page['status']);
	$token = jar_csrf_token($jar);
	check($token !== null && preg_match('/^[0-9a-f]{64}$/', (string)$token),
		'joinery_api_csrf mirror cookie distributed to an anonymous visitor');
	check(strpos($page['raw'], '<meta name="joinery-api-csrf"') === false,
		'anonymous HTML carries no per-visitor CSRF meta tag (cache-safe)');

	section('1. Anonymous + valid CSRF invokes an allow_guest action');
	$visitor_id = 'guesttest' . $run_id;
	$r = guest_request('POST', '/api/v1/action/consent_record', $jar,
		guest_csrf_header($token), array('analytics' => true, 'marketing' => false),
		'visitor_id=' . $visitor_id);
	check($r['status'] === 200, 'consent_record as guest returns 200', $r['raw']);
	check(($r['json']['data']['recorded'] ?? false) === true, 'response reports recorded', $r['raw']);

	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare("SELECT vse_visitor_event_id, vse_source FROM vse_visitor_events
		WHERE vse_visitor_id = ? AND vse_type = 2");
	$q->execute(array($visitor_id));
	$consent_rows = $q->fetchAll(PDO::FETCH_ASSOC);
	check(count($consent_rows) === 1, 'consent event landed in the DB', count($consent_rows) . ' rows');
	if ($consent_rows) {
		$source = json_decode($consent_rows[0]['vse_source'], true);
		check(($source['a'] ?? null) === 1 && ($source['m'] ?? null) === 0,
			'consent choices recorded faithfully (a=1, m=0)', $consent_rows[0]['vse_source']);
		harness_register_row('vse_visitor_events', 'vse_visitor_event_id',
			$consent_rows[0]['vse_visitor_event_id']);
	}

	section('2. auth.session_write persists guest cart mutations across requests');
	// Hard requirement, not a skip: this suite is dev-only and session-write
	// persistence is the riskiest part of the credential — going green
	// without exercising it would be a silent gap.
	$settings = Globalvars::get_instance();
	check((bool)$settings->get_setting('products_active'),
		'products_active is on (required — this suite must exercise cart persistence)');
	if ($settings->get_setting('products_active')) {
		require_once(PathHelper::getIncludePath('plugins/store/data/coupon_codes_class.php'));
		$codes = array();
		foreach (array('a', 'b') as $suffix) {
			$coupon = new CouponCode(NULL);
			$coupon->set('ccd_code', 'guesttest_' . $run_id . '_' . $suffix);
			$coupon->set('ccd_percent_discount', 5);
			$coupon->set('ccd_is_active', TRUE);
			$coupon->set('ccd_is_stackable', TRUE);
			$coupon->save();
			harness_register_row(CouponCode::$tablename, CouponCode::$pkey_column, $coupon->key);
			$codes[] = $coupon->get('ccd_code');
		}

		$r1 = guest_request('POST', '/api/v1/action/store/checkout_apply_coupon', $jar,
			guest_csrf_header($token), array('coupon_code' => $codes[0]));
		check($r1['status'] === 200, 'guest applies coupon 1', $r1['raw']);
		check(in_array($codes[0], $r1['json']['data']['coupon_codes'] ?? array()),
			'coupon 1 on the cart in request 1', $r1['raw']);

		$r2 = guest_request('POST', '/api/v1/action/store/checkout_apply_coupon', $jar,
			guest_csrf_header($token), array('coupon_code' => $codes[1]));
		$codes_after = $r2['json']['data']['coupon_codes'] ?? array();
		check($r2['status'] === 200, 'guest applies coupon 2', $r2['raw']);
		check(in_array($codes[0], $codes_after) && in_array($codes[1], $codes_after),
			'request 2 still sees coupon 1 — session write persisted across requests', $r2['raw']);

		$r3 = guest_request('POST', '/api/v1/action/store/checkout_remove_coupon', $jar,
			guest_csrf_header($token), array('coupon_code' => $codes[0]));
		check($r3['status'] === 200 && !in_array($codes[0], $r3['json']['data']['coupon_codes'] ?? array('sentinel')),
			'guest removes coupon 1', $r3['raw']);
	}

	section('3. checkout_check_email answers as a guest');
	$r = guest_request('POST', '/api/v1/action/store/checkout_check_email', $jar,
		guest_csrf_header($token), array('email' => 'guesttest_' . $run_id . '_nobody@getjoinery.com'));
	check($r['status'] === 200 && ($r['json']['data']['exists'] ?? null) === false,
		'unknown email reports exists=false', $r['raw']);

	$member = make_user('Gst' . $run_id);
	$r = guest_request('POST', '/api/v1/action/store/checkout_check_email', $jar,
		guest_csrf_header($token), array('email' => $member->get('usr_email')));
	check($r['status'] === 200 && ($r['json']['data']['exists'] ?? null) === true,
		'existing email reports exists=true', $r['raw']);

	section('4. Fail-closed everywhere else');
	$r = guest_request('POST', '/api/v1/action/account_edit', $jar,
		guest_csrf_header($token), array('usr_first_name' => 'Nope'));
	check($r['status'] === 401, 'non-guest action as guest → 401', 'status ' . $r['status'] . ' ' . $r['raw']);
	check(strpos($r['raw'], 'requires authentication') !== false,
		'401 body is the generic authentication shape', $r['raw']);

	$r = guest_request('GET', '/api/v1/User/' . (int)$member->key, $jar, guest_csrf_header($token));
	check($r['status'] === 401, 'CRUD read as guest → 401', 'status ' . $r['status'] . ' ' . $r['raw']);

	$r = guest_request('GET', '/api/v1/form/account_edit', $jar, guest_csrf_header($token));
	check($r['status'] === 401, 'sessioned form as guest → 401', 'status ' . $r['status'] . ' ' . $r['raw']);

	$r = guest_request('GET', '/api/v1/auth/session', $jar, guest_csrf_header($token));
	check($r['status'] === 401, 'auth/session as guest → 401', 'status ' . $r['status'] . ' ' . $r['raw']);

	$r = guest_request('POST', '/api/v1/action/consent_record', $jar,
		guest_csrf_header(str_repeat('0', 64)), array('analytics' => true));
	check($r['status'] === 403, 'wrong CSRF token → 403', 'status ' . $r['status'] . ' ' . $r['raw']);

	$r = guest_request('POST', '/api/v1/action/consent_record', $jar, array(), array('analytics' => true));
	check($r['status'] === 400, 'anonymous cookie, no CSRF header → 400 (pre-existing shape)',
		'status ' . $r['status'] . ' ' . $r['raw']);
	check(strpos($r['raw'], 'keys not present') !== false,
		'no-header error body unchanged', $r['raw']);

	$machine = make_machine_key($member->key, 'GuestSuite');
	$r = guest_request('POST', '/api/v1/action/store/checkout_check_email',
		tempnam(sys_get_temp_dir(), 'jygk'),
		key_headers($machine['api_key']->get('apk_public_key'), $machine['secret_key']),
		array('email' => 'x@example.com'));
	check($r['status'] === 403, 'API key on a requires_browser_session action → 403',
		'status ' . $r['status'] . ' ' . $r['raw']);

	section('5. Mirror cookie survives the static page cache');
	// Hard requirement, not a skip: token distribution on cache HITs is the
	// whole reason the mirror cookie exists — the suite must observe a HIT.
	$hit = null;
	for ($i = 0; $i < 5 && $hit === null; $i++) {
		$p = guest_request('GET', '/', $jar);
		if (response_header_matches($p['headers'], '/^X-Cache:\s*HIT/i')) $hit = $p;
	}
	check($hit !== null, 'an X-Cache HIT was observed within 5 fetches of / (page cache must be live on dev)');
	if ($hit !== null) {
		check(strpos($hit['raw'], '<meta name="joinery-api-csrf"') === false,
			'cached HTML carries no CSRF meta tag');
		check(jar_csrf_token($jar) === $token,
			'mirror cookie still valid for this visitor after cache HITs');
	}

} finally {
	harness_teardown_data();
	foreach ($cookie_files as $f) {
		if (is_file($f)) unlink($f);
	}
}

harness_finish();
