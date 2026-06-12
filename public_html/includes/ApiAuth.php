<?php
/**
 * ApiAuth
 *
 * The single home for the REST API's security boundary — both axes of "who are
 * you and what may you do," plus the credential-lifecycle decisions the
 * /auth/* endpoints delegate here.
 *
 *   authenticate()      — resolve + validate an API key from request headers and
 *                         load its user, returning the authenticated principal
 *                         (or exiting 4xx). This is the chain every authenticated
 *                         request runs through.
 *   authorize()         — decide whether a principal may invoke an endpoint,
 *                         against a small contract (capability + machine-key +
 *                         user floor). Called by the CRUD verbs, the logic
 *                         endpoint, and the management router.
 *   attemptLogin()      — verify an email/password and mint a session key.
 *   revokeSessionKey()  — revoke a presented session key (logout).
 *
 * This class holds pure auth *decisions* — it returns values or exits via
 * api_error(); it does not parse request bodies or shape success responses.
 * The HTTP plumbing for /auth/* lives in ApiAuthEndpoint, which is a thin shell
 * over attemptLogin()/revokeSessionKey(). Uses the api_error() helper and the
 * RequestLogger defined/loaded by apiv1.php.
 *
 * AUTHORIZATION — the apk_permission axis is NON-MONOTONIC: permission 2 is
 * write-only (it can write but cannot read), so authorization is expressed as a
 * capability (read / write / delete), each mapping to the exact comparison the
 * CRUD verbs use:
 *     read   → deny if apk_permission == 2
 *     write  → deny if apk_permission < 2
 *     delete → deny if apk_permission < 4
 * The user role axis (usr_permission) is a simple floor (e.g. management = 10).
 * See specs/implemented/api_auth_gate_unification.md for the equivalence table.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('data/api_keys_class.php'));

class ApiAuth {

	// Capability values for the apk_permission (CRUD) axis.
	const CAP_READ   = 'read';    // deny if apk_permission == 2 (write-only key)
	const CAP_WRITE  = 'write';   // deny if apk_permission < 2
	const CAP_DELETE = 'delete';  // deny if apk_permission < 4

	// ====================================================================
	// Authentication — resolve the principal from request headers
	// ====================================================================

	/**
	 * Authenticate a request: resolve the API key from headers, validate its
	 * status (active, not deleted, within its start/expiry window, IP allowed),
	 * verify the secret, and load the owning user. Every failure is logged to
	 * the api_auth feature (which feeds the failed-auth rate limiter) and exits
	 * via api_error(). On success, stamps the key type onto subsequent log rows,
	 * records key usage, and returns the principal.
	 *
	 * @param array  $headers   Lowercased-underscore header map (public_key, secret_key).
	 * @param string $source_ip Client IP for the apk_ip_restriction check.
	 * @return array ['api_entry' => ApiKey, 'api_user' => User, 'auth_data' => array]
	 */
	public static function authenticate(array $headers, $source_ip) {
		$public_key = isset($headers['public_key']) ? $headers['public_key'] : null;
		$secret_key = isset($headers['secret_key']) ? $headers['secret_key'] : null;

		if (!$public_key || !$secret_key) {
			self::auth_failure(400, 'Missing public/secret key headers', 'Public/secret keys not present');
		}

		try {
			$api_entry = ApiKey::GetByColumn('apk_public_key', $public_key);
		} catch (Exception $e) {
			self::auth_failure(400, 'Unable to find the api key', 'Unable to find the api key');
		}

		if (!$api_entry->key) {
			self::auth_failure(400, 'Unable to find the api key', 'Unable to find the api key');
		}

		// Validate API key status
		if ($api_entry->get('apk_delete_time')) {
			self::auth_failure(401, 'API key has been revoked', 'API key has been revoked');
		}

		if (!$api_entry->get('apk_is_active')) {
			self::auth_failure(401, 'API key is not active', 'API key is not active');
		}

		if ($api_entry->get('apk_start_time')) {
			if (gmdate('Y-m-d H:i:s') < $api_entry->get('apk_start_time')) {
				self::auth_failure(401, 'API key is not yet active', 'API key is not yet active');
			}
		}

		if ($api_entry->get('apk_expires_time')) {
			if (gmdate('Y-m-d H:i:s') > $api_entry->get('apk_expires_time')) {
				self::auth_failure(401, 'API key has expired', 'API key has expired');
			}
		}

		try {
			$api_user = new User($api_entry->get('apk_usr_user_id'), TRUE);
		} catch (Exception $e) {
			self::auth_failure(400, 'Unable to find the api user', 'Unable to find the api user');
		}

		if (!$api_user->key) {
			self::auth_failure(400, 'Unable to find the api user', 'Unable to find the api user');
		}

		if ($api_user->get('usr_delete_time')) {
			self::auth_failure(400, 'API user has been deleted', 'API user has been deleted');
		}

		if ($authorized_ips = $api_entry->get('apk_ip_restriction')) {
			$ip_list = array_map('trim', str_getcsv($authorized_ips));
			if (count($ip_list) && !in_array($source_ip, $ip_list)) {
				self::auth_failure(401, 'Unauthorized IP: ' . $source_ip, 'Unauthorized IP');
			}
		}

		if (!$api_entry->check_secret_key($secret_key)) {
			self::auth_failure(401, 'Incorrect secret key', 'Incorrect secret key');
		}

		// Authentication passed — stamp the key type onto every subsequent log
		// row and record key usage (written at most once per hour).
		RequestLogger::set_api_key_type($api_entry->get('apk_type'));
		$api_entry->touch_last_used();

		return array(
			'api_entry' => $api_entry,
			'api_user'  => $api_user,
			'auth_data' => array(
				'current_user_id'         => $api_user->key,
				'current_user_permission' => $api_user->get('usr_permission'),
			),
		);
	}

	/**
	 * Log an authentication failure to the api_auth feature (feeding the
	 * failed-auth rate limiter) and exit via api_error(). Always exits.
	 */
	private static function auth_failure($status_code, $note, $message) {
		RequestLogger::log('api_auth', 'auth_failure', false, [
			'status_code' => $status_code,
			'error_type'  => 'AuthenticationError',
			'note'        => $note,
		]);
		api_error($message, 'AuthenticationError', $status_code);
	}

	// ====================================================================
	// Authorization — decide whether a principal may invoke an endpoint
	// ====================================================================

	/**
	 * Enforce an authorization contract, or exit via api_error() with 403.
	 * Each caller supplies a default equal to the constant its surface
	 * historically hardcoded; a descriptor's ['auth'] block can override.
	 *
	 * @param array  $auth Recognized keys (all optional):
	 *   'capability'           => 'read'|'write'|'delete'|null (null = no apk_permission check)
	 *   'requires_machine_key' => bool (default false)
	 *   'min_user_permission'  => int  (default 0)
	 * @param ApiKey $api_entry       The authenticated key.
	 * @param int    $user_permission The owning user's usr_permission.
	 * @param string $message_prefix  Surface label for the 403 body.
	 * @return void Returns when authorized; otherwise exits.
	 */
	public static function authorize(array $auth, $api_entry, $user_permission, $message_prefix = 'Endpoint') {
		// Machine-key gate first — fails closed before any finer-grained check.
		// Null-safe: a missing key is, by definition, not a machine key.
		if (!empty($auth['requires_machine_key'])
			&& (!$api_entry || $api_entry->get('apk_type') !== ApiKey::TYPE_MACHINE)) {
			api_error($message_prefix . ' requires a machine key', 'AuthenticationError', 403);
		}

		// Capability gate (apk_permission). Null = this surface does not gate on it.
		$capability = isset($auth['capability']) ? $auth['capability'] : null;
		if ($capability !== null) {
			$permission = (int) $api_entry->get('apk_permission');
			$blocked =
				($capability === self::CAP_READ   && $permission == 2) ||
				($capability === self::CAP_WRITE  && $permission < 2)  ||
				($capability === self::CAP_DELETE && $permission < 4);
			if ($blocked) {
				api_error($message_prefix . ': insufficient API key permission', 'AuthenticationError', 403);
			}
		}

		// User role floor (usr_permission).
		$min_user_permission = isset($auth['min_user_permission']) ? (int) $auth['min_user_permission'] : 0;
		if ((int) $user_permission < $min_user_permission) {
			api_error($message_prefix . ': insufficient user permission', 'AuthenticationError', 403);
		}
	}

	// ====================================================================
	// Credential lifecycle — the decisions /auth/* endpoints delegate here
	// ====================================================================

	/**
	 * Verify an email/password and, on success, mint a session key. One failure
	 * shape for unknown email, deleted user, and wrong password — no
	 * account-existence oracle. Returns a result the endpoint shell shapes into
	 * a response; performs no logging or HTTP itself.
	 *
	 * @return array On success: ['ok'=>true, 'user'=>User, 'api_key'=>ApiKey, 'secret_key'=>string].
	 *               On failure: ['ok'=>false, 'user'=>User|null] (user set when found-but-invalid,
	 *               for failure logging).
	 */
	public static function attemptLogin($email, $password, $device_label = NULL) {
		require_once(PathHelper::getIncludePath('data/users_class.php'));
		$user = User::GetByEmail($email);

		if (!$user || $user->get('usr_delete_time') || !$user->check_password($password)) {
			return array('ok' => false, 'user' => $user ?: null);
		}

		$minted = ApiKey::CreateSessionKey($user->key, $device_label);
		return array(
			'ok'         => true,
			'user'       => $user,
			'api_key'    => $minted['api_key'],
			'secret_key' => $minted['secret_key'],
		);
	}

	/**
	 * Revoke a presented session key (logout). Machine keys are revoked from the
	 * admin page, never by themselves.
	 *
	 * @return bool true if revoked; false if the key is a machine key (the
	 *              endpoint maps this to 403).
	 */
	public static function revokeSessionKey($api_entry) {
		if (!$api_entry->is_session()) {
			return false;
		}
		$api_entry->soft_delete();
		return true;
	}
}
?>
