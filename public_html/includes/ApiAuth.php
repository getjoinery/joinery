<?php
/**
 * ApiAuth
 *
 * The single home for the REST API's security boundary — both axes of "who are
 * you and what may you do," plus the credential-lifecycle decisions the
 * /auth/* endpoints delegate here.
 *
 *   authenticate()      — resolve + validate the request's credential and load
 *                         its user, returning the authenticated principal (or
 *                         exiting 4xx). Two credential kinds: API key headers
 *                         (primary — always win when present), or a logged-in
 *                         web session cookie + X-Joinery-Csrf header (the
 *                         browser-session credential, letting page JS call the
 *                         same /api/v1 actions apps use). A browser-session
 *                         principal has api_entry === null and carries the same
 *                         full capability a freshly minted session key gets.
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
 * @version 1.2.0
 * @changelog 1.2.0 - Anonymous browser-session principal: a session cookie with
 *   a valid X-Joinery-Csrf proof but no logged-in user authenticates as an
 *   anonymous principal (api_user === null). authorize() denies it 401 unless
 *   the contract declares allow_guest; apiv1.php confines it to action dispatch.
 * @changelog 1.1.0 - Browser-session credential: a logged-in web session cookie
 *   plus a matching X-Joinery-Csrf header authenticates as that user when no
 *   key headers are present. Keys take precedence; the management API's
 *   machine-key gate rejects browser sessions unchanged (null is not a machine
 *   key). The session lock is released immediately after identity is read.
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
	/**
	 * @var ApiKey|null The key row this request authenticated with, or null for
	 * the browser-session credential. Set by authenticate(); stays null on a
	 * request that never reached the API at all (an ordinary page render), which
	 * is the same answer for the same reason — no key was presented.
	 */
	private static $current_api_key = null;

	/** The key this request authenticated with, or null for a browser session. */
	public static function currentApiKey() {
		return self::$current_api_key;
	}

	/**
	 * Does this request carry a browser session rather than an API key?
	 *
	 * The question worth asking of it: **can this caller's later fetches present
	 * a session cookie?** A browser can, so anything keyed to session state — a
	 * Sealed Vault unlock window above all (`docs/sealed_vault.md`) — is reachable
	 * for it. An API-key caller cannot, so handing it a URL whose bytes need a
	 * window produces a guaranteed 423.
	 *
	 * True for an ordinary page render too: no key was presented there either,
	 * and a page render is the browser-session case by definition.
	 */
	public static function isBrowserSessionPrincipal(): bool {
		return self::$current_api_key === null;
	}

	public static function authenticate(array $headers, $source_ip) {
		$public_key = isset($headers['public_key']) ? $headers['public_key'] : null;
		$secret_key = isset($headers['secret_key']) ? $headers['secret_key'] : null;

		if (!$public_key || !$secret_key) {
			// No key headers — the request may instead carry the browser-session
			// credential (web session cookie + X-Joinery-Csrf header). Keys always
			// take precedence when present; this path only runs keyless.
			return self::authenticateBrowserSession($headers);
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
			// Empty escape (RFC 4180) rather than the deprecated default. An IP
			// allowlist holds neither backslashes nor quotes, so this parses the
			// same either way; it is here so no CSV call in the tree relies on a
			// default PHP 8.4 deprecates.
			$ip_list = array_map('trim', str_getcsv($authorized_ips, ',', '"', ''));
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
		self::$current_api_key = $api_entry;

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
	 * Authenticate a keyless request from its web session: session cookie plus
	 * an X-Joinery-Csrf header matching the session's API CSRF token (minted at
	 * session construction — see SessionControl::get_api_csrf_token()). On
	 * success the principal has api_entry === null; identity and permission
	 * come from the session exactly as web pages see them (so login-as and the
	 * IP-change guard behave identically to the web surface).
	 *
	 * A session with a valid CSRF proof but no logged-in user authenticates as
	 * the ANONYMOUS principal (api_user === null): the proof establishes
	 * "same-origin JS running in this visitor's browser", nothing more.
	 * authorize() denies anonymous principals everywhere an action does not
	 * declare allow_guest, and apiv1.php blocks them from every route family
	 * except action dispatch.
	 *
	 * The API only ever READS session state: the session file lock is released
	 * via session_write_close() as soon as identity is read, so parallel page
	 * JS calls are not serialized. Actions that must persist $_SESSION writes
	 * (e.g. the cart) declare auth.session_write, which re-opens the session
	 * in ApiLogicEndpoint::executeAction() — see SessionControl::reopen().
	 *
	 * Failure shapes: a request with no session cookie — or a session with
	 * neither user nor CSRF header — fails exactly as a keyless request always
	 * has (400, no oracle for whether sessions are accepted); a session
	 * presenting a wrong/missing token where one was expected is 403.
	 *
	 * @param array $headers Lowercased-underscore header map (x_joinery_csrf).
	 * @return array Same principal shape as authenticate(), api_entry = null;
	 *               api_user is null for the anonymous principal.
	 */
	private static function authenticateBrowserSession(array $headers) {
		// No session cookie → plain anonymous keyless request; fail as always.
		if (empty($_COOKIE[session_name()])) {
			self::auth_failure(400, 'Missing public/secret key headers', 'Public/secret keys not present');
		}

		// Starting the session is safe here: this branch is only reached with
		// no key headers, so key-authenticated requests stay session-free.
		$session = SessionControl::get_instance();
		$user_id = $session->get_user_id();
		$session_token = $session->get_raw('api_csrf_token');
		$session_permission = $session->get_permission();
		session_write_close();

		$csrf_header = isset($headers['x_joinery_csrf']) ? (string) $headers['x_joinery_csrf'] : '';

		if (!$user_id && $csrf_header === '') {
			// Stale/anonymous session cookie with no CSRF attempt — same shape
			// as no credential at all.
			self::auth_failure(400, 'Browser session not logged in', 'Public/secret keys not present');
		}

		if (!$session_token || $csrf_header === '' || !hash_equals($session_token, $csrf_header)) {
			self::auth_failure(403, 'Browser session CSRF token missing or invalid', 'Invalid or missing X-Joinery-Csrf token');
		}

		if (!$user_id) {
			// Anonymous principal: valid same-origin proof, no user. Carries no
			// identity and no permission; allow_guest actions are its entire
			// reachable surface. Permission is null, not 0 — null is the one
			// anonymity signal (authorize() keys on it), so the guest can never
			// be mistaken for an authenticated permission-0 user by anything
			// that consumes auth_data.
			RequestLogger::set_api_key_type('guest');
			return array(
				'api_entry' => null,
				'api_user'  => null,
				'auth_data' => array(
					'current_user_id'         => null,
					'current_user_permission' => null,
				),
			);
		}

		try {
			$api_user = new User($user_id, TRUE);
		} catch (Exception $e) {
			self::auth_failure(400, 'Unable to find the session user', 'Unable to find the api user');
		}

		if (!$api_user->key || $api_user->get('usr_delete_time')) {
			self::auth_failure(400, 'Session user has been deleted', 'API user has been deleted');
		}

		RequestLogger::set_api_key_type('browser');

		return array(
			'api_entry' => null,
			'api_user'  => $api_user,
			'auth_data' => array(
				'current_user_id'         => $api_user->key,
				// Session permission, not usr_permission: mirrors what web pages
				// enforce (admin login-as elevation, IP-change guard zeroing).
				'current_user_permission' => $session_permission,
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
	 *   'capability'              => 'read'|'write'|'delete'|null (null = no apk_permission check)
	 *   'requires_machine_key'    => bool (default false)
	 *   'requires_browser_session'=> bool (default false) — reject any API-key
	 *                                credential; the action is reachable only via
	 *                                the browser-session credential (session cookie
	 *                                + CSRF). For operations bound to session state
	 *                                (e.g. a Sealed Vault unlock window keyed to the
	 *                                session id) so the boundary is declared, not
	 *                                left to incidental session-plumbing behavior.
	 *   'allow_guest'             => bool (default false) — accept the anonymous
	 *                                browser-session principal (valid CSRF proof,
	 *                                no logged-in user). Without this flag an
	 *                                anonymous principal is denied 401 before any
	 *                                other check, so every contract that does not
	 *                                opt in stays guest-free with no audit needed.
	 *   'min_user_permission'     => int  (default 0)
	 * @param ApiKey $api_entry       The authenticated key.
	 * @param int|null $user_permission The owning user's usr_permission, or null
	 *                                for the anonymous browser-session principal
	 *                                (null is the anonymity signal — never pass
	 *                                null for a real user).
	 * @param string $message_prefix  Surface label for the 403 body.
	 * @return void Returns when authorized; otherwise exits.
	 */
	public static function authorize(array $auth, $api_entry, $user_permission, $message_prefix = 'Endpoint') {
		// Anonymous gate first — fails closed before every other check. The
		// 401 body matches the missing-credential shape: no oracle separating
		// "this action exists but needs login" from "no credential presented".
		if ($user_permission === null && empty($auth['allow_guest'])) {
			api_error($message_prefix . ' requires authentication', 'AuthenticationError', 401);
		}
		// Machine-key gate first — fails closed before any finer-grained check.
		// Null-safe: a missing key is, by definition, not a machine key.
		if (!empty($auth['requires_machine_key'])
			&& (!$api_entry || $api_entry->get('apk_type') !== ApiKey::TYPE_MACHINE)) {
			api_error($message_prefix . ' requires a machine key', 'AuthenticationError', 403);
		}

		// Browser-session gate — the inverse of the machine-key gate. A browser
		// session presents no key row ($api_entry === null); any non-null entry
		// is an API key and is refused. Native apps ride the same browser-session
		// bridge, so they satisfy this too.
		if (!empty($auth['requires_browser_session']) && $api_entry !== null) {
			api_error($message_prefix . ' is available only to a signed-in browser session', 'AuthenticationError', 403);
		}

		// Capability gate (apk_permission). Null = this surface does not gate on it.
		$capability = isset($auth['capability']) ? $auth['capability'] : null;
		if ($capability !== null) {
			// A browser-session principal has no key row; it carries the same
			// full capability (4) a freshly minted session key gets — the
			// credential IS the user, so the user's role is the real limit.
			$permission = $api_entry ? (int) $api_entry->get('apk_permission') : 4;
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
	 * When activation_required_login is on, an unactivated account is refused
	 * here exactly as login_logic refuses it a web session — same setting, same
	 * gate, both doors — and the activation email is re-sent. The reason is
	 * only revealed after the password verifies, so it is no account-existence
	 * oracle.
	 *
	 * @return array On success: ['ok'=>true, 'user'=>User, 'api_key'=>ApiKey, 'secret_key'=>string].
	 *               On failure: ['ok'=>false, 'user'=>User|null] (user set when found-but-invalid,
	 *               for failure logging), plus 'reason'=>'activation_required' when
	 *               the credentials were valid but the account is unactivated.
	 */
	public static function attemptLogin($email, $password, $device_label = NULL) {
		require_once(PathHelper::getIncludePath('data/users_class.php'));
		$user = User::GetByEmail($email);

		if (!$user || $user->get('usr_delete_time') || !$user->check_password($password)) {
			return array('ok' => false, 'user' => $user ?: null);
		}

		$settings = Globalvars::get_instance();
		if ($settings->get_setting('activation_required_login') && !$user->get('usr_is_activated')) {
			require_once(PathHelper::getIncludePath('includes/Activation.php'));
			Activation::email_activate_send($user);
			return array('ok' => false, 'user' => $user, 'reason' => 'activation_required');
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
	 * admin page, never by themselves. A browser-session principal has no key to
	 * revoke — browsers sign out on the website (/logout).
	 *
	 * @return bool true if revoked; false if there is no revocable key (machine
	 *              key or browser session — the endpoint maps this to 403).
	 */
	public static function revokeSessionKey($api_entry) {
		if (!$api_entry || !$api_entry->is_session()) {
			return false;
		}
		$api_entry->soft_delete();
		return true;
	}
}
?>
