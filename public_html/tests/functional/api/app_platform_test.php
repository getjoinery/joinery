<?php
/**
 * App platform functional suite (Phase 1 of specs/ios_app_platform.md):
 *
 *   - GET /api/v1/app/navigation — entry filtering (shell slugs, permission),
 *     destination shape, per-app tab pinning from the app_navigation setting,
 *     and the session-key-only gate
 *   - POST /api/v1/auth/web_session — bridge minting, target validation, and
 *     the session-key-only gate
 *   - /app_bridge — single-use consumption, expiry, the app-context web
 *     session it starts, chrome-less (app display mode) rendering, and
 *     lifetime coupling (key revocation and password change kill the session)
 *
 * Run: php tests/functional/api/app_platform_test.php [base_url] [origin_ip]
 */

require_once(__DIR__ . '/api_test_harness.php');
require_once(PathHelper::getIncludePath('data/app_bridge_tokens_class.php'));

harness_boot($argv);

/**
 * Plain web request (not /api/v1): returns status, headers, body. Pins DNS to
 * the origin like api_request(). $cookie_jar (a file path) persists the web
 * session between calls, like a webview's cookie store.
 */
function web_request($path, $cookie_jar = null, $follow = false) {
	global $BASE_URL, $ORIGIN_IP;
	$ch = curl_init($BASE_URL . $path);
	$host = parse_url($BASE_URL, PHP_URL_HOST);
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HEADER => true,
		CURLOPT_FOLLOWLOCATION => $follow,
		CURLOPT_MAXREDIRS => 5,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_RESOLVE => array($host . ':443:' . $ORIGIN_IP, $host . ':80:' . $ORIGIN_IP),
	));
	if ($cookie_jar !== null) {
		curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_jar);
		curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_jar);
	}
	$raw = curl_exec($ch);
	$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	curl_close($ch);
	return array(
		'status' => $status,
		'headers' => substr((string)$raw, 0, $header_size),
		'body' => substr((string)$raw, $header_size),
	);
}

function login_session_key($email, $password, $device_label) {
	$res = api_request('POST', '/api/v1/auth/login', array(),
		array('email' => $email, 'password' => $password, 'device_label' => $device_label));
	if ($res['status'] !== 200) {
		return null;
	}
	$data = $res['json']['data'];
	// Register the minted key for teardown
	$keys = new MultiApiKey(array('public_key' => $data['public_key']));
	$keys->load();
	foreach ($keys as $key) {
		harness_register_key_id($key->key);
	}
	return $data;
}

$jar_dir = sys_get_temp_dir() . '/app_platform_test_' . getmypid();
@mkdir($jar_dir, 0700);

$old_app_navigation = get_setting_raw('app_navigation');
$old_check_seconds = get_setting_raw('app_bridge_key_check_seconds');

try {

	// ---- fixtures ----------------------------------------------------------
	// Unique suffix per run: teardown can only soft-delete users on deployments
	// where a plugin table is missing from the FK sweep, and a reused email
	// would then match a deleted account and fail login.
	$run_id = strtolower(LibraryFunctions::random_string(6));
	$member = make_user('AppM' . $run_id, 0);
	$admin = make_user('AppA' . $run_id, 10);

	$member_login = login_session_key($member->get('usr_email'), 'TestPassword_AppM' . $run_id, 'App test phone');
	$admin_login = login_session_key($admin->get('usr_email'), 'TestPassword_AppA' . $run_id, 'Admin test phone');
	$machine = make_machine_key($member->key, 'app_test_machine', 4);

	if (!$member_login || !$admin_login) {
		echo "FATAL: could not mint session keys via auth/login\n";
		exit(1);
	}
	$member_headers = key_headers($member_login['public_key'], $member_login['secret_key']);
	$admin_headers = key_headers($admin_login['public_key'], $admin_login['secret_key']);
	$machine_headers = key_headers($machine['api_key']->get('apk_public_key'), $machine['secret_key']);

	// ---- navigation --------------------------------------------------------
	section('app/navigation: entries and filtering');

	$res = api_request('GET', '/api/v1/app/navigation', $member_headers);
	check($res['status'] === 200, 'member session key gets 200', 'status ' . $res['status']);
	$entries = $res['json']['data']['entries'] ?? array();
	$slugs = array_column($entries, 'slug');
	check(in_array('core-profile', $slugs), 'entries include core-profile');
	check(in_array('core-calendar', $slugs), 'entries include core-calendar');
	check(!in_array('core-signout', $slugs), 'shell-owned core-signout excluded');
	check(!in_array('core-signin', $slugs), 'signed-out core-signin excluded');
	check(!in_array('core-admin-dashboard', $slugs), 'permission-5 entry hidden from member');

	$profile_entry = null;
	foreach ($entries as $e) {
		if ($e['slug'] === 'core-profile') $profile_entry = $e;
	}
	check($profile_entry !== null
		&& ($profile_entry['destination']['type'] ?? '') === 'web'
		&& ($profile_entry['destination']['url'] ?? '') === '/profile',
		'core-profile destination is {type: web, url: /profile}',
		json_encode($profile_entry));

	$res = api_request('GET', '/api/v1/app/navigation', $admin_headers);
	$admin_slugs = array_column($res['json']['data']['entries'] ?? array(), 'slug');
	check(in_array('core-admin-dashboard', $admin_slugs), 'admin sees permission-5 entry');

	section('app/navigation: tab pinning');

	$tabs = $res = api_request('GET', '/api/v1/app/navigation', $member_headers);
	$tabs = $res['json']['data']['tabs'] ?? array();
	check(in_array('core-profile', $tabs) && in_array('core-calendar', $tabs),
		'default tabs from app_navigation setting', json_encode($tabs));

	set_setting_raw('app_navigation', json_encode(array(
		'apptest' => array('core-calendar', 'core-profile', 'no-such-slug'),
		'default' => array('core-profile'),
	)));
	$res = api_request('GET', '/api/v1/app/navigation',
		array_merge($member_headers, array('client-app: apptest')));
	$tabs = $res['json']['data']['tabs'] ?? array();
	check($tabs === array('core-calendar', 'core-profile'),
		'client-app picks its own tab list, ordered, unknown slugs dropped', json_encode($tabs));

	$res = api_request('GET', '/api/v1/app/navigation',
		array_merge($member_headers, array('client-app: unconfigured_app')));
	$tabs = $res['json']['data']['tabs'] ?? array();
	check($tabs === array('core-profile'), 'unconfigured client-app falls back to default key', json_encode($tabs));

	set_setting_raw('app_navigation', $old_app_navigation);

	section('app/navigation: gates');

	$res = api_request('GET', '/api/v1/app/navigation', $machine_headers);
	check($res['status'] === 403, 'machine key gets 403', 'status ' . $res['status']);

	$res = api_request('GET', '/api/v1/app/navigation');
	check($res['status'] >= 400, 'anonymous request rejected', 'status ' . $res['status']);

	$res = api_request('POST', '/api/v1/app/navigation', $member_headers);
	check($res['status'] === 405, 'POST gets 405', 'status ' . $res['status']);

	$res = api_request('GET', '/api/v1/app/nonsense', $member_headers);
	check($res['status'] === 404, 'unknown app endpoint 404s', 'status ' . $res['status']);

	// ---- web_session minting ------------------------------------------------
	section('auth/web_session: minting and target validation');

	$res = api_request('POST', '/api/v1/auth/web_session', $member_headers,
		array('target' => '/profile'));
	check($res['status'] === 200, 'session key mints a bridge token', 'status ' . $res['status']);
	$bridge_url = $res['json']['data']['bridge_url'] ?? '';
	check((bool)preg_match('#^/app_bridge\?token=[0-9a-f]{64}$#', $bridge_url),
		'bridge_url shape', $bridge_url);
	check(($res['json']['data']['expires_in'] ?? 0) === AppBridgeToken::TTL_SECONDS, 'expires_in matches TTL');

	foreach (array('https://evil.example.com/x', '//evil.example.com/x', 'profile', '/pro file', "/x\ny") as $bad) {
		$res = api_request('POST', '/api/v1/auth/web_session', $member_headers, array('target' => $bad));
		check($res['status'] === 400, 'target rejected: ' . json_encode($bad), 'status ' . $res['status']);
	}

	$res = api_request('POST', '/api/v1/auth/web_session', $machine_headers, array('target' => '/profile'));
	check($res['status'] === 403, 'machine key cannot mint', 'status ' . $res['status']);

	$res = api_request('GET', '/api/v1/auth/web_session', $member_headers);
	check($res['status'] === 405, 'GET gets 405', 'status ' . $res['status']);

	// ---- bridge consumption --------------------------------------------------
	section('/app_bridge: consumption, single use, expiry');

	$jar1 = $jar_dir . '/jar1.txt';
	$res = web_request($bridge_url, $jar1);
	check($res['status'] === 302, 'valid token 302s', 'status ' . $res['status']);
	check((bool)preg_match('#^Location:\s*/profile\s*$#mi', $res['headers']), 'redirects to the target path');
	check(stripos($res['headers'], 'Set-Cookie:') !== false, 'starts a web session (Set-Cookie)');

	$res = web_request($bridge_url);
	check($res['status'] === 410, 'second use of the same token gets 410', 'status ' . $res['status']);

	// Expired token: mint directly, then age it in the database.
	$member_keys = new MultiApiKey(array('public_key' => $member_login['public_key']));
	$member_keys->load();
	$member_key_obj = null;
	foreach ($member_keys as $k) { $member_key_obj = $k; }
	$mint = AppBridgeToken::Mint($member_key_obj, '/profile', 'apptest');
	harness_register_row('abt_app_bridge_tokens', 'abt_app_bridge_token_id', $mint['bridge_token']->key);
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare("UPDATE abt_app_bridge_tokens SET abt_expires_time = now() - interval '5 minutes' WHERE abt_app_bridge_token_id = ?");
	$q->execute([$mint['bridge_token']->key]);
	$res = web_request('/app_bridge?token=' . $mint['token']);
	check($res['status'] === 410, 'expired token gets 410', 'status ' . $res['status']);

	$res = web_request('/app_bridge?token=' . str_repeat('0', 64));
	check($res['status'] === 410, 'unknown token gets 410', 'status ' . $res['status']);

	// ---- app display mode -----------------------------------------------------
	section('App display mode: chrome-less bridged session, normal traffic unaffected');

	$res = web_request('/profile', $jar1, true);
	check($res['status'] === 200, 'bridged session loads /profile', 'status ' . $res['status']);
	check(strpos($res['body'], 'jy-app-mode') !== false, 'app-mode body class hook present');
	check(strpos($res['body'], 'class="site-nav"') === false, 'site nav chrome absent');
	check(strpos($res['body'], 'class="site-footer"') === false, 'site footer chrome absent');

	$res = web_request('/');
	check(strpos($res['body'], 'class="site-nav"') !== false, 'anonymous page keeps site chrome');
	check(strpos($res['body'], 'jy-app-mode') === false, 'anonymous page has no app-mode hook');

	// ---- lifetime coupling -----------------------------------------------------
	section('Lifetime coupling: revocation kills the bridged web session');

	set_setting_raw('app_bridge_key_check_seconds', '0');

	// Key revocation (app logout / App Sessions page)
	$res = api_request('POST', '/api/v1/auth/logout', $member_headers);
	check($res['status'] === 200, 'key revoked via auth/logout', 'status ' . $res['status']);
	$res = web_request('/profile', $jar1, true);
	check(strpos($res['body'], 'jy-app-mode') === false,
		'bridged session dead after key revocation', 'status ' . $res['status']);

	// Password change (the lost-phone path) — revokes all session keys
	$res = api_request('POST', '/api/v1/auth/web_session', $admin_headers, array('target' => '/profile'));
	$jar2 = $jar_dir . '/jar2.txt';
	$bres = web_request($res['json']['data']['bridge_url'], $jar2);
	check($bres['status'] === 302, 'second bridge established', 'status ' . $bres['status']);
	$pres = web_request('/profile', $jar2, true);
	check($pres['status'] === 200 && strpos($pres['body'], 'jy-app-mode') !== false,
		'second bridged session loads /profile', 'status ' . $pres['status']);

	$admin->set('usr_password', User::GeneratePassword('NewPassword_' . LibraryFunctions::random_string(8)));
	$admin->save();

	$res = web_request('/profile', $jar2, true);
	check(strpos($res['body'], 'jy-app-mode') === false,
		'bridged session dead after password change', 'status ' . $res['status']);

} finally {
	if ($old_app_navigation !== null) set_setting_raw('app_navigation', $old_app_navigation);
	if ($old_check_seconds !== null) set_setting_raw('app_bridge_key_check_seconds', $old_check_seconds);
	array_map('unlink', glob($jar_dir . '/*') ?: array());
	@rmdir($jar_dir);
	harness_teardown_data();
}

harness_finish();
