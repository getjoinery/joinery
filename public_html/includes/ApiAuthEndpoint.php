<?php
/**
 * API Auth Endpoints
 *
 * Serves /api/v1/auth/*: per-user session-key provisioning and lifecycle.
 *
 *   POST /api/v1/auth/login        — unauthenticated; email + password → session key pair
 *   GET  /api/v1/auth/session      — key-authenticated (either type); user/tier summary
 *   POST /api/v1/auth/logout       — session-key-authenticated; revokes the presented key
 *   POST /api/v1/auth/web_session  — session-key-authenticated; mints a single-use
 *                                    bridge URL that starts an app-context web session
 *                                    (docs/mobile_apps.md)
 *
 * Dispatched from api/apiv1.php in two places, mirroring ApiLogicEndpoint:
 * dispatchPreAuth() before the key-header requirement (login is the
 * unauthenticated entry point), dispatchAuthenticated() after $api_user
 * resolves. HTTPS enforcement and both rate limiters run before either.
 * Uses the api_error()/api_success() helpers defined in apiv1.php.
 *
 * This is a thin HTTP shell: the credential decisions (verify-and-mint on login,
 * revoke on logout, bridge-token minting) live in ApiAuth/AppBridgeToken. This
 * class owns only the transport concerns — method checks, request parsing,
 * request logging, and response shaping (user_summary).
 *
 * @version 1.3.0
 * @changelog 1.3.0 - auth/web_session: mints an AppBridgeToken for session keys
 *   so the app webview can derive a web session from the API credential.
 * @changelog 1.2.0 - Browser-session principals (api_entry === null) get a
 *   dedicated 403 on logout pointing at the website /logout; /auth/session
 *   works for them unchanged.
 * @changelog 1.1.0 - Credential decisions delegated to ApiAuth; this class is
 *   now a thin transport shell over ApiAuth::attemptLogin()/revokeSessionKey().
 */

require_once(PathHelper::getIncludePath('data/api_keys_class.php'));

class ApiAuthEndpoint {

	/**
	 * Pre-authentication dispatch. Handles login and exits; returns for
	 * session/logout so key authentication continues, after which
	 * dispatchAuthenticated() handles the request. Unknown endpoints 404
	 * here — they can never authenticate later.
	 */
	public static function dispatchPreAuth($url_segments) {
		$endpoint = strtolower($url_segments[3] ?? '');

		if ($endpoint === 'login') {
			self::handle_login();
			// handle_login() always exits.
		}

		if ($endpoint === 'device_link') {
			// Both halves of the ceremony are pre-auth by definition: the device
			// has no credential yet — acquiring one is the whole point.
			if (isset($url_segments[4]) && $url_segments[4] !== '') {
				self::handle_device_link_poll($url_segments[4]);
			}
			self::handle_device_link_begin();
			// Both always exit.
		}

		if (!in_array($endpoint, array('session', 'logout', 'web_session'))) {
			api_error('Unknown auth endpoint: ' . $endpoint, 'ActionError', 404);
		}
	}

	/**
	 * Post-authentication dispatch for session/logout/web_session. Always exits.
	 */
	public static function dispatchAuthenticated($url_segments, $api_entry, $api_user) {
		$endpoint = strtolower($url_segments[3] ?? '');

		if ($endpoint === 'session') {
			self::handle_session($api_user);
		}

		if ($endpoint === 'web_session') {
			self::handle_web_session($api_entry, $api_user);
		}

		self::handle_logout($api_entry, $api_user);
	}

	/**
	 * POST /api/v1/auth/login — verify email + password, mint a session key.
	 * The success response is the only time the secret plaintext is returned.
	 * Always exits.
	 */
	protected static function handle_login() {
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			api_error('Login must use POST method', 'ActionError', 405);
		}

		$params = self::request_params();
		$email = trim($params['email'] ?? '');
		$password = $params['password'] ?? '';
		$device_label = $params['device_label'] ?? NULL;

		if ($email === '' || $password === '') {
			RequestLogger::log('api_auth', 'auth/login', false, [
				'status_code' => 400,
				'error_type' => 'AuthenticationError',
				'note' => 'Missing email or password'
			]);
			api_error('Email and password are required', 'AuthenticationError', 400);
		}

		// The verify-and-mint decision lives in ApiAuth; this shell owns only the
		// HTTP concerns (parsing, logging, response shaping). One failure shape
		// for unknown email, deleted user, and wrong password — no
		// account-existence oracle — and every failure counts toward the
		// api_auth rate limit via the log below.
		require_once(PathHelper::getIncludePath('includes/ApiAuth.php'));
		$result = ApiAuth::attemptLogin($email, $password, $device_label);

		if (!$result['ok']) {
			// Valid credentials on an unactivated account (activation_required_login
			// on): refused with the reason, matching the web login door. The
			// activation email was re-sent by attemptLogin.
			if (($result['reason'] ?? '') === 'activation_required') {
				RequestLogger::log('api_auth', 'auth/login', false, [
					'user_id' => $result['user']->key,
					'status_code' => 403,
					'error_type' => 'AuthenticationError',
					'note' => 'Activation required'
				]);
				api_error('This account requires email activation before signing in. '
					. 'An activation email has been sent to ' . $result['user']->get('usr_email')
					. ' — click the link inside to activate.', 'AuthenticationError', 403);
			}
			RequestLogger::log('api_auth', 'auth/login', false, [
				'user_id' => $result['user'] ? $result['user']->key : NULL,
				'status_code' => 401,
				'error_type' => 'AuthenticationError',
				'note' => 'Invalid login credentials'
			]);
			api_error('Invalid email or password', 'AuthenticationError', 401);
		}

		$user = $result['user'];
		$api_key = $result['api_key'];

		RequestLogger::log('api_auth', 'auth/login', true, [
			'user_id' => $user->key,
			'status_code' => 200
		]);

		api_success(array(
			'public_key' => $api_key->get('apk_public_key'),
			'secret_key' => $result['secret_key'],
			'expires_time' => $api_key->get('apk_expires_time'),
			'user' => self::user_summary($user),
		), 'Login successful');
	}

	/**
	 * POST /api/v1/auth/device_link — open a device-link ceremony.
	 *
	 * The desktop client calls this with nothing but a description of itself,
	 * gets back a short code and a poll token, and shows the user where to go.
	 * No credential is issued here and nothing about the account is revealed —
	 * at this point the server does not know whose device this is.
	 *
	 * Always exits.
	 */
	protected static function handle_device_link_begin() {
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			api_error('Device link must use POST method', 'ActionError', 405);
		}

		// This endpoint carries its own bucket. It is polled every few seconds
		// for ten minutes by design, so it cannot share the deliberately tiny
		// failed-auth allowance, and it must not be an unmetered door either.
		$settings = Globalvars::get_instance();
		$limit  = (int)($settings->get_setting('api_device_link_rate_limit_requests') ?: 600);
		$window = (int)($settings->get_setting('api_device_link_rate_limit_window') ?: 3600);
		if (!RequestLogger::check_rate_limit('api_device_link', $limit, $window)) {
			api_error('Too many device link requests. Please try again later.', 'RateLimitError', 429);
		}

		require_once(PathHelper::getIncludePath('data/device_links_class.php'));
		require_once(PathHelper::getIncludePath('data/sync_devices_class.php'));

		$params = self::request_params();
		$device_name = trim((string)($params['device_name'] ?? ''));
		$platform    = strtolower(trim((string)($params['platform'] ?? '')));
		$pubkey      = trim((string)($params['device_pubkey'] ?? ''));

		if ($device_name === '') {
			api_error('A device name is required', 'ActionError', 400);
		}
		if (!in_array($platform, SyncDevice::platforms(), true)) {
			api_error('Platform must be one of: ' . implode(', ', SyncDevice::platforms()), 'ActionError', 400);
		}
		// The public key is optional (a client that will never touch encrypted
		// folders need not have one) but a malformed one is refused rather than
		// stored, so the browser can seal to it without checking first.
		if ($pubkey !== '') {
			$raw = base64_decode($pubkey, true);
			if ($raw === false || strlen($raw) !== 32) {
				api_error('device_pubkey must be standard base64 of a 32-byte X25519 public key', 'ActionError', 400);
			}
		}

		$code = DeviceLink::generate_code();
		$poll_token = bin2hex(random_bytes(32));

		$link = new DeviceLink(NULL);
		$link->set('dlk_code_hash', DeviceLink::hash_code($code));
		$link->set('dlk_poll_token_hash', DeviceLink::hash_token($poll_token));
		$link->set('dlk_device_name', substr($device_name, 0, 64));
		$link->set('dlk_platform', $platform);
		if ($pubkey !== '') {
			$link->set('dlk_device_pubkey', $pubkey);
		}
		$link->set('dlk_request_ip', substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45));
		$link->set('dlk_status', DeviceLink::STATUS_PENDING);
		$link->set('dlk_expires_time', gmdate('Y-m-d H:i:s', time() + DeviceLink::TTL_SECONDS));
		$link->save();

		$display_code = substr($code, 0, 4) . '-' . substr($code, 4);

		// The code itself is never logged — it is a bearer credential for the
		// ten minutes it lives.
		RequestLogger::log('api_auth', 'auth/device_link', true, ['status_code' => 200]);

		api_success(array(
			'link_code'    => $display_code,
			'poll_token'   => $poll_token,
			'verify_url'   => LibraryFunctions::get_absolute_url('/profile/devices/link?code=' . urlencode($display_code)),
			'expires_time' => $link->get('dlk_expires_time'),
			'poll_after'   => 3,
		), 'Device link opened');
	}

	/**
	 * GET /api/v1/auth/device_link/{poll_token} — collect the outcome.
	 *
	 * While the user has not acted this says `pending` and nothing else. On the
	 * first successful poll after approval it hands over the credential — and
	 * only then — after which the row is scrubbed and further polls report the
	 * ceremony as already claimed. A secret delivered twice is a secret that can
	 * be stolen by whoever polls second.
	 *
	 * Always exits.
	 */
	protected static function handle_device_link_poll($poll_token) {
		if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
			api_error('Device link polling must use GET method', 'ActionError', 405);
		}

		$settings = Globalvars::get_instance();
		$limit  = (int)($settings->get_setting('api_device_link_rate_limit_requests') ?: 600);
		$window = (int)($settings->get_setting('api_device_link_rate_limit_window') ?: 3600);
		if (!RequestLogger::check_rate_limit('api_device_link', $limit, $window)) {
			api_error('Too many device link requests. Please try again later.', 'RateLimitError', 429);
		}

		require_once(PathHelper::getIncludePath('data/device_links_class.php'));

		$link = DeviceLink::load_by_poll_token($poll_token);
		// An unknown token and an expired ceremony are the same answer on
		// purpose: neither tells a prober whether a ceremony ever existed.
		if (!$link || !$link->key) {
			api_error('This device link is no longer valid', 'AuthenticationError', 404);
		}

		$status = (string)$link->get('dlk_status');

		if ($status === DeviceLink::STATUS_PENDING) {
			if ($link->is_expired()) {
				api_error('This device link has expired', 'AuthenticationError', 404);
			}
			api_success(array('status' => DeviceLink::STATUS_PENDING, 'poll_after' => 3));
		}

		if ($status === DeviceLink::STATUS_DENIED) {
			api_success(array('status' => DeviceLink::STATUS_DENIED));
		}

		// Approved. The secret is present only until the first collector takes it.
		$secret = $link->open_secret();
		if ($secret === null) {
			api_error('This device link has already been claimed', 'AuthenticationError', 409);
		}

		require_once(PathHelper::getIncludePath('data/api_keys_class.php'));
		$api_key = new ApiKey((int)$link->get('dlk_apk_api_key_id'), true);
		if (!$api_key->key) {
			api_error('This device link is no longer valid', 'AuthenticationError', 404);
		}

		$payload = array(
			'status'     => DeviceLink::STATUS_APPROVED,
			'public_key' => $api_key->get('apk_public_key'),
			'secret_key' => $secret,
			'device_id'  => (int)$link->get('dlk_sde_sync_device_id'),
			'expires_time' => $api_key->get('apk_expires_time'),
		);
		$sealed = $link->get('dlk_sealed_vault_key');
		if ($sealed !== null && $sealed !== '') {
			$payload['sealed_vault_key'] = $sealed;
		}

		$link->scrub_secrets();

		RequestLogger::log('api_auth', 'auth/device_link/claim', true, [
			'user_id' => (int)$link->get('dlk_usr_user_id'),
			'status_code' => 200,
		]);

		api_success($payload, 'Device linked');
	}

	/**
	 * GET /api/v1/auth/session — the "who am I / what may I do" call.
	 * Always exits.
	 */
	protected static function handle_session($api_user) {
		if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
			api_error('Session endpoint must use GET method', 'ActionError', 405);
		}

		RequestLogger::log('api_auth', 'auth/session', true, [
			'user_id' => $api_user->key,
			'status_code' => 200
		]);

		api_success(self::user_summary($api_user));
	}

	/**
	 * POST /api/v1/auth/logout — revoke the presented session key. Machine
	 * keys are revoked from the admin page, never by themselves. Always exits.
	 */
	protected static function handle_logout($api_entry, $api_user) {
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			api_error('Logout must use POST method', 'ActionError', 405);
		}

		// Browser-session principals have no key to revoke — signing out is a
		// website concern (/logout ends the web session and its API access).
		if ($api_entry === null) {
			RequestLogger::log('api_auth', 'auth/logout', false, [
				'user_id' => $api_user->key,
				'status_code' => 403,
				'error_type' => 'AuthenticationError',
				'note' => 'Browser session on logout'
			]);
			api_error('Browser sessions sign out on the website (/logout), not via the API', 'AuthenticationError', 403);
		}

		require_once(PathHelper::getIncludePath('includes/ApiAuth.php'));
		if (!ApiAuth::revokeSessionKey($api_entry)) {
			RequestLogger::log('api_auth', 'auth/logout', false, [
				'user_id' => $api_user->key,
				'status_code' => 403,
				'error_type' => 'AuthenticationError',
				'note' => 'Machine key on logout'
			]);
			api_error('Machine keys cannot log out; revoke them from the admin API Keys page', 'AuthenticationError', 403);
		}

		RequestLogger::log('api_auth', 'auth/logout', true, [
			'user_id' => $api_user->key,
			'status_code' => 200
		]);

		api_success(new stdClass(), 'Session revoked');
	}

	/**
	 * POST /api/v1/auth/web_session — mint a single-use bridge URL so the
	 * app's webview can start a web session derived from this session key.
	 * The webview loads the returned URL; /app_bridge validates the token,
	 * starts an app-context web session for the key's user, and 302s to the
	 * target. Session keys only: browser sessions already ARE a web session,
	 * and machine keys are integration credentials, not devices. Always exits.
	 */
	protected static function handle_web_session($api_entry, $api_user) {
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			api_error('Web session endpoint must use POST method', 'ActionError', 405);
		}

		if ($api_entry === null || !$api_entry->is_session()) {
			RequestLogger::log('api_auth', 'auth/web_session', false, [
				'user_id' => $api_user->key,
				'status_code' => 403,
				'error_type' => 'AuthenticationError',
				'note' => $api_entry === null ? 'Browser session on web_session' : 'Machine key on web_session'
			]);
			api_error('The web-session bridge serves app session keys only', 'AuthenticationError', 403);
		}

		$params = self::request_params();
		$target = $params['target'] ?? '/';

		if (!self::is_valid_bridge_target($target)) {
			api_error('Target must be a same-origin relative path (e.g. /profile/calendar)', 'ActionError', 400);
		}

		// client_app is recorded onto the bridged session for per-app behavior
		// (same normalization as the version handshake in apiv1.php).
		$client_app = '';
		foreach (getallheaders() as $header_name => $header_value) {
			if (str_replace('-', '_', strtolower($header_name)) === 'client_app') {
				$client_app = trim($header_value);
			}
		}

		require_once(PathHelper::getIncludePath('data/app_bridge_tokens_class.php'));
		$mint = AppBridgeToken::Mint($api_entry, $target, $client_app);

		// The token plaintext is never logged — it appears only in this response.
		RequestLogger::log('api_auth', 'auth/web_session', true, [
			'user_id' => $api_user->key,
			'status_code' => 200
		]);

		api_success(array(
			'bridge_url' => '/app_bridge?token=' . $mint['token'],
			'expires_in' => AppBridgeToken::TTL_SECONDS,
		), 'Bridge token minted');
	}

	/**
	 * Whether a bridge target is a safe same-origin relative path: rooted at
	 * '/', no scheme or host (rejects absolute and protocol-relative URLs),
	 * no backslashes or raw control characters/spaces.
	 */
	protected static function is_valid_bridge_target($target) {
		if (!is_string($target) || $target === '' || strlen($target) > 512) {
			return false;
		}
		if ($target[0] !== '/') {
			return false;
		}
		if (isset($target[1]) && ($target[1] === '/' || $target[1] === '\\')) {
			return false;
		}
		if (strpos($target, '\\') !== false || preg_match('/[\x00-\x20]/', $target)) {
			return false;
		}
		$parts = parse_url($target);
		if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
			return false;
		}
		return true;
	}

	/**
	 * The user/tier summary shared by login and session responses: identity,
	 * permission, subscription tier, and tier feature flags.
	 */
	protected static function user_summary($user) {
		require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
		$tier = SubscriptionTier::GetUserTier($user->key);

		$tier_summary = NULL;
		if ($tier) {
			$features = json_decode($tier->get('sbt_features') ?: '{}', true);
			$tier_summary = array(
				'subscription_tier_id' => $tier->key,
				'name' => $tier->get('sbt_name'),
				'display_name' => $tier->get('sbt_display_name'),
				'tier_level' => (int)$tier->get('sbt_tier_level'),
				'features' => is_array($features) ? $features : new stdClass(),
			);
		}

		return array(
			'user_id' => $user->key,
			'first_name' => $user->get('usr_first_name'),
			'last_name' => $user->get('usr_last_name'),
			'display_name' => $user->display_name(),
			'email' => $user->get('usr_email'),
			'permission' => (int)$user->get('usr_permission'),
			'tier' => $tier_summary,
		);
	}

	/**
	 * Request parameters from the JSON body when present, else form data —
	 * the same convention as the action endpoint.
	 */
	protected static function request_params() {
		$raw_input = file_get_contents('php://input');
		$json_params = json_decode($raw_input, true);
		return is_array($json_params) ? $json_params : $_POST;
	}
}

?>
