<?php
/**
 * API Auth Endpoints
 *
 * Serves /api/v1/auth/*: per-user session-key provisioning and lifecycle.
 *
 *   POST /api/v1/auth/login    — unauthenticated; email + password → session key pair
 *   GET  /api/v1/auth/session  — key-authenticated (either type); user/tier summary
 *   POST /api/v1/auth/logout   — session-key-authenticated; revokes the presented key
 *
 * Dispatched from api/apiv1.php in two places, mirroring ApiFormEndpoint:
 * dispatchPreAuth() before the key-header requirement (login is the
 * unauthenticated entry point), dispatchAuthenticated() after $api_user
 * resolves. HTTPS enforcement and both rate limiters run before either.
 * Uses the api_error()/api_success() helpers defined in apiv1.php.
 *
 * @version 1.0.0
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

		if (!in_array($endpoint, array('session', 'logout'))) {
			api_error('Unknown auth endpoint: ' . $endpoint, 'ActionError', 404);
		}
	}

	/**
	 * Post-authentication dispatch for session/logout. Always exits.
	 */
	public static function dispatchAuthenticated($url_segments, $api_entry, $api_user) {
		$endpoint = strtolower($url_segments[3] ?? '');

		if ($endpoint === 'session') {
			self::handle_session($api_user);
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

		require_once(PathHelper::getIncludePath('data/users_class.php'));
		$user = User::GetByEmail($email);

		// One failure path for unknown email, deleted user, and wrong
		// password — no account-existence oracle, and every failure counts
		// toward the api_auth rate limit.
		if (!$user || $user->get('usr_delete_time') || !$user->check_password($password)) {
			RequestLogger::log('api_auth', 'auth/login', false, [
				'user_id' => $user ? $user->key : NULL,
				'status_code' => 401,
				'error_type' => 'AuthenticationError',
				'note' => 'Invalid login credentials'
			]);
			api_error('Invalid email or password', 'AuthenticationError', 401);
		}

		$minted = ApiKey::CreateSessionKey($user->key, $device_label);
		$api_key = $minted['api_key'];

		RequestLogger::log('api_auth', 'auth/login', true, [
			'user_id' => $user->key,
			'status_code' => 200
		]);

		api_success(array(
			'public_key' => $api_key->get('apk_public_key'),
			'secret_key' => $minted['secret_key'],
			'expires_time' => $api_key->get('apk_expires_time'),
			'user' => self::user_summary($user),
		), 'Login successful');
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

		if (!$api_entry->is_session()) {
			RequestLogger::log('api_auth', 'auth/logout', false, [
				'user_id' => $api_user->key,
				'status_code' => 403,
				'error_type' => 'AuthenticationError',
				'note' => 'Machine key on logout'
			]);
			api_error('Machine keys cannot log out; revoke them from the admin API Keys page', 'AuthenticationError', 403);
		}

		$api_entry->soft_delete();

		RequestLogger::log('api_auth', 'auth/logout', true, [
			'user_id' => $api_user->key,
			'status_code' => 200
		]);

		api_success(new stdClass(), 'Session revoked');
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
