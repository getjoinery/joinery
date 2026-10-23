<?php
/** @joinery-test
 * name: rate_limit_state
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * RequestLogger::rate_limit_state() and the API's use of it.
 *
 *  - Under the limit: allowed, with the count. Over it: refused, with the
 *    seconds until the next request will be accepted — when the oldest of the
 *    rows that put the caller over the limit leaves the window, not when the
 *    whole window has passed.
 *  - A count keyed to a user sees that user's rows from any address and no
 *    other user's rows from the same address.
 *  - An attempt limit (attempt_limit_state) counts by network: an IPv4
 *    address, or an IPv6 /64. A capacity limit (rate_limit_state) keeps
 *    counting by address, so servers sharing one /64 are separate callers.
 *    Every row log() writes records its network.
 *  - apiv1.php meters a browser-shaped request per user after authentication,
 *    not per address before it, and every 429 carries Retry-After and the
 *    numbers. A keyed action request (both key headers) is metered after
 *    authentication too, and a principal with no user by address.
 *  - address_rate_limit_state() leaves per-person traffic (browser, session,
 *    app_password rows) out of an address's count.
 *  - person_rate_limit_state() counts one person's rows of one kind: their
 *    browser, devices and app credentials are separate budgets, and apiv1.php
 *    meters each per-person kind on its own (devices on
 *    api_device_rate_limit_requests).
 *
 * Run: php tests/run.php db --filter=rate_limit_state
 *
 * @version 1.3 - person_rate_limit_state: one budget per kind of credential
 * @version 1.2 - address_rate_limit_state; keyed action requests
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));

$db = DbConnector::get_instance()->get_db_link();
$feature = 'test_rl_' . bin2hex(random_bytes(3));
$ip = '203.0.113.' . random_int(1, 254);
$other_ip = '203.0.113.' . random_int(1, 254);
$_SERVER['REMOTE_ADDR'] = $ip;

/** One log row, $age seconds old, for $feature, carrying its network as log() writes it. */
$row = function (string $addr, int $age, ?int $user_id = null, ?string $row_feature = null, ?string $key_type = null) use ($db, $feature) {
	$stmt = $db->prepare('INSERT INTO rql_request_logs (rql_feature, rql_action, rql_ip_address, rql_ip_network, rql_usr_user_id, rql_api_key_type, rql_was_success, rql_create_time)
		VALUES (?, ?, ?, ?, ?, ?, true, NOW() - (? || \' seconds\')::interval) RETURNING rql_request_log_id');
	$stmt->execute(array($row_feature ?? $feature, 'test', $addr, IpAddress::network($addr), $user_id, $key_type, $age));
	harness_register_row('rql_request_logs', 'rql_request_log_id', (int)$stmt->fetchColumn());
};

// ---------------------------------------------------------------------------
section('Under and over the limit, keyed to the address');
// ---------------------------------------------------------------------------

$s = RequestLogger::rate_limit_state($feature, 3, 600);
check($s['allowed'] && $s['count'] === 0 && $s['retry_after'] === 0, 'an empty window allows, count 0', json_encode($s));

$row($ip, 500);   // leaves the window in ~100 s
$row($ip, 300);   // ~300 s
$row($ip, 100);   // ~500 s
$s = RequestLogger::rate_limit_state($feature, 3, 600);
check(!$s['allowed'] && $s['count'] === 3, 'three rows against a limit of three refuse', json_encode($s));
check($s['retry_after'] >= 95 && $s['retry_after'] <= 105,
	'…and the wait is until the OLDEST row leaves the window (~100 s), not the whole window', json_encode($s));

$row($ip, 50);    // a fourth: now two rows must age out before one is allowed
$s = RequestLogger::rate_limit_state($feature, 3, 600);
check($s['count'] === 4 && $s['retry_after'] >= 295 && $s['retry_after'] <= 305,
	'one over the limit: the wait is until the SECOND-oldest leaves (~300 s)', json_encode($s));

$s = RequestLogger::rate_limit_state($feature, 3, 60);
check($s['allowed'] && $s['count'] === 1, 'a shorter window sees only the rows inside it', json_encode($s));

$row($other_ip, 10);
$s = RequestLogger::rate_limit_state($feature, 10, 600);
check($s['count'] === 4, "another address's rows are not this address's", json_encode($s));

check(RequestLogger::check_rate_limit($feature, 3, 600) === false && RequestLogger::check_rate_limit($feature, 10, 600) === true,
	'check_rate_limit() is the allowed flag of the same state');

// ---------------------------------------------------------------------------
section('Keyed to a user');
// ---------------------------------------------------------------------------

$u1 = 990001 + random_int(0, 999);
$u2 = $u1 + 1;
$row($ip, 20, $u1);
$row($other_ip, 30, $u1);   // the same user from another address counts
$row($ip, 40, $u2);         // another user from the same address does not
$s = RequestLogger::rate_limit_state($feature, 2, 600, null, $u1);
check(!$s['allowed'] && $s['count'] === 2, "a user's count spans addresses and excludes other users", json_encode($s));
$s = RequestLogger::rate_limit_state($feature, 2, 600, null, $u2);
check($s['allowed'] && $s['count'] === 1, 'the other user has their own count', json_encode($s));

// ---------------------------------------------------------------------------
section('A person has one budget per kind of credential');
// ---------------------------------------------------------------------------

$kind_feature = $feature . '_k';
$row($ip, 10, $u1, $kind_feature, 'session');
$row($other_ip, 20, $u1, $kind_feature, 'session');   // another device, another address: the same budget
$row($ip, 30, $u1, $kind_feature, 'browser');
$row($ip, 40, $u2, $kind_feature, 'session');         // another person's device does not count
$s = RequestLogger::person_rate_limit_state($kind_feature, 2, 600, $u1, 'session');
check(!$s['allowed'] && $s['count'] === 2 && $s['retry_after'] > 0,
	"a person's devices share one count across addresses, and it says when", json_encode($s));
$s = RequestLogger::person_rate_limit_state($kind_feature, 2, 600, $u1, 'browser');
check($s['allowed'] && $s['count'] === 1, "the same person's browser has its own count", json_encode($s));
$s = RequestLogger::person_rate_limit_state($kind_feature, 2, 600, $u1, 'app_password');
check($s['allowed'] && $s['count'] === 0, 'and so do their app credentials', json_encode($s));
$s = RequestLogger::rate_limit_state($kind_feature, 10, 600, null, $u1);
check($s['count'] === 3, 'rate_limit_state() by user still counts every kind together', json_encode($s));

// ---------------------------------------------------------------------------
section('An address\'s count leaves out per-person traffic');
// ---------------------------------------------------------------------------

$addr_feature = $feature . '_a';
foreach (array('browser', 'session', 'app_password') as $per_person) {
	$row($ip, 10, $u1, $addr_feature, $per_person);
}
$s = RequestLogger::address_rate_limit_state($addr_feature, 2, 600);
check($s['allowed'] && $s['count'] === 0, 'browser, session and app-credential rows do not count against the address', json_encode($s));
foreach (array('machine', 'guest', null) as $counted) {
	$row($ip, 10, null, $addr_feature, $counted);
}
$s = RequestLogger::address_rate_limit_state($addr_feature, 3, 600);
check(!$s['allowed'] && $s['count'] === 3, 'machine, guest and untyped (failed, no credential) rows do', json_encode($s));
$s = RequestLogger::rate_limit_state($addr_feature, 10, 600);
check($s['count'] === 6, 'rate_limit_state() still counts every row from the address', json_encode($s));

// ---------------------------------------------------------------------------
section('Attempt limits count by network; capacity limits by address');
// ---------------------------------------------------------------------------

$v6_feature = $feature . '_v6';
for ($i = 1; $i <= 10; $i++) {
	$row('2001:db8:77:1::' . dechex($i), 10, null, $v6_feature);
}
$_SERVER['REMOTE_ADDR'] = '2001:db8:77:1:ffff::1';
$s = RequestLogger::attempt_limit_state($v6_feature, 10, 600);
check(!$s['allowed'] && $s['count'] === 10,
	'ten rows from ten addresses in one IPv6 /64 fill one attempt budget, seen from an eleventh address in it', json_encode($s));
check(RequestLogger::check_attempt_limit($v6_feature, 10, 600) === false, 'check_attempt_limit() is the allowed flag of the same state');
$s = RequestLogger::rate_limit_state($v6_feature, 10, 600);
check($s['allowed'] && $s['count'] === 0,
	'the same rows are ten callers to a capacity limit: the eleventh address is accepted (servers sharing a /64 stay apart)', json_encode($s));
$_SERVER['REMOTE_ADDR'] = '2001:db8:77:2::1';
check(RequestLogger::attempt_limit_state($v6_feature, 10, 600)['count'] === 0, 'the next /64 is another network');
$_SERVER['REMOTE_ADDR'] = '2001:db8:77:1::1';
check(RequestLogger::rate_limit_state($v6_feature, 10, 600)['count'] === 1, 'a capacity count sees only its own address');

$_SERVER['REMOTE_ADDR'] = $ip;
check(RequestLogger::attempt_limit_state($feature, 10, 600)['count'] === RequestLogger::rate_limit_state($feature, 10, 600)['count'],
	'for an IPv4 caller the network is the address, so both counts agree');

$log_feature = $feature . '_log';
foreach (array('198.51.100.9' => '198.51.100.9', '2001:db8:77:3::5' => '2001:db8:77:3::/64') as $addr => $network) {
	$_SERVER['REMOTE_ADDR'] = $addr;
	RequestLogger::log($log_feature, 'test', false, array('key' => 'k'));
	$stmt = $db->prepare('SELECT rql_request_log_id, rql_ip_network FROM rql_request_logs WHERE rql_feature = ? AND rql_ip_address = ? ORDER BY rql_request_log_id DESC LIMIT 1');
	$stmt->execute(array($log_feature, $addr));
	$logged = $stmt->fetch(PDO::FETCH_ASSOC) ?: array();
	if (!empty($logged['rql_request_log_id'])) {
		harness_register_row('rql_request_logs', 'rql_request_log_id', (int)$logged['rql_request_log_id']);
	}
	check(($logged['rql_ip_network'] ?? null) === $network, "log() records the network of $addr as $network", json_encode($logged));
}
$_SERVER['REMOTE_ADDR'] = $ip;

check(RequestLogger::username_key(' Someone@Example.COM ') === RequestLogger::username_key('someone@example.com')
	&& strpos(RequestLogger::username_key('someone@example.com'), 'user:') === 0
	&& strlen(RequestLogger::username_key('someone@example.com')) === 37,
	'the per-account key ignores case and surrounding space, and fits rql_key');
$apiauth = file_get_contents(PathHelper::getIncludePath('includes/ApiAuth.php'));
$login = file_get_contents(PathHelper::getIncludePath('logic/login_logic.php'));
check(strpos($apiauth, 'RequestLogger::username_key($username)') !== false
	&& strpos($login, 'RequestLogger::username_key(') !== false,
	'app sign-in and web sign-in key their per-account count the same way');

// ---------------------------------------------------------------------------
section('apiv1.php meters browser sessions per user, and every 429 says when');
// ---------------------------------------------------------------------------

$api = file_get_contents(PathHelper::getIncludePath('api/apiv1.php'));
check(strpos($api, '$browser_shaped = empty($headers[\'public_key\'])') !== false
	&& strpos($api, 'if (!$browser_shaped && !$app_shaped && !$key_shaped) {') !== false,
	'a browser-shaped request skips the per-address check before authentication');
check(strpos($api, "\$key_shaped = \$action_route && !empty(\$headers['public_key']) && !empty(\$headers['secret_key']);") !== false,
	'a keyed ACTION request is one with both key headers, as ApiAuth reads a key request');
check(substr_count($api, "RequestLogger::address_rate_limit_state('api'") === 2
	&& strpos($api, "RequestLogger::rate_limit_state('api', \$api_rate_limit") === false,
	'every general address check leaves per-person traffic out of the count');
check(strpos($api, '$app_shaped = isset($headers[\'x_joinery_app_secret\'])') !== false
	&& strpos($api, '$api_entry === null || $api_entry->is_app_credential()') !== false,
	'an app credential is metered per person after authentication, like a browser session');
check(strpos($api, "\$action_route = strtolower(explode('/', trim((string)parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'))[2] ?? '') === 'action';") !== false
	&& strpos($api, "\$app_shaped = isset(\$headers['x_joinery_app_secret']) && \$action_route;") !== false,
	'only an ACTION request can be app-shaped: the headers switch no limiter off on auth/login or forms');
check(substr_count($api, "RequestLogger::person_rate_limit_state('api'") === 1
	&& strpos($api, "RequestLogger::rate_limit_state('api', \$session_limit") === false
	&& strpos($api, "intval(\$api_user->key), (string)RequestLogger::api_key_type())") !== false,
	'a signed-in person is metered after authentication on the kind of credential the request is logged as');
check(strpos($api, "get_setting('api_session_rate_limit_requests')") !== false, 'the session limit is its own setting');
check(strpos($api, "get_setting('api_device_rate_limit_requests')") !== false
	&& strpos($api, "'Your linked devices'") !== false,
	'a session key is metered on the devices\' own limit, and its refusal names them');
check(strpos($api, "api_error('Rate limit exceeded.") === false
	&& strpos($api, "header('Retry-After: '") !== false
	&& substr_count($api, 'api_rate_limited(') >= 6,
	'no bare rate-limit refusal remains; every 429 goes through api_rate_limited() with Retry-After');
$auth_ep = file_get_contents(PathHelper::getIncludePath('includes/ApiAuthEndpoint.php'));
check(strpos($auth_ep, 'Too many device link requests') === false && substr_count($auth_ep, 'api_rate_limited(') === 2,
	'device-link refusals say when too');

$settings_json = json_decode(file_get_contents(PathHelper::getIncludePath('settings.json')), true);
$names = array_column($settings_json['settings'] ?? $settings_json, 'name');
check(in_array('api_session_rate_limit_requests', $names, true) && in_array('api_session_rate_limit_window', $names, true),
	'both session-limit settings are declared');
check(in_array('api_device_rate_limit_requests', $names, true), 'the devices\' limit is declared');

harness_finish();
