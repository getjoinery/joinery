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
 *  - apiv1.php meters a browser-shaped request per user after authentication,
 *    not per address before it, and every 429 carries Retry-After and the
 *    numbers.
 *
 * Run: php tests/run.php db --filter=rate_limit_state
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));

$db = DbConnector::get_instance()->get_db_link();
$feature = 'test_rl_' . bin2hex(random_bytes(3));
$ip = '203.0.113.' . random_int(1, 254);
$other_ip = '203.0.113.' . random_int(1, 254);
$_SERVER['REMOTE_ADDR'] = $ip;

/** One log row, $age seconds old, for $feature. */
$row = function (string $addr, int $age, ?int $user_id = null) use ($db, $feature) {
	$stmt = $db->prepare('INSERT INTO rql_request_logs (rql_feature, rql_action, rql_ip_address, rql_usr_user_id, rql_was_success, rql_create_time)
		VALUES (?, ?, ?, ?, true, NOW() - (? || \' seconds\')::interval) RETURNING rql_request_log_id');
	$stmt->execute(array($feature, 'test', $addr, $user_id, $age));
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
section('apiv1.php meters browser sessions per user, and every 429 says when');
// ---------------------------------------------------------------------------

$api = file_get_contents(PathHelper::getIncludePath('api/apiv1.php'));
check(strpos($api, '$browser_shaped = empty($headers[\'public_key\'])') !== false
	&& strpos($api, 'if (!$browser_shaped) {') !== false,
	'a browser-shaped request skips the per-address check before authentication');
check(strpos($api, "rate_limit_state('api', \$session_limit, \$session_window, null, intval(\$api_user->key))") !== false,
	'a signed-in browser session is metered per user after authentication');
check(strpos($api, "get_setting('api_session_rate_limit_requests')") !== false, 'the session limit is its own setting');
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

harness_finish();
