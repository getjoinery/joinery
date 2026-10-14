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
 *                         against a small contract (key scope + capability +
 *                         machine-key + user floor). Called by the CRUD verbs,
 *                         the logic endpoint, and the management router.
 *   refuseScopedKeyOutsideItsScope() — keep a scoped key off every route
 *                         family its scope cannot name; apiv1.php calls it
 *                         before any family but action dispatch runs.
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
 * SCOPE — a machine key may carry apk_scope, a list of entries: action names
 * ('dns_filtering/resolver_snapshot') and record entries ('Event:read', one
 * of read|create|update|delete per entry). A scoped key reaches those and
 * nothing else: authorize() refuses it on every endpoint its scope does not
 * name (an action by name, a record endpoint by model and verb; forms and
 * management can never be named), and apiv1.php keeps it out of the route
 * families its scope cannot name before they run. Per-record authorization
 * applies underneath, as the key's owner. An action whose auth block declares
 * requires_scoped_key admits only a key scoped to it.
 *
 * APP CREDENTIALS — a credential limited to one app purpose (AppAccess; an app
 * password is the one kind today). A program that cannot do a passkey sign-in
 * presents it through the app-access headers (X-Joinery-App-Secret, with the
 * username it signed in as in X-Joinery-App-Username); it reaches ONLY the
 * actions whose descriptor declares that purpose (`app_purpose`), and those
 * actions accept nothing else. Its failed sign-ins count in their own bucket,
 * app_access_auth, per source network and per username. A purpose whose
 * declaring plugin is inactive is refused outright. Every rule here is keyed to
 * the purpose and the key's id, never to the kind
 * (specs/mail_apps_plugin.md § Core: app access).
 *
 * @version 1.7.1
 * @changelog 1.7.1 - A deleted owner is reported only after the secret verifies, like a disabled one.
 * @changelog 1.7.0 - A disabled account (User::actions_allowed()) is refused on every key it owns
 *   (machine, session, app password), on a browser session, and at auth/login (reason 'disabled').
 *   Only a deleted account was refused before.
 * @changelog 1.6.0 - Record entries in a scope ('Model:verb'): the record
 *   endpoints pass the model and verb to authorize() as its own argument, and
 *   refuseScopedKeyOutsideItsScope() (was refuseScopedKeyOutsideActions())
 *   lets a key through to the models its scope names.
 * @changelog 1.5.0 - App credentials: authenticate() resolves the app-access
 *   headers, refuses an undeclared purpose, opens the credential's borrowed
 *   unlock window (VaultUnlock::useCredentialWindow()) and limits failed
 *   app sign-ins in the app_access_auth bucket; authorize() matches a
 *   credential's purpose against the action's app_purpose both ways;
 *   refuseAppCredentialOutsideActions() keeps one off every other route family.
 * @changelog 1.4.0 - requires_person_credential: browser session or app session
 *   key, never a machine key (specs/fortress_mobile_apps.md § R8).
 * @changelog 1.3.0 - Scoped machine keys: authorize() takes the endpoint's
 *   name, refuses a scoped key outside its scope, and enforces
 *   requires_scoped_key; refuseScopedKeyOutsideActions() for apiv1.php.
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

		// An app credential arrives as the person typed it into their program,
		// relayed unchanged by whatever spoke the program's protocol (the mail
		// gateway). Resolving it is this class's job alone.
		if (isset($headers['x_joinery_app_secret'])) {
			self::$app_access_attempt = (string)($headers['x_joinery_app_username'] ?? '');
			self::$app_client_ip = self::appClientIp($headers);
			$source_ip = self::$app_client_ip;
			self::refuseOverAppAccessLimit(self::$app_access_attempt);
			$split = ApiKey::SplitAppPassword((string)$headers['x_joinery_app_secret']);
			if ($split === null) {
				self::auth_failure(401, 'App credential not in a recognised form', 'Incorrect username or app password');
			}
			$public_key = $split['public_key'];
			$secret_key = $split['secret_key'];
		}

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

		// What became of the account is said only to a caller holding the
		// secret: a public key alone learns nothing about it.
		if ($api_user->get('usr_delete_time')) {
			self::auth_failure(400, 'API user has been deleted', 'API user has been deleted');
		}

		// A disabled account signs in nowhere, the website included: every key
		// it owns is refused while it stays disabled, and works again if it is
		// re-enabled (disabling is undone, so its keys are not revoked).
		if (!$api_user->actions_allowed()) {
			self::auth_failure(401, 'API user is disabled', 'This account is disabled');
		}

		// An app credential is good only while its purpose is declared: the
		// declaring plugin's deactivation is what turns its credentials off.
		// And one presented through the ordinary key headers is refused — the
		// app-access path is the one that counts its failures where they belong.
		if ($api_entry->is_app_credential()) {
			if (self::$app_access_attempt === null) {
				self::auth_failure(401, 'App credential presented as an API key', 'Incorrect secret key');
			}
			if (!AppAccess::isAvailable($api_entry->purpose())) {
				self::auth_failure(401, 'App purpose "' . $api_entry->purpose() . '" is not declared by an active plugin, or is switched off',
					'Access for this kind of app is turned off on this site');
			}
		} elseif (self::$app_access_attempt !== null) {
			self::auth_failure(401, 'App-access headers carried a key that is not an app credential',
				'Incorrect username or app password');
		}

		// Authentication passed — stamp the key type onto every subsequent log
		// row and record key usage (written at most once per hour, or when the
		// address changes).
		RequestLogger::set_api_key_type($api_entry->get('apk_type'));
		$api_entry->touch_last_used($source_ip);
		self::$current_api_key = $api_entry;

		// A credential whose purpose borrows the website unlock reads sealed
		// content in its own window slot, armed whenever the person unlocks on
		// the website (docs/sealed_vault.md § Borrowed windows).
		if ($api_entry->is_app_credential() && AppAccess::borrowsUnlock($api_entry->purpose())) {
			VaultUnlock::useCredentialWindow((int)$api_entry->key, (int)$api_user->key);
		}

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
			self::no_credential('Missing public/secret key headers');
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
			self::no_credential('Browser session not logged in');
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

		if (!$api_user->actions_allowed()) {
			self::auth_failure(401, 'Session user is disabled', 'This account is disabled');
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
	 *
	 * A failed app sign-in counts in app_access_auth instead, twice: once for
	 * the network it came from and once for the username it tried, so neither
	 * a guesser hopping usernames nor one hopping addresses gets far.
	 */
	private static function auth_failure($status_code, $note, $message) {
		if (self::$app_access_attempt !== null) {
			foreach (self::appAccessBucketKeys(self::$app_access_attempt) as $key) {
				RequestLogger::log(self::APP_ACCESS_FEATURE, 'auth_failure', false, [
					'status_code' => $status_code,
					'error_type'  => 'AuthenticationError',
					'note'        => $note,
					'key'         => $key,
				]);
			}
			api_error($message, 'AuthenticationError', $status_code);
		}
		RequestLogger::log('api_auth', 'auth_failure', false, [
			'status_code' => $status_code,
			'error_type'  => 'AuthenticationError',
			'note'        => $note,
		]);
		api_error($message, 'AuthenticationError', $status_code);
	}

	// ====================================================================
	// App access — failed sign-in limits
	// ====================================================================

	/** Requests an app credential's person may make per hour (at least; the browser-session limit when higher). */
	const APP_REQUEST_LIMIT = 20000;

	/** The request-log feature failed app sign-ins count in. */
	const APP_ACCESS_FEATURE = 'app_access_auth';
	/**
	 * Failed app sign-ins allowed in the window, per source network and per
	 * username. An app password is 256 random bits, so these bound load, not
	 * guessing — which is why the per-username limit sits far above the
	 * per-network one: a stranger who knows an address, or one of the person's
	 * own devices retrying with a revoked password, must not be able to lock
	 * the person's other devices out by failing in their name.
	 */
	const APP_ACCESS_NETWORK_FAILURE_LIMIT = 60;
	const APP_ACCESS_USERNAME_FAILURE_LIMIT = 600;
	const APP_ACCESS_FAILURE_WINDOW = 900;

	/**
	 * The username an app sign-in claimed, when this request presented an app
	 * credential; null otherwise. Set first thing in authenticate().
	 * @var string|null
	 */
	private static $app_access_attempt = null;

	/**
	 * The two buckets a failed app sign-in counts in: the source network (an
	 * IPv4 address, or an IPv6 /64 — one subscriber's whole prefix) and the
	 * username, lowercased and hashed (the request log is not where mailbox
	 * addresses should collect).
	 *
	 * @return string[]
	 */
	private static function appAccessBucketKeys(string $username): array {
		$keys = array('net:' . self::sourceNetwork(self::$app_client_ip ?? ($_SERVER['REMOTE_ADDR'] ?? '')));
		$username = strtolower(trim($username));
		if ($username !== '') {
			$keys[] = 'user:' . substr(hash('sha256', $username), 0, 32);
		}
		return $keys;
	}

	/** @var string|null The address the app sign-in came from (see appClientIp()). */
	private static $app_client_ip = null;

	/**
	 * Where an app sign-in really came from. The program speaks its own
	 * protocol to a gateway on this host (the mail gateway), which calls the
	 * API over loopback and names the program's address in X-Joinery-Client-Ip
	 * — believed only from loopback, as a front proxy's forwarding header is,
	 * because only a process on this machine can be there. Anything else is
	 * the connection's own address (behind a front proxy, the one mod_remoteip
	 * already resolved). IPv4 and IPv6 alike, compared as addresses.
	 */
	public static function appClientIp(array $headers): string {
		$remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
		$packed = @inet_pton($remote);
		$loopback = $packed !== false && ($packed === inet_pton('::1')
			|| (strlen($packed) === 4 && ord($packed[0]) === 127)
			|| (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff"
				&& ord($packed[12]) === 127));
		$claimed = trim((string)($headers['x_joinery_client_ip'] ?? ''));
		if ($loopback && $claimed !== '' && @inet_pton($claimed) !== false) {
			return inet_ntop(inet_pton($claimed));
		}
		return $remote;
	}

	/** An address's limiting unit: itself for IPv4, its /64 for IPv6. */
	private static function sourceNetwork(string $ip): string {
		$packed = @inet_pton(trim($ip));
		if ($packed === false) {
			return 'unknown';
		}
		if (strlen($packed) === 16) {
			return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
		}
		return inet_ntop($packed);
	}

	/** Refuse an app sign-in once its network or its username is over the limit. Exits when over. */
	private static function refuseOverAppAccessLimit(string $username): void {
		foreach (self::appAccessBucketKeys($username) as $key) {
			$per_user = strpos($key, 'user:') === 0;
			$limit = $per_user ? self::APP_ACCESS_USERNAME_FAILURE_LIMIT : self::APP_ACCESS_NETWORK_FAILURE_LIMIT;
			$state = RequestLogger::rate_limit_state(self::APP_ACCESS_FEATURE, $limit,
				self::APP_ACCESS_FAILURE_WINDOW, false, null, $key);
			if (!$state['allowed']) {
				api_rate_limited($state, $per_user ? 'This username' : 'This address',
					$limit, self::APP_ACCESS_FAILURE_WINDOW, 'failed sign-in attempts');
			}
		}
	}

	/**
	 * Refuse a request that presented no credential at all, and exit via
	 * api_error() with the same 400 a bad key gets (no oracle for whether
	 * sessions are accepted). It guessed nothing, so it is not a failed
	 * sign-in: counted there, a signed-out app or page calling a member
	 * action a few times would lock every caller at its address out of
	 * signing in. It is logged to the general api feature instead, whose
	 * per-address limit bounds a stranger's flood. Always exits.
	 */
	private static function no_credential($note) {
		RequestLogger::log('api', 'no_credential', false, [
			'status_code' => 400,
			'error_type'  => 'AuthenticationError',
			'note'        => $note,
		]);
		api_error('Public/secret keys not present', 'AuthenticationError', 400);
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
	 *   'requires_scoped_key'     => bool (default false) — admit only a machine
	 *                                key whose apk_scope names this endpoint.
	 *                                Refuses unscoped keys and browser sessions,
	 *                                so a general-purpose credential copied onto
	 *                                another machine cannot call it.
	 * @param ApiKey $api_entry       The authenticated key.
	 * @param int|null $user_permission The owning user's usr_permission, or null
	 *                                for the anonymous browser-session principal
	 *                                (null is the anonymity signal — never pass
	 *                                null for a real user).
	 * @param string $message_prefix  Surface label for the 403 body.
	 * @param string|null $endpoint   The action's name ('{plugin}/{action}' or
	 *                                '{action}'), matched against a scoped key's
	 *                                action entries. Null for every other
	 *                                surface.
	 * @param array|null $record      ['model' => class, 'verb' => read|create|
	 *                                update|delete], passed by the record (CRUD)
	 *                                endpoints alone and matched against a scoped
	 *                                key's record entries. A separate argument,
	 *                                not a contract key, so no descriptor's auth
	 *                                block can name a record.
	 * @return void Returns when authorized; otherwise exits.
	 */
	public static function authorize(array $auth, $api_entry, $user_permission, $message_prefix = 'Endpoint', $endpoint = null, $record = null) {
		// Anonymous gate first — fails closed before every other check. The
		// 401 body matches the missing-credential shape: no oracle separating
		// "this action exists but needs login" from "no credential presented".
		if ($user_permission === null && empty($auth['allow_guest'])) {
			api_error($message_prefix . ' requires authentication', 'AuthenticationError', 401);
		}
		// App-purpose gate, both ways. A credential limited to a purpose reaches
		// only an endpoint declaring that purpose (CRUD, forms and management
		// declare none, so they always refuse it); and an endpoint declaring a
		// purpose is for that purpose's credentials alone — its logic runs on the
		// credential's identity (its borrowed unlock window, its message
		// numbering), which nothing else has.
		$credential_purpose = ($api_entry && $api_entry->is_app_credential()) ? $api_entry->purpose() : null;
		$endpoint_purpose = isset($auth['app_purpose']) && $auth['app_purpose'] !== '' ? (string)$auth['app_purpose'] : null;
		if ($credential_purpose !== null && $credential_purpose !== $endpoint_purpose) {
			api_error($message_prefix . ': this credential is limited to ' . AppAccess::label($credential_purpose),
				'AuthenticationError', 403);
		}
		if ($endpoint_purpose !== null && $credential_purpose !== $endpoint_purpose) {
			api_error($message_prefix . ' is available only to ' . AppAccess::label($endpoint_purpose),
				'AuthenticationError', 403);
		}

		// Scope gate. A scoped key reaches only what its scope names: an action
		// by its name, a record endpoint by model and verb. Forms, management
		// and every other surface pass neither, so no scope reaches them.
		if ($api_entry && $api_entry->is_scoped()
			&& !self::scopeAdmits($api_entry, $endpoint, $record)) {
			api_error($message_prefix . ': this key is limited to ' . $api_entry->scope_label(),
				'AuthenticationError', 403);
		}
		// The inverse: an endpoint that admits only a key scoped to it. The
		// gate above already refused a key scoped elsewhere, so what remains
		// to refuse is a browser session, an unscoped key, and a scoped key
		// that is somehow not a machine key.
		if (!empty($auth['requires_scoped_key'])
			&& (!$api_entry || !$api_entry->is_scoped()
				|| $api_entry->get('apk_type') !== ApiKey::TYPE_MACHINE)) {
			api_error($message_prefix . ' requires a machine key scoped to it', 'AuthenticationError', 403);
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

		// Person-credential gate: the browser session (no key row) or an app's
		// session key. A machine key is the one credential refused.
		if (!empty($auth['requires_person_credential']) && $api_entry !== null
			&& $api_entry->get('apk_type') !== ApiKey::TYPE_SESSION) {
			api_error($message_prefix . ' is available only to a signed-in person (browser or app)', 'AuthenticationError', 403);
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

	/**
	 * Whether a scoped key's entries name this endpoint: an action by name,
	 * or a record endpoint by model and verb.
	 *
	 * @param ApiKey      $api_entry A scoped key.
	 * @param string|null $endpoint  The action's name, or null.
	 * @param array|null  $record    ['model' => class, 'verb' => verb], or null.
	 */
	protected static function scopeAdmits($api_entry, $endpoint, $record): bool {
		if ($endpoint !== null) {
			return $api_entry->scope_allows_action((string)$endpoint);
		}
		if (is_array($record) && isset($record['model'], $record['verb'])) {
			return $api_entry->scope_allows_record((string)$record['model'], (string)$record['verb']);
		}
		return false;
	}

	/**
	 * Refuse a scoped key on every route family outside its scope, before the
	 * family runs. Action dispatch is left to authorize(), which matches the
	 * action's name. A record (CRUD) request is let through only when its URL
	 * names a model the scope has an entry for, exactly: 'Event' (one record)
	 * or 'Events' (the list), after apiv1.php's ucwords(); the record
	 * endpoint then checks the verb in authorize(). Everything else is
	 * refused here, including the families that never call authorize()
	 * (auth/*, app/*, drive_upload, the GET actions listing). No model may
	 * share a name with a route family; tests/unit/api_scoped_key_test.php
	 * holds that.
	 *
	 * @param ApiKey|null $api_entry The authenticated key (null for a browser session).
	 * @param string      $segment   The route segment after /api/v1/, as sent.
	 */
	public static function refuseScopedKeyOutsideItsScope($api_entry, $segment) {
		if (!$api_entry || !$api_entry->is_scoped()) {
			return;
		}
		$segment = (string)$segment;
		if (strtolower($segment) === 'action') {
			return;
		}
		// Exactly 'Model' or 'Models'. The router takes any last character off
		// for a list, so this is stricter than routing on purpose: dropping
		// an arbitrary letter would let 'auth' through for a model named 'Aut'.
		// The name must also be a model this site has: a stored entry naming
		// a route family ('Auth:read') opens nothing, whatever wrote it.
		$operation = ucwords($segment);
		foreach (array_keys($api_entry->scope_records()) as $model) {
			if (($operation === $model || $operation === $model . 's') && ApiKey::is_model_class($model)) {
				return;
			}
		}
		api_error(($segment !== '' ? strtolower($segment) : 'This endpoint') . ': this key is limited to '
			. $api_entry->scope_label(), 'AuthenticationError', 403);
	}

	/**
	 * Refuse an app credential on a route family other than action dispatch —
	 * auth/* (the web-session bridge included), app/*, forms, CRUD,
	 * management, drive_upload, the actions listing. Its purpose names only
	 * actions; authorize() then matches it against each action's app_purpose.
	 *
	 * @param ApiKey|null $api_entry The authenticated key (null for a browser session).
	 * @param string      $family    The route family, for the 403 body.
	 */
	public static function refuseAppCredentialOutsideActions($api_entry, $family) {
		if ($api_entry && $api_entry->is_app_credential()) {
			api_error(($family !== '' ? $family : 'This endpoint') . ': this credential is limited to '
				. AppAccess::label($api_entry->purpose()), 'AuthenticationError', 403);
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
	 *               for failure logging), plus a 'reason' when the credentials were
	 *               valid: 'disabled' (the account is disabled) or 'activation_required'
	 *               (it is unactivated).
	 */
	public static function attemptLogin($email, $password, $device_label = NULL) {
		require_once(PathHelper::getIncludePath('data/users_class.php'));
		$user = User::GetByEmail($email);

		if (!$user || $user->get('usr_delete_time') || !$user->check_password($password)) {
			return array('ok' => false, 'user' => $user ?: null);
		}

		// Said only after the password verifies, like activation below.
		if (!$user->actions_allowed()) {
			return array('ok' => false, 'user' => $user, 'reason' => 'disabled');
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
