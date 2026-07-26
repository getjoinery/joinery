<?php
/**
 * API v1 Endpoint
 *
 * @version 2.14
 * @changelog 2.13 - Drive chunk transport logs its OUTCOME: the api_upload
 *   request-log row is written by DriveUploadTransport's response helpers
 *   (success on 2xx, failure with status code otherwise) instead of a
 *   pre-dispatch success stamp here. Rate-limit counting is unchanged.
 * @changelog 2.12 - Descriptor consumption: action discovery and the action/
 *   form endpoints resolve metadata from _logic_descriptor() first with
 *   _logic_api() as the legacy fallback; discovery exposes each action's
 *   typed input schema; descriptor-declared input is validated at the action
 *   boundary (DescriptorValidator, 422 ValidationError on hard failure).
 * @changelog 2.11 - App platform endpoints (/api/v1/app/*, ApiAppEndpoint) and
 *   auth/web_session (ApiAuthEndpoint): the navigation routing table and the
 *   web-session bridge consumed by native apps. See docs/mobile_apps.md.
 * @changelog 2.10 - Idempotent writes (specs/implemented/api_contract_and_idempotency.md
 *   § Change 2): authenticated action requests may carry an Idempotency-Key
 *   header — dedup/replay/conflict handled in ApiLogicEndpoint against the
 *   aik_api_idempotency_keys table; header added to the CORS allow-list.
 *   Requests without the header are unchanged.
 * @changelog 2.9 - Contract freeze (specs/implemented/api_contract_and_idempotency.md § Change 1):
 *   error envelope always carries an object `data` (was '' from api_error), the
 *   `error` string is the message itself (dropped the 'Error: ' prefix), and
 *   collection pagination fields (num_results, page, numperpage) are integers.
 *   The pinned contract lives in docs/api.md § Contract.
 * @changelog 2.8 - Browser-session credential: keyless requests carrying a
 *   logged-in web session cookie + X-Joinery-Csrf header authenticate as that
 *   user (ApiAuth::authenticateBrowserSession). Key headers take precedence;
 *   anonymous keyless requests fail exactly as before.
 * @changelog 2.7 - Authentication chain extracted to ApiAuth::authenticate();
 *   the front controller now resolves the principal in one call. Authorization
 *   (former ApiAuthGate) folded into ApiAuth::authorize(); CRUD verbs call it
 *   with a capability. Behavior unchanged (see ApiAuth).
 * @changelog 2.6 - Authorization unified through a single gate: the five CRUD
 *   verb gates declare a capability (read/write/delete) instead of inlining the
 *   apk_permission comparison; behavior is unchanged.
 * @changelog 2.5 - Plugin-aware actions: {plugin}/{action} names resolve via
 *   api_resolve_logic_path(); discovery lists active plugins' actions under
 *   their namespaced names
 * @changelog 2.4 - User session keys: /auth/* endpoints, pre-auth dispatch of
 *   sessionless actions, client version handshake (426 UpgradeRequired),
 *   last-used tracking, key type stamped onto request logs
 */
require_once(__DIR__ . '/../includes/PathHelper.php');

$settings = Globalvars::get_instance();
require_once(PathHelper::getIncludePath('data/api_keys_class.php'));
require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
require_once(PathHelper::getIncludePath('includes/ApiAuth.php'));

// Track request start time for response_ms logging
$api_start_time = microtime(true);

/**
 * Send a JSON error response and exit.
 */
function api_error($message, $error_type = 'TransactionError', $status_code = 400) {
	header("Content-Type: application/json");
	http_response_code($status_code);
	echo json_encode(array(
		'api_version' => '1.0',
		'errortype' => $error_type,
		'error' => $message,
		'data' => new stdClass()
	)) . PHP_EOL;
	exit;
}

/**
 * Send a JSON success response and exit.
 */
function api_success($data, $message = '', $status_code = 200, $extra = array()) {
	$response = array(
		'api_version' => '1.0',
		'success_message' => $message,
		'data' => $data
	);
	if ($extra) {
		$response = array_merge($response, $extra);
	}
	header("Content-Type: application/json");
	http_response_code($status_code);
	echo json_encode($response) . PHP_EOL;
	exit;
}

/**
 * Convert a LogicResult to an API JSON response.
 *
 * @param LogicResult $result
 * @param string $action_name
 * @return array ['response' => array, 'status_code' => int]
 */
function api_translate_logic_result($result, $action_name) {
	if ($result->error) {
		$response = array(
			'api_version' => '1.0',
			'errortype' => !empty($result->validation_errors) ? 'ValidationError' : 'ActionError',
			'error' => $result->error,
			'data' => $result->data ?: new stdClass()
		);

		if (!empty($result->validation_errors)) {
			$response['validation_errors'] = $result->validation_errors;
		}

		return array('response' => $response, 'status_code' => 422);
	}

	$response = array(
		'api_version' => '1.0',
		'success_message' => 'Action \'' . $action_name . '\' completed successfully.',
		'data' => $result->data ?: new stdClass()
	);

	if ($result->redirect) {
		$response['redirect'] = $result->redirect;
	}

	return array('response' => $response, 'status_code' => 200);
}

/**
 * Resolve the path segments after /api/v1/{action|form}/ to a logic file,
 * require it, and return naming metadata. Used by ApiLogicEndpoint (both the
 * action and form faces). Exits via api_error() on any failure.
 *
 * Two name forms:
 *   - {action}            core action; resolves through the theme chain
 *                         (theme -> base logic/), exactly as views do
 *   - {plugin}/{action}   plugin action; resolves directly to
 *                         plugins/{plugin}/logic/{action}_logic.php for
 *                         active plugins only. No theme chain - themes do
 *                         not override plugin logic.
 *
 * An inactive or unknown plugin produces the same 404 as a missing action,
 * so responses do not reveal which plugins are installed.
 *
 * @param array $url_segments ['api', 'v1', '{action|form}', '{name}'] or
 *                            ['api', 'v1', '{action|form}', '{plugin}', '{action}']
 * @param string $noun 'action' or 'form' - used in error messages
 * @return array [$action_label, $action_name]
 *   $action_label - full name for logs/messages ('{plugin}/{action}' or '{action}')
 *   $action_name  - bare action name; logic functions are named from it
 */
function api_resolve_logic_path($url_segments, $noun) {
	if (empty($url_segments[3])) {
		api_error(ucfirst($noun) . ' name required', 'ActionError', 400);
	}

	$plugin_name = NULL;
	if (!empty($url_segments[4])) {
		$plugin_name = strtolower($url_segments[3]);
		$action_name = strtolower($url_segments[4]);
	} else {
		$action_name = strtolower($url_segments[3]);
	}

	// Validate each segment (security: prevent path traversal)
	if (!preg_match('/^[a-z0-9_]+$/', $action_name)
		|| ($plugin_name !== NULL && !preg_match('/^[a-z0-9_]+$/', $plugin_name))) {
		api_error('Invalid ' . $noun . ' name', 'ActionError', 400);
	}

	$action_label = ($plugin_name !== NULL) ? $plugin_name . '/' . $action_name : $action_name;

	if ($plugin_name !== NULL) {
		if (!PluginHelper::isPluginActive($plugin_name)) {
			api_error('Unknown ' . $noun . ': ' . $action_label, 'ActionError', 404);
		}
		$logic_filepath = PathHelper::getIncludePath(
			'plugins/' . $plugin_name . '/logic/' . $action_name . '_logic.php');
	} else {
		try {
			$logic_filepath = PathHelper::getThemeFilePath($action_name . '_logic.php', 'logic');
		} catch (Exception $e) {
			api_error('Unknown ' . $noun . ': ' . $action_label, 'ActionError', 404);
		}
	}

	if (!file_exists($logic_filepath)) {
		api_error('Unknown ' . $noun . ': ' . $action_label, 'ActionError', 404);
	}

	require_once($logic_filepath);

	return array($action_label, $action_name);
}

/**
 * Layer 1 helper — read a model's API exposure flag safely. Every model inherits
 * the SystemBase `false` default, so a class predating the property is simply not
 * exposed. $prop is 'api_readable' or 'api_writable'.
 */
function api_flag($class_name, $prop) {
	return (bool) $class_name::${$prop};
}

/**
 * The conventional owner column for a model ({prefix}_usr_user_id), or null if the
 * model has no such column. Used for owner-stamping (POST), owner-column dropping
 * (writes), and owner-scoping the collection query.
 */
function api_owner_column($class_name) {
	$col = $class_name::$prefix . '_usr_user_id';
	return array_key_exists($col, $class_name::$field_specifications) ? $col : null;
}

/**
 * Layer 3 (write) boundary sanitize — runs on the input map BEFORE it reaches any
 * create/update path (CreateNew() or the raw set-loop), so the write floor cannot be
 * bypassed by a model defining its own factory. Drops unwritable fields (credential
 * pattern + $api_unwritable_fields) and the owner column (never reassignable via CRUD).
 */
function api_sanitize_write_input(array $input, $class_name) {
	$owner_col = api_owner_column($class_name);
	$clean = array();
	foreach ($input as $key => $value) {
		if ($class_name::is_unwritable_field($key)) continue;
		if ($owner_col !== null && $key === $owner_col) continue;
		$clean[$key] = $value;
	}
	return $clean;
}

/**
 * Layer 2 row-scope gate. The per-record hooks are throw-to-deny; this also treats an
 * explicit `false` return as denial (§4.3 safety net) without tripping on the void/null
 * return of the normal hooks. $method is 'authenticate_read' or 'authenticate_write'.
 */
function api_authorize_row($object, $method, $auth_data) {
	if ($object->$method($auth_data) === false) {
		throw new SystemAuthenticationError('Authorization denied.');
	}
}

// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: no-referrer");

// CORS headers
$allowed_origins = $settings->get_setting('api_allowed_origins');
if ($allowed_origins && isset($_SERVER['HTTP_ORIGIN'])) {
	$origin = $_SERVER['HTTP_ORIGIN'];
	$allowed_list = array_map('trim', explode(',', $allowed_origins));
	if (in_array($origin, $allowed_list)) {
		header("Access-Control-Allow-Origin: " . $origin);
		header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
		header("Access-Control-Allow-Headers: public_key, secret_key, public-key, secret-key, client-app, client-version, Idempotency-Key, Content-Type");
		header("Access-Control-Max-Age: 86400");
	}
}

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
	http_response_code(204);
	exit;
}

// Enforce HTTPS (check both direct HTTPS and reverse proxy headers).
// Read as a yes/no setting, the way every other toggle is read — an unset
// value therefore still requires HTTPS.
if ((string)$settings->get_setting('api_require_https', false, true) !== '0') {
	$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
		|| (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
		|| (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
	if (!$is_https) {
		api_error('API requires HTTPS. Please use https:// instead of http://', 'SecurityError', 426);
	}
}

// Rate limiting: general API requests (configurable, default 1000/hour per IP)
$api_rate_limit = (int)($settings->get_setting('api_rate_limit_requests') ?: 1000);
$api_rate_window = (int)($settings->get_setting('api_rate_limit_window') ?: 3600);
if (!RequestLogger::check_rate_limit('api', $api_rate_limit, $api_rate_window)) {
	api_error('Rate limit exceeded. Please try again later.', 'RateLimitError', 429);
}

// Rate limiting: failed auth attempts (configurable, default 10 failures per 15 min per IP)
$api_auth_rate_limit = (int)($settings->get_setting('api_auth_rate_limit_requests') ?: 10);
$api_auth_rate_window = (int)($settings->get_setting('api_auth_rate_limit_window') ?: 900);
if (!RequestLogger::check_rate_limit('api_auth', $api_auth_rate_limit, $api_auth_rate_window, false)) {
	api_error('Too many failed authentication attempts. Please try again later.', 'RateLimitError', 429);
}

// HTTP header names are case-insensitive (RFC 7230). Clients such as Go's
// net/http canonicalize `public_key` → `Public_key` on HTTP/1.1, where case is
// preserved on the wire. Additionally, CGI/FastCGI transports collapse `-`
// and `_` into one namespace (HTTP_PUBLIC_KEY) — and Apache→FPM silently
// DROPS header names containing underscores, so clients behind such stacks
// must send `public-key`/`secret-key`. Normalize to lowercase with
// underscores so both spellings authenticate identically.
$headers = array();
foreach (getallheaders() as $header_name => $header_value) {
	$headers[str_replace('-', '_', strtolower($header_name))] = $header_value;
}

// Client version handshake: apps send client_app + client_version on every
// request. If the named app has a configured minimum and the request's version
// is below it (or missing), respond 426 — the client renders this as a
// blocking upgrade screen. Requests without client headers are unaffected.
$min_client_versions_json = $settings->get_setting('api_min_client_versions');
$client_app = isset($headers['client_app']) ? trim($headers['client_app']) : '';
if ($client_app !== '' && $min_client_versions_json) {
	$min_client_versions = json_decode($min_client_versions_json, true);
	if (is_array($min_client_versions) && isset($min_client_versions[$client_app])) {
		$client_version = isset($headers['client_version']) ? trim($headers['client_version']) : '';
		if ($client_version === ''
			|| version_compare($client_version, $min_client_versions[$client_app], '<')) {
			api_error('A newer version of this app is required. Please update it from the app store.',
				'UpgradeRequired', 426);
		}
	}
}

$request_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$url_segments = explode('/', trim($request_path, '/'));
// URL: /api/v1/{Entity}/{Id} → segments: ['api', 'v1', 'Entity', 'Id']

// Form definition endpoint: GET /api/v1/form/{action_name}
// Sessionless forms (requires_session => false) are served here, before the
// key-header requirement — a first-launch client has no credentials yet.
// Sessioned forms fall through to authentication and are dispatched again
// after $api_user resolves.
if (strtolower($url_segments[2] ?? '') === 'form') {
	require_once(PathHelper::getIncludePath('includes/ApiLogicEndpoint.php'));
	ApiLogicEndpoint::dispatchFormPreAuth($url_segments);
	// Returns only when the form requires a session.
}

// Auth endpoints: /api/v1/auth/*. login is the unauthenticated entry point and
// is handled (exits) here; session/logout fall through to key authentication.
if (strtolower($url_segments[2] ?? '') === 'auth') {
	require_once(PathHelper::getIncludePath('includes/ApiAuthEndpoint.php'));
	ApiAuthEndpoint::dispatchPreAuth($url_segments);
	// Returns only for endpoints that require key authentication.
}

// Action endpoint: POST /api/v1/action/{action_name}
// Sessionless actions (requires_session => false: register, password resets)
// execute here, before the key-header requirement. Sessioned actions fall
// through to authentication and are dispatched again after $api_user resolves.
require_once(PathHelper::getIncludePath('includes/ApiLogicEndpoint.php'));
if (strtolower($url_segments[2] ?? '') === 'action') {
	ApiLogicEndpoint::dispatchActionPreAuth($url_segments);
	// Returns only when the action requires a session.
}

// Discover all model classes, then apply the Layer 1 exposure opt-in. discover_model_classes()
// must keep returning ALL models (shared with schema/deletion subsystems); the CRUD surface is
// the filtered subset. An unexposed class is indistinguishable from a nonexistent one (404).
$classes = LibraryFunctions::discover_model_classes(['include_plugins' => true, 'plugin_status' => 'active']);
$readable_classes = array_values(array_filter($classes, fn($c) => api_flag($c, 'api_readable')));
$writable_classes = array_values(array_filter($classes, fn($c) => api_flag($c, 'api_writable')));

// Auth-grade client IP: behind Cloudflare the TCP peer is an edge address, so
// key IP restrictions must check the verified CF-Connecting-IP instead — but
// only when the peer really is a Cloudflare edge (a spoofed header from a
// direct-to-origin request must not defeat the restriction).
$source_ip = SessionControl::get_client_ip(true);
// Authentication: resolve + validate the credential and load its user, or exit
// 4xx. Key headers are the primary credential (full chain: key lookup,
// status/expiry/IP checks, secret verify, user load, failure logging that feeds
// the api_auth rate limiter, key-type stamping, usage tracking). Keyless
// requests may instead authenticate as a logged-in browser session (web session
// cookie + X-Joinery-Csrf header); those principals have api_entry === null.
// Both live in ApiAuth::authenticate().
$principal = ApiAuth::authenticate($headers, $source_ip);
$api_entry = $principal['api_entry'];
$api_user  = $principal['api_user'];
$auth_data = $principal['auth_data'];

// The anonymous browser-session principal (valid CSRF proof, no logged-in
// user — api_user === null) may only reach action dispatch, where
// ApiAuth::authorize() enforces the per-action allow_guest contract. Every
// other route family (CRUD verbs, forms, auth, app, management, backups)
// reads $api_user unconditionally and has no guest vocabulary.
if ($api_user === null && strtolower($url_segments[2] ?? '') !== 'action') {
	api_error('Authentication required', 'AuthenticationError', 401);
}

// URL segments were parsed above the pre-auth dispatches
$operation = isset($url_segments[2]) ? ucwords($url_segments[2]) : '';
$entity_id = isset($url_segments[3]) ? $url_segments[3] : null;
$request_method = strtolower($_SERVER['REQUEST_METHOD']);

$response = NULL;

// Management API — intercepted before class matching.
// Operation matches "management" (case-insensitive via ucwords above).
if (strtolower($url_segments[2] ?? '') === 'management') {
	require_once(PathHelper::getIncludePath('includes/ManagementApiRouter.php'));
	ManagementApiRouter::dispatch($url_segments, $auth_data, $request_method, $api_entry);
	// dispatch() always exits.
}

// Sessioned form definitions — sessionless ones were served pre-auth above.
if (strtolower($url_segments[2] ?? '') === 'form') {
	ApiLogicEndpoint::dispatchFormAuthenticated($url_segments, $api_entry, $api_user);
	// dispatchFormAuthenticated() always exits.
}

// Key-authenticated auth endpoints (session/logout/web_session) — login exited pre-auth.
if (strtolower($url_segments[2] ?? '') === 'auth') {
	ApiAuthEndpoint::dispatchAuthenticated($url_segments, $api_entry, $api_user);
	// dispatchAuthenticated() always exits.
}

// App platform endpoints (/api/v1/app/*): the navigation routing table native
// apps render as their tab bar and More list. Session-key clients only.
if (strtolower($url_segments[2] ?? '') === 'app') {
	require_once(PathHelper::getIncludePath('includes/ApiAppEndpoint.php'));
	ApiAppEndpoint::dispatchAuthenticated($url_segments, $api_entry, $api_user, $headers);
	// dispatchAuthenticated() always exits.
}

// Drive chunk transport (PUT/GET /api/v1/drive_upload/{token}) — raw-body upload,
// the inbound twin of management/backups/fetch. Its own rate-limit bucket keeps a
// multi-GB upload from exhausting the general 1000/hr API budget for other calls.
if (strtolower($url_segments[2] ?? '') === 'drive_upload') {
	$up_limit  = (int)($settings->get_setting('api_upload_rate_limit_requests') ?: 10000);
	$up_window = (int)($settings->get_setting('api_upload_rate_limit_window') ?: 3600);
	if (!RequestLogger::check_rate_limit('api_upload', $up_limit, $up_window)) {
		api_error('Upload rate limit exceeded. Please slow down.', 'RateLimitError', 429);
	}
	require_once(PathHelper::getIncludePath('includes/DriveUploadTransport.php'));
	DriveUploadTransport::dispatch($url_segments, $auth_data, $request_method, $api_entry);
	// dispatch() always exits, logging the api_upload outcome as it goes.
}

if (in_array($operation, $classes)) {
	$class_name = $operation;

	if ($request_method == 'get') {

		if (!in_array($class_name, $readable_classes)) {
			api_error('Not found', 'NotFound', 404);
		}

		ApiAuth::authorize(['capability' => ApiAuth::CAP_READ], $api_entry,
			$auth_data['current_user_permission'], 'Fetch object');

		// Single object GET
		try {
			$object = new $class_name($entity_id, TRUE);
			if (!$class_name::$api_public_read) {
				api_authorize_row($object, 'authenticate_read', $auth_data);
			}
			$response = array(
				'api_version' => '1.0',
				'success_message' => $class_name . ' found.',
				'data' => $object->export_for_api()
			);
		} catch (Exception $e) {
			RequestLogger::log('api', $request_method . ' ' . $operation, false, [
				'user_id' => $api_user->key,
				'status_code' => 400,
				'error_type' => 'TransactionError',
				'note' => $e->getMessage()
			]);
			api_error('Unable to fetch object (' . $e->getMessage() . ')', 'TransactionError', 400);
		}

	} else if ($request_method == 'put') {

		if (!in_array($class_name, $writable_classes)) {
			api_error('Not found', 'NotFound', 404);
		}

		ApiAuth::authorize(['capability' => ApiAuth::CAP_WRITE], $api_entry,
			$auth_data['current_user_permission'], 'Update object');

		parse_str($_SERVER['QUERY_STRING'], $url_parts);

		try {
			$object = new $class_name($entity_id, TRUE);
			// Authorize the row as it exists in the DB, BEFORE applying input — you may
			// only update a row you already own (the owner column is unwritable below, so
			// it cannot be flipped to forge ownership mid-update).
			api_authorize_row($object, 'authenticate_write', $auth_data);
			foreach (api_sanitize_write_input($url_parts, $class_name) as $key => $value) {
				$object->set($key, $value);
			}
			$object->prepare();
			$object->save();

			$response = array(
				'api_version' => '1.0',
				'success_message' => $class_name . ' update successful.',
				'data' => $object->export_for_api()
			);
		} catch (Exception $e) {
			RequestLogger::log('api', $request_method . ' ' . $operation, false, [
				'user_id' => $api_user->key,
				'status_code' => 400,
				'error_type' => 'TransactionError',
				'note' => $e->getMessage()
			]);
			api_error('Unable to update object (' . $e->getMessage() . ')', 'TransactionError', 400);
		}

	} else if ($request_method == 'post') {

		if (!in_array($class_name, $writable_classes)) {
			api_error('Not found', 'NotFound', 404);
		}

		ApiAuth::authorize(['capability' => ApiAuth::CAP_WRITE], $api_entry,
			$auth_data['current_user_permission'], 'Create object');

		try {
			// Boundary sanitize BEFORE dispatch so the floor wraps both CreateNew() and the
			// raw set-loop; then stamp the owner server-side so created rows belong to the
			// caller by construction (and a supplied owner cannot spoof).
			$write_input = api_sanitize_write_input($_POST, $class_name);
			$owner_col = api_owner_column($class_name);
			if ($owner_col !== null) {
				$write_input[$owner_col] = $auth_data['current_user_id'];
			}

			if (!$object = $class_name::CreateNew($write_input)) {
				$object = new $class_name(NULL);
				foreach ($write_input as $key => $value) {
					$object->set($key, $value);
				}
				$object->prepare();
				api_authorize_row($object, 'authenticate_write', $auth_data);
				$object->save();
			}

			$response = array(
				'api_version' => '1.0',
				'success_message' => 'New ' . $class_name . ' successful.',
				'data' => $object->export_for_api()
			);
		} catch (Exception $e) {
			RequestLogger::log('api', $request_method . ' ' . $operation, false, [
				'user_id' => $api_user->key,
				'status_code' => 400,
				'error_type' => 'TransactionError',
				'note' => $e->getMessage()
			]);
			api_error('Unable to create object (' . $e->getMessage() . ')', 'TransactionError', 400);
		}

	} else if ($request_method == 'delete') {

		if (!in_array($class_name, $writable_classes)) {
			api_error('Not found', 'NotFound', 404);
		}

		ApiAuth::authorize(['capability' => ApiAuth::CAP_DELETE], $api_entry,
			$auth_data['current_user_permission'], 'Delete object');

		try {
			$object = new $class_name($entity_id, TRUE);
			api_authorize_row($object, 'authenticate_write', $auth_data);
			$object->soft_delete();
			$object = new $class_name($entity_id, TRUE);

			$response = array(
				'api_version' => '1.0',
				'success_message' => 'Deletion successful.',
				'data' => $object->export_for_api()
			);
		} catch (Exception $e) {
			RequestLogger::log('api', $request_method . ' ' . $operation, false, [
				'user_id' => $api_user->key,
				'status_code' => 400,
				'error_type' => 'TransactionError',
				'note' => $e->getMessage()
			]);
			api_error('Unable to delete object (' . $e->getMessage() . ')', 'TransactionError', 400);
		}
	}

} else if (in_array(substr($operation, 0, -1), $classes)) {

	// Collection GET
	$class_name = substr($operation, 0, -1);

	if (!in_array($class_name, $readable_classes)) {
		api_error('Not found', 'NotFound', 404);
	}

	ApiAuth::authorize(['capability' => ApiAuth::CAP_READ], $api_entry,
		$auth_data['current_user_permission'], 'Fetch objects');

	$multiclassname = 'Multi' . $class_name;

	parse_str($_SERVER['QUERY_STRING'], $url_parts);

	// __route is routing metadata injected by the Apache rewrite; RouteHelper
	// strips it from the superglobals, but re-parsing the raw query string
	// resurrects it here. It is not a filter.
	unset($url_parts['__route']);

	$page = isset($url_parts['page']) ? $url_parts['page'] : 0;
	unset($url_parts['page']);

	$numperpage = isset($url_parts['numperpage']) ? $url_parts['numperpage'] : 3;
	unset($url_parts['numperpage']);

	$sort = isset($url_parts['sort']) ? $url_parts['sort'] : NULL;
	unset($url_parts['sort']);

	$sdirection = isset($url_parts['sdirection']) ? $url_parts['sdirection'] : 'ASC';
	unset($url_parts['sdirection']);

	$sortarray = ($sort && $sdirection) ? array($sort => $sdirection) : NULL;

	$offset = $numperpage * $page;

	$objects = new $multiclassname($url_parts, $sortarray, $numperpage, $offset);

	// §4.5 owner-scope: for a non-public owner-column model read by a non-staff caller, AND
	// the owner filter into the query itself, so count_all() is correct and there is no count
	// disclosure. Public-read models (catalog content) and staff get the unfiltered query.
	$is_public_read = $class_name::$api_public_read;
	$owner_col = api_owner_column($class_name);
	if (!$is_public_read && $owner_col !== null && (int)$auth_data['current_user_permission'] < 5) {
		$objects->api_owner_scope = [$owner_col, $auth_data['current_user_id']];
	}

	// An unknown filter key is a caller error, not a server fault: without the
	// refusal the key would be silently dropped and the collection would
	// return every row, presented as a filtered result.
	try {
		$numobjects = $objects->count_all();
		$objects->load();
	} catch (UnknownMultiOptionException $e) {
		api_error('Unknown filter parameter (' . $e->getMessage() . ')', 'TransactionError', 400);
	}

	$response_array = array();
	foreach ($objects as $object) {
		try {
			// Public-read models skip the per-record scope; owned models enforce it (backstop).
			if (!$is_public_read) {
				api_authorize_row($object, 'authenticate_read', $auth_data);
			}
			$response_array[] = $object->export_for_api();
		} catch (Exception $e) {
			// Skip unauthorized objects
			continue;
		}
	}

	$response = array(
		'api_version' => '1.0',
		'success_message' => '',
		'num_results' => (int) $numobjects,
		'page' => (int) $page,
		'numperpage' => (int) $numperpage,
		'data' => $response_array
	);

} else if (strtolower($url_segments[2] ?? '') === 'actions' && $request_method === 'get') {
	// Action discovery endpoint: GET /api/v1/actions
	$discover_actions = function ($logic_dir, $name_prefix) {
		$found = [];
		foreach (glob($logic_dir . '/*_logic.php') as $file) {
			$basename = basename($file, '.php');           // e.g., "register_logic"
			$action_name = substr($basename, 0, -6);       // e.g., "register" (strip "_logic")
			$descriptor_function = $basename . '_descriptor'; // canonical metadata
			$api_meta_function = $basename . '_api';        // legacy fallback

			// Check file contents for a metadata companion without including
			// (some legacy files have top-level code that would execute on include)
			$contents = file_get_contents($file);
			$declares = function ($fn) use ($contents) {
				return (bool) preg_match('/function\s+' . preg_quote($fn, '/') . '\s*\(/', $contents);
			};
			if ($declares($descriptor_function) || $declares($api_meta_function)) {
				require_once($file);
				$meta_function = function_exists($descriptor_function)
					? $descriptor_function
					: (function_exists($api_meta_function) ? $api_meta_function : null);
				if ($meta_function) {
					$meta = call_user_func($meta_function);
					$found[$name_prefix . $action_name] = [
						'description' => $meta['description'] ?? '',
						'requires_session' => $meta['auth']['requires_session'] ?? $meta['requires_session'] ?? true,
						// Typed input schema from the descriptor; null when the
						// action only has the legacy _logic_api() companion
						'input' => (isset($meta['input']) && is_array($meta['input'])) ? $meta['input'] : null,
						// Form builder companion → GET /api/v1/form/{action} works
						'has_form' => function_exists($basename . '_form'),
					];
				}
			}
		}
		return $found;
	};

	$actions = $discover_actions(PathHelper::getIncludePath('logic'), '');

	// Plugin actions are listed under their namespaced name
	// ({plugin}/{action}); only active plugins are discoverable.
	foreach (array_keys(PluginHelper::getActivePlugins()) as $plugin_name) {
		$actions += $discover_actions(
			PathHelper::getIncludePath('plugins/' . $plugin_name . '/logic'),
			$plugin_name . '/'
		);
	}

	ksort($actions);
	$response = array(
		'api_version' => '1.0',
		'success_message' => 'Available actions',
		'data' => $actions
	);

} else if (strtolower($url_segments[2] ?? '') === 'action' && isset($url_segments[3])) {
	// Sessioned action endpoint — sessionless actions executed pre-auth above.
	ApiLogicEndpoint::dispatchActionAuthenticated($url_segments, $api_entry, $api_user);
	// dispatchActionAuthenticated() always exits.
}

if ($response !== NULL) {
	// Log successful request
	$response_ms = round((microtime(true) - $api_start_time) * 1000);
	RequestLogger::log('api', $request_method . ' ' . $operation, true, [
		'user_id' => $api_user->key,
		'status_code' => 200,
		'response_ms' => $response_ms
	]);
	api_success($response['data'], $response['success_message'], 200,
		array_diff_key($response, array_flip(['api_version', 'success_message', 'data']))
	);
} else {
	RequestLogger::log('api', $operation, false, [
		'user_id' => $api_user->key,
		'status_code' => 400,
		'error_type' => 'TransactionError',
		'note' => 'Invalid object or list: ' . $operation
	]);
	api_error('Invalid object or list (' . $operation . ')', 'TransactionError', 400);
}

?>
