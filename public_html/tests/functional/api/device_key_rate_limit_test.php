<?php
/**
 * A person's own device is metered on that person's budget, not its address's
 * (api/apiv1.php 2.29, ApiLogicEndpoint 1.8.2).
 *
 * The run's own loopback address is filled to the address limit first, as a
 * home or office whose other devices have spent it. Then:
 *   - a session key (a linked desktop, a phone app) still reaches an action;
 *   - a machine key from the same address is refused 429 'This address';
 *   - a key header on a sessionless action does not switch the address limit
 *     off: refused 429 'This address' (it runs before any key is checked);
 *   - a guest (cookie + X-Joinery-Csrf) adding a public-key header with no
 *     secret is still metered by address: 429 'This address' on a guest action;
 *   - the person's own budget still holds, on every route: filled, the
 *     session key is refused 429 'Your account' on an action, auth/session
 *     and a record read.
 * A second address is filled with per-person rows only (a household's own
 * devices): they do not count against it, so a machine key and a device's
 * non-action route still get through (RequestLogger 1.7).
 * Its rows are removed afterwards.
 *
 *   php tests/functional/api/device_key_rate_limit_test.php [base_url] [origin_ip]
 */

/** @joinery-test
 * name: api_device_key_rate_limit
 * tier: db
 * env: dev-only
 * needs: []
 */
require_once(__DIR__ . '/api_test_harness.php');
api_test_boot($argv);

$settings = Globalvars::get_instance();
$db = DbConnector::get_instance()->get_db_link();
$from = harness_http_loopback_source();
$user = null;
$fresh = null;

$households = harness_http_loopback_source();
$fill = function ($n, $ip, $user_id, $key_type = null) use ($db) {
	$db->prepare("INSERT INTO rql_request_logs (rql_feature, rql_ip_address, rql_was_success, rql_usr_user_id, rql_api_key_type, rql_create_time)
		SELECT 'api', ?, TRUE, ?, ?, NOW() FROM generate_series(1, ?)")->execute([$ip, $user_id, $key_type, (int)$n]);
};
$call = function ($path, array $key, $body = array(), $ip = null, $method = 'POST') use ($from) {
	$opts = array(
		'headers' => key_headers($key['api_key']->get('apk_public_key'), $key['secret_key']),
		'from' => $ip ?: $from,
	);
	if ($method === 'POST') {
		$opts['body'] = $body ?: new stdClass();
		$opts['encode'] = 'json';
	}
	return harness_request($method, $path, $opts);
};
$who = function ($r) {
	return (string)($r['json']['error'] ?? $r['json']['message'] ?? $r['raw']);
};

try {
	$suffix = strtoupper(LibraryFunctions::random_string(6));
	$address_limit = (int)($settings->get_setting('api_rate_limit_requests') ?: 1000);
	$person_limit = (int)($settings->get_setting('api_session_rate_limit_requests') ?: 5000);

	section('Setup: an address whose budget its other devices have spent');
	$user = make_user($suffix . 'D');
	$session = ApiKey::CreateSessionKey($user->key, 'Desktop ' . $suffix);
	harness_register_key_id($session['api_key']->key);
	$machine = make_machine_key($user->key, 'mkey-' . $suffix);
	$fill($address_limit, $from, null);
	$q = $db->prepare("SELECT COUNT(*) FROM rql_request_logs WHERE rql_feature = 'api' AND rql_ip_address = ?");
	$q->execute([$from]);
	check((int)$q->fetchColumn() >= $address_limit, 'the address holds its whole budget: ' . $address_limit . ' rows against ' . $from);

	section('A session key is metered on its person, not the address');
	$r = $call('/api/v1/action/vault_client_probe', $session);
	check($r['status'] === 200, 'the device still reaches an action from a spent address', $r['status'] . ' ' . $r['raw']);

	section('Any other key stays on the address');
	$r = $call('/api/v1/action/vault_client_probe', $machine);
	check($r['status'] === 429 && stripos($who($r), 'This address') !== false,
		'a machine key from the spent address is refused 429 This address', $r['status'] . ' ' . $r['raw']);

	section('A key header does not lift the limit off a sessionless action');
	$junk = array('api_key' => (function () { $k = new ApiKey(NULL); $k->set('apk_public_key', 'public_nosuchkey'); return $k; })(),
		'secret_key' => 'nosuchsecret');
	$r = $call('/api/v1/action/passkey_login_options', $junk);
	check($r['status'] === 429 && stripos($who($r), 'This address') !== false,
		'a made-up key on passkey_login_options from the spent address is refused 429 This address', $r['status'] . ' ' . $r['raw']);

	section('A public key with no secret is not a key: a guest stays on the address');
	$jar = harness_jar_new('devkey');
	harness_request('GET', '/', array('jar' => $jar, 'from' => $from, 'accept' => 'text/html'));
	$csrf = harness_jar_csrf($jar);
	check((bool)$csrf, 'a guest page view hands out the CSRF token');
	$r = harness_request('POST', '/api/v1/action/forum/forum_search', array(
		'jar' => $jar, 'from' => $from, 'encode' => 'json', 'body' => array('q' => 'probe'),
		'headers' => array('X-Joinery-Csrf: ' . $csrf, 'public-key: public_nosuchkey'),
	));
	check($r['status'] === 429 && stripos($who($r), 'This address') !== false,
		'a guest with a made-up public key and no secret is refused 429 This address', $r['status'] . ' ' . $r['raw']);

	section('A household\'s own devices do not fill their address');
	$fill($address_limit, $households, (int)$user->key, 'session');
	$fill($address_limit, $households, (int)$user->key, 'browser');
	$r = $call('/api/v1/action/vault_client_probe', $machine, array(), $households);
	check($r['status'] === 200, 'a machine key still gets through an address full of per-person rows', $r['status'] . ' ' . $r['raw']);
	$r = $call('/api/v1/auth/session', $session, array(), $households, 'GET');
	check($r['status'] === 200, 'a device\'s non-action route (auth/session) still gets through it', $r['status'] . ' ' . $r['raw']);
	$db->prepare("DELETE FROM rql_request_logs WHERE rql_ip_address = ?")->execute([$households]);

	section('The person\'s own budget still holds');
	$fill($person_limit, '127.0.0.1', (int)$user->key);
	$r = $call('/api/v1/action/vault_client_probe', $session);
	check($r['status'] === 429 && stripos($who($r), 'Your account') !== false,
		'with the person\'s budget spent, the device is refused 429 Your account', $r['status'] . ' ' . $r['raw']);
	// From an address nobody has spent: a non-action route checks the address
	// before authentication, and only the person's budget may refuse these.
	$fresh = harness_http_loopback_source();
	foreach (array('/api/v1/auth/session', '/api/v1/User/' . (int)$user->key) as $route) {
		$r = $call($route, $session, array(), $fresh, 'GET');
		check($r['status'] === 429 && stripos($who($r), 'Your account') !== false,
			'…and on a non-action route too: GET ' . $route . ' is refused 429 Your account', $r['status'] . ' ' . $r['raw']);
	}

} catch (\Throwable $e) {
	check(false, 'unhandled exception mid-suite', $e->getMessage());
} finally {
	$db->prepare("DELETE FROM rql_request_logs WHERE rql_ip_address IN (?, ?, ?)")->execute([$from, $households, (string)$fresh]);
	if ($user) {
		$db->prepare("DELETE FROM rql_request_logs WHERE rql_usr_user_id = ?")->execute([(int)$user->key]);
	}
}

harness_finish();
