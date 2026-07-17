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
 * @version 1.1.0
 */

/** @joinery-test
 * name: api_guest_credential
 * tier: db
 * env: dev-only
 * needs: []
 */
require_once(__DIR__ . '/api_test_harness.php');

api_test_boot($argv);

try {

	section('Setup: anonymous session with mirror cookie');
	$run_id = substr(md5(uniqid('', true)), 0, 6);
	$jar = harness_jar_new('jygst');

	$page = harness_request('GET', '/', array('jar' => $jar));
	check($page['status'] === 200, 'anonymous GET / renders', 'status ' . $page['status']);
	$token = harness_jar_csrf($jar);
	check($token !== null && preg_match('/^[0-9a-f]{64}$/', (string)$token),
		'joinery_api_csrf mirror cookie distributed to an anonymous visitor');
	check(strpos($page['raw'], '<meta name="joinery-api-csrf"') === false,
		'anonymous HTML carries no per-visitor CSRF meta tag (cache-safe)');

	section('1. Anonymous + valid CSRF invokes an allow_guest action');
	$visitor_id = 'guesttest' . $run_id;
	$r = harness_request('POST', '/api/v1/action/consent_record', array(
		'jar'     => $jar,
		'headers' => harness_csrf_header($token),
		'body'    => array('analytics' => true, 'marketing' => false),
		'cookies' => 'visitor_id=' . $visitor_id,
	));
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

		$r1 = harness_request('POST', '/api/v1/action/store/checkout_apply_coupon', array(
			'jar' => $jar, 'headers' => harness_csrf_header($token),
			'body' => array('coupon_code' => $codes[0])));
		check($r1['status'] === 200, 'guest applies coupon 1', $r1['raw']);
		check(in_array($codes[0], $r1['json']['data']['coupon_codes'] ?? array()),
			'coupon 1 on the cart in request 1', $r1['raw']);

		$r2 = harness_request('POST', '/api/v1/action/store/checkout_apply_coupon', array(
			'jar' => $jar, 'headers' => harness_csrf_header($token),
			'body' => array('coupon_code' => $codes[1])));
		$codes_after = $r2['json']['data']['coupon_codes'] ?? array();
		check($r2['status'] === 200, 'guest applies coupon 2', $r2['raw']);
		check(in_array($codes[0], $codes_after) && in_array($codes[1], $codes_after),
			'request 2 still sees coupon 1 — session write persisted across requests', $r2['raw']);

		$r3 = harness_request('POST', '/api/v1/action/store/checkout_remove_coupon', array(
			'jar' => $jar, 'headers' => harness_csrf_header($token),
			'body' => array('coupon_code' => $codes[0])));
		check($r3['status'] === 200 && !in_array($codes[0], $r3['json']['data']['coupon_codes'] ?? array('sentinel')),
			'guest removes coupon 1', $r3['raw']);
	}

	section('3. checkout_check_email answers as a guest');
	$r = harness_request('POST', '/api/v1/action/store/checkout_check_email', array(
		'jar' => $jar, 'headers' => harness_csrf_header($token),
		'body' => array('email' => 'guesttest_' . $run_id . '_nobody@getjoinery.com')));
	check($r['status'] === 200 && ($r['json']['data']['exists'] ?? null) === false,
		'unknown email reports exists=false', $r['raw']);

	$member = make_user('Gst' . $run_id);
	$r = harness_request('POST', '/api/v1/action/store/checkout_check_email', array(
		'jar' => $jar, 'headers' => harness_csrf_header($token),
		'body' => array('email' => $member->get('usr_email'))));
	check($r['status'] === 200 && ($r['json']['data']['exists'] ?? null) === true,
		'existing email reports exists=true', $r['raw']);

	section('4. Fail-closed everywhere else');
	$r = harness_request('POST', '/api/v1/action/account_edit', array(
		'jar' => $jar, 'headers' => harness_csrf_header($token),
		'body' => array('usr_first_name' => 'Nope')));
	check($r['status'] === 401, 'non-guest action as guest → 401', 'status ' . $r['status'] . ' ' . $r['raw']);
	check(strpos($r['raw'], 'requires authentication') !== false,
		'401 body is the generic authentication shape', $r['raw']);

	$r = harness_request('GET', '/api/v1/User/' . (int)$member->key, array(
		'jar' => $jar, 'headers' => harness_csrf_header($token)));
	check($r['status'] === 401, 'CRUD read as guest → 401', 'status ' . $r['status'] . ' ' . $r['raw']);

	$r = harness_request('GET', '/api/v1/form/account_edit', array(
		'jar' => $jar, 'headers' => harness_csrf_header($token)));
	check($r['status'] === 401, 'sessioned form as guest → 401', 'status ' . $r['status'] . ' ' . $r['raw']);

	$r = harness_request('GET', '/api/v1/auth/session', array(
		'jar' => $jar, 'headers' => harness_csrf_header($token)));
	check($r['status'] === 401, 'auth/session as guest → 401', 'status ' . $r['status'] . ' ' . $r['raw']);

	$r = harness_request('POST', '/api/v1/action/consent_record', array(
		'jar' => $jar, 'headers' => harness_csrf_header(str_repeat('0', 64)),
		'body' => array('analytics' => true)));
	check($r['status'] === 403, 'wrong CSRF token → 403', 'status ' . $r['status'] . ' ' . $r['raw']);

	$r = harness_request('POST', '/api/v1/action/consent_record', array(
		'jar' => $jar, 'body' => array('analytics' => true)));
	check($r['status'] === 400, 'anonymous cookie, no CSRF header → 400 (pre-existing shape)',
		'status ' . $r['status'] . ' ' . $r['raw']);
	check(strpos($r['raw'], 'keys not present') !== false,
		'no-header error body unchanged', $r['raw']);

	$machine = make_machine_key($member->key, 'GuestSuite');
	// A jar of its own: this probes the API-key path, so it must not carry the
	// guest session's cookies.
	$r = harness_request('POST', '/api/v1/action/store/checkout_check_email', array(
		'jar'     => harness_jar_new('jygk'),
		'headers' => key_headers($machine['api_key']->get('apk_public_key'), $machine['secret_key']),
		'body'    => array('email' => 'x@example.com'),
	));
	check($r['status'] === 403, 'API key on a requires_browser_session action → 403',
		'status ' . $r['status'] . ' ' . $r['raw']);

	section('5. Mirror cookie survives the static page cache');
	// Hard requirement, not a skip: token distribution on cache HITs is the
	// whole reason the mirror cookie exists — the suite must observe a HIT.
	//
	// The static cache only *creates* an entry for a request whose User-Agent
	// looks like a real browser: StaticPageCache::shouldCache() refuses to
	// cache curl / empty-UA requests. Every request in this suite is curl, so
	// on its own it can only ride an entry that real browser traffic happened
	// to leave behind — and once that entry is gone (a deploy clear, the 1%
	// serve-time freshness roll, or a nostatic marking) no curl fetch can
	// rebuild it and the assertion flakes with no fault in the credential.
	//
	// So warm / with a single browser-UA GET first. It is served by the origin,
	// so Apache writes the cache entry under its own ownership (no CLI-vs-web
	// mismatch). Serving a HIT does not depend on the UA, so the warmed entry
	// is then served to this visitor's normal jar too.
	$browser_ua = 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
		. 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Safari/537.36';
	harness_request('GET', '/', array('jar' => $jar, 'headers' => array($browser_ua)));
	$hit = null;
	// 8, not 5: the entry is known to exist now, so this only has to absorb the
	// 1% serve-time freshness roll, which an all-miss run across 8 fetches
	// cannot realistically survive.
	for ($i = 0; $i < 8 && $hit === null; $i++) {
		$p = harness_request('GET', '/', array('jar' => $jar));
		if (harness_header_matches($p['headers'], '/^X-Cache:\s*HIT/i')) $hit = $p;
	}
	check($hit !== null,
		'an X-Cache HIT was observed on / after warming the page cache (else the static cache is disabled on dev — see /admin/admin_static_cache)');
	if ($hit !== null) {
		check(strpos($hit['raw'], '<meta name="joinery-api-csrf"') === false,
			'cached HTML carries no CSRF meta tag');
		check(harness_jar_csrf($jar) === $token,
			'mirror cookie still valid for this visitor after cache HITs');
	}

} finally {
	harness_teardown_data();
}

harness_finish();
