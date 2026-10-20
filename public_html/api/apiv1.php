<?php
/**
 * API v1 Endpoint
 *
 * @version 2.29
 * @changelog 2.29 - A keyed action request is metered after authentication: a session key
 *   (a person's own device: a linked desktop, a phone app) on that person's own budget,
 *   like their browser, any other key on the address as before. Several people's
 *   devices behind one address are several callers. A session key is metered per person on every
 *   route, not only on actions. A key request is a public AND a secret key
 *   header, as ApiAuth reads it; a request with no user (the anonymous principal) is metered by
 *   address whatever its headers. The address count leaves out per-person traffic
 *   (RequestLogger::address_rate_limit_state), so a person's devices never fill it.
 * @changelog 2.28 - A collection read answers with at most API_MAX_PAGE (200) rows; numperpage above it
 *   reads as 200.
 * @changelog 2.27 - A record create or update takes its fields from a JSON object body (Content-Type
 *   application/json) as the actions do; form fields and the query string still work.
 * @changelog 2.26 - A member's list request on a model only staff can read (not public, no owner
 *   column, SystemBase's own read rule) is refused before it is counted: num_results answered for
 *   rows the member could not see.
 * @changelog 2.25 - A scoped key may name models ('Event:read'): the record endpoints pass their
 *   model and verb to ApiAuth::authorize(), and ApiAuth::refuseScopedKeyOutsideItsScope() lets
 *   such a key reach the models it names. The GET actions listing reads
 *   ApiLogicEndpoint::actionCatalog().
 * @changelog 2.24 - An app-shaped request (the app-access headers on an action) is metered per
 *   person after authentication and its failed sign-ins counted in app_access_auth; the
 *   headers switch no limiter off on any other route family. An app credential (one purpose, AppAccess) is refused on every route
 *   family but action dispatch (ApiAuth::refuseAppCredentialOutsideActions()).
 * @changelog 2.23 - Backup broker (/api/v1/broker/*): a Managed node's backup run asks for signed links with its
 *   run token, dispatched before key authentication (BrokerEndpoint).
 * @changelog 2.22 - The agent channel's limit is checked by the endpoint, per node for what a
 *   node's signature proves and per address for the rest, not here per address for everything.
 * @changelog 2.21 - api_error() and a logic action's error envelope carry
 *   `error_ref` ({id, hash, report_url}) when an error was recorded on this
 *   request (ErrorReference).
 * @changelog 2.20 - A scoped machine key is refused on every route family but
 *   action dispatch (ApiAuth::refuseScopedKeyOutsideActions()).
 * @changelog 2.19 - Sessioned actions dispatch before the CRUD model list is
 *   assembled. Assembling it loads every model class on the platform, ~180 ms
 *   a call that no action ever used; a page mounting with five calls in
 *   flight paid it five times over on one CPU.
 * @changelog 2.18 - Rate limits: a signed-in browser session is metered per USER
 *   (api_session_rate_limit_*), after authentication, instead of sharing the
 *   per-address bucket with keyless and key traffic — a person's own reader,
 *   the vault beacon and its background drains no longer spend the budget that
 *   guards against strangers, and several people behind one address are not
 *   one caller. Every 429 now says what was counted, the limit, and how long
 *   until the next request is accepted (Retry-After + data.retry_after_seconds).
 * @changelog 2.17 - Agent channel (/api/v1/agent/*): the server manager's node
 *   agent claims primitive jobs and posts results over an outbound HTTPS poll,
 *   authenticated by an Ed25519 keypair the node generated and kept. Its own
 *   rate-limit bucket; dispatched before key authentication because its
 *   credential is not an API key. See AgentChannelEndpoint.
 * @version 2.16
 * @changelog 2.16 - a collection GET includes a locked sealed row with its
 *   sealed fields masked and content_locked set (export_for_api_locked),
 *   instead of silently omitting it from a list presented as complete.
 * @changelog 2.15 - a sealed record nobody present can open answers 423
 *   VaultLocked on the single-object CRUD read, not a generic 400: the record
 *   exists and the caller may read it, so "unlock and retry" is actionable
 *   where "something went wrong" is not.
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
/** The most rows one collection read answers with. */
const API_MAX_PAGE = 200;

function api_error($message, $error_type = 'TransactionError', $status_code = 400, $data = array()) {
	header("Content-Type: application/json");
	http_response_code($status_code);
	$envelope = array(
		'api_version' => '1.0',
		'errortype' => $error_type,
		'error' => $message,
		'data' => $data ? $data : new stdClass()
	);
	// An error recorded on this request is what the caller can report.
	$ref = ErrorReference::forEnvelope();
	if ($ref !== null) {
		$envelope['error_ref'] = $ref;
	}
	echo json_encode($envelope) . PHP_EOL;
	exit;
}

/**
 * Refuse a request that is over a rate limit, and say so usefully: who was
 * counted, how many in what window, the limit, and when the next request will
 * be accepted. Sets Retry-After and carries the same numbers in data so a
 * client can wait the right amount instead of guessing.
 *
 * @param array  $state  RequestLogger::rate_limit_state() result
 * @param string $who    'This address', 'Your account', 'This agent', ...
 * @param int    $limit  requests allowed per window
 * @param int    $window window length in seconds
 * @param string $what   what was counted, e.g. 'API requests', 'failed sign-in attempts'
 */
function api_rate_limited(array $state, $who, $limit, $window, $what = 'API requests') {
	$span = function ($seconds) {
		$seconds = max(1, intval($seconds));
		if ($seconds < 90) { return $seconds . ' second' . ($seconds === 1 ? '' : 's'); }
		if ($seconds < 5400) { $m = (int)ceil($seconds / 60); return $m . ' minute' . ($m === 1 ? '' : 's'); }
		$h = round($seconds / 3600, 1); return ($h == (int)$h ? (int)$h : $h) . ' hour' . ($h == 1 ? '' : 's');
	};
	$retry = intval($state['retry_after'] ?? 60);
	header('Retry-After: ' . $retry);
	api_error($who . ' has made ' . number_format(intval($state['count'] ?? $limit)) . ' ' . $what
		. ' in the last ' . $span($window) . '; the limit is ' . number_format($limit)
		. '. Nothing is wrong — try again in ' . $span($retry) . '.',
		'RateLimitError', 429, array(
			'limit' => intval($limit),
			'window_seconds' => intval($window),
			'count' => intval($state['count'] ?? 0),
			'retry_after_seconds' => $retry,
		));
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

		// An error recorded on this request (an exception the action threw)
		// is what the caller can report.
		$ref = ErrorReference::forEnvelope();
		if ($ref !== null) {
			$response['error_ref'] = $ref;
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
 * A record write's fields: a JSON object body when the request sends one
 * (Content-Type application/json, as the actions take theirs and as a
 * sandboxed plugin's API door sends), otherwise $fallback: the form fields of
 * a create, the query string of an update.
 */
function api_record_write_fields(array $fallback) {
	$type = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
	if (strpos($type, 'application/json') === 0) {
		$body = json_decode((string)file_get_contents('php://input'), true);
		if (is_array($body) && ($body === array() || array_keys($body) !== range(0, count($body) - 1))) {
			return $body;
		}
	}
	return $fallback;
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

// Agent channel (/api/v1/agent/*): the node-posture job source of the server
// manager's agent (specs/agent_on_node_architecture.md §3.1). Dispatched here —
// before the general API rate limiter — because it is machine traffic with its
// own profile: a fleet of nodes polling from one NAT'd address, or a
// multi-tenant host's sites on one box, would otherwise exhaust a per-IP
// budget sized for human clients and go silent, which is exactly the failure
// the channel exists to make visible. The endpoint meters itself instead, per
// node for what a node's signature proves and per address for the rest
// (AgentChannelEndpoint::dispatchPreAuth), so abnormal agent volume is still
// bounded and still legible (§3.5.6, reads are loud).
//
// Its credential is not an API key: the node signs with a keypair it generated
// and kept, and this plane holds only the public half. So it authenticates
// itself, before the key-header requirement below.
if (strtolower(explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'))[2] ?? '') === 'agent') {
	if (!class_exists('AgentChannelEndpoint')) {
		// The server_manager plugin is absent or inactive, so this route does
		// not exist here. Indistinguishable from any other unknown path.
		api_error('Not found', 'ActionError', 404);
	}
	AgentChannelEndpoint::dispatchPreAuth(explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/')));
	// dispatchPreAuth() always exits.
}

// Relay birth channel (/api/v1/relay/*): a relay born from first-boot user-data
// fetches its bundle and posts its birth report here
// (specs/relay_without_a_shell.md). Its credential is a one-time run token plus
// a signature by the relay's own identity key, never an API key, so like the
// agent channel it authenticates itself before the key-header requirement
// below. Present only where the mailbox plugin is active; metered in its own
// bucket by the endpoint.
if (strtolower(explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'))[2] ?? '') === 'relay') {
	if (!class_exists('RelayBirthEndpoint')) {
		api_error('Not found', 'ActionError', 404);
	}
	RelayBirthEndpoint::dispatchPreAuth(explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/')));
	// dispatchPreAuth() always exits.
}

// Backup broker (/api/v1/broker/*): a Managed node's backup run asks the
// management node for signed links (specs/storage_targets.md WP5). Its
// credential is the run's own token, minted when the node's agent claimed the
// job, never an API key, so like the relay channel it authenticates itself
// before the key-header requirement below. Present only where the
// server_manager plugin is active; metered in its own bucket by the endpoint.
if (strtolower(explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'))[2] ?? '') === 'broker') {
	if (!class_exists('BrokerEndpoint')) {
		api_error('Not found', 'ActionError', 404);
	}
	BrokerEndpoint::dispatchPreAuth(explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/')));
	// dispatchPreAuth() always exits.
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

// Rate limiting: general API requests (configurable, default 1000/hour per IP).
//
// A request shaped like the browser-session credential — no key headers, a
// session cookie, an X-Joinery-Csrf header — is NOT metered here. It is
// metered per user once it has authenticated (below), because the address
// bucket exists to bound strangers and machines, and a person reading their
// own mail is neither: the reader, the vault beacon and its background drains
// all come from one browser, and several people behind one address are
// several callers. A browser-shaped request that fails to authenticate is
// bounded by the failed-auth limiter like any other bad credential.
$api_rate_limit = (int)($settings->get_setting('api_rate_limit_requests') ?: 1000);
$api_rate_window = (int)($settings->get_setting('api_rate_limit_window') ?: 3600);
$browser_shaped = empty($headers['public_key']) && empty($headers['secret_key'])
	&& !empty($_COOKIE[session_name()]) && isset($headers['x_joinery_csrf']);
// An app credential (a mail app through the mail gateway) is metered per
// person after authentication, like a browser session: one person's mail
// program syncing a large mailbox must not spend the budget that guards the
// address against strangers, and is not a stranger's flood. Its failed
// sign-ins have their own bucket (ApiAuth, app_access_auth).
// Only an ACTION request can be app-shaped: every other route family is
// dispatched before authentication (auth/login, forms) or refused to an app
// credential after it, so the header must never switch a limiter off there.
// A sessionless action is refused an app credential before it runs
// (ApiLogicEndpoint::dispatchActionPreAuth()), so every request exempted here
// reaches ApiAuth::authenticate() and its own app_access_auth bucket. (A
// request with the header for an action that does not exist, or one switched
// off, is answered before that, unmetered: a stateless refusal, cheap to give.)
$action_route = strtolower(explode('/', trim((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'))[2] ?? '') === 'action';
$app_shaped = isset($headers['x_joinery_app_secret']) && $action_route;
// A keyed ACTION request is metered once its key has authenticated (below):
// a person's own device -- a linked desktop, a phone app -- signs in with a
// session key and is metered per person, like their browser, so several
// people's devices behind one address are several callers; any other key
// stays on the address. Action only, for the reason above. A bad key never
// gets that far: it fails authentication, which the failed-auth limiter
// counts by address. A key request is both key headers, the test ApiAuth
// itself makes: a public key alone authenticates as a browser or a guest,
// which the branch below meters by address. A keyed request refused before
// authentication (an unknown action, a wrong method, an action switched off)
// is answered unmetered, like an app-shaped one: a stateless refusal, cheap
// to give.
$key_shaped = $action_route && !empty($headers['public_key']) && !empty($headers['secret_key']);
if (!$browser_shaped && !$app_shaped && !$key_shaped) {
	$api_state = RequestLogger::address_rate_limit_state('api', $api_rate_limit, $api_rate_window);
	if (!$api_state['allowed']) {
		api_rate_limited($api_state, 'This address', $api_rate_limit, $api_rate_window);
	}
}

// Rate limiting: failed auth attempts (configurable, default 10 failures per 15 min per IP)
$api_auth_rate_limit = (int)($settings->get_setting('api_auth_rate_limit_requests') ?: 10);
$api_auth_rate_window = (int)($settings->get_setting('api_auth_rate_limit_window') ?: 900);
// An app credential's failed sign-ins have their own bucket (ApiAuth,
// app_access_auth), counted per the program's network and per username; its
// requests all arrive from the gateway's loopback address, which this
// per-address bucket would share with every other loopback caller.
$api_auth_state = $app_shaped ? array('allowed' => true)
	: RequestLogger::rate_limit_state('api_auth', $api_auth_rate_limit, $api_auth_rate_window, false);
if (!$api_auth_state['allowed']) {
	api_rate_limited($api_auth_state, 'This address', $api_auth_rate_limit, $api_auth_rate_window,
		'failed sign-in attempts');
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

// Rate limiting for the browser-session credential, now that it has a name:
// a signed-in user is metered on their own requests (api_session_rate_limit_*,
// default 5000/hour), whoever else shares their address; a principal with no
// user (the anonymous browser principal, a guest) is metered by address like
// any other keyless caller, whatever headers it came with. A session key is
// metered here per person on every route; any other key on an action request
// is metered here by address; every other key request was metered by address
// before authentication.
if ($api_entry === null || $api_entry->is_app_credential()) {
	if ($api_user !== null) {
		$session_limit  = (int)($settings->get_setting('api_session_rate_limit_requests') ?: 5000);
		// A mail program's first sync of a large mailbox downloads it whole,
		// a few dozen messages a request: give it room.
		if ($api_entry !== null) {
			$session_limit = max($session_limit, ApiAuth::APP_REQUEST_LIMIT);
		}
		$session_window = (int)($settings->get_setting('api_session_rate_limit_window') ?: 3600);
		$session_state = RequestLogger::rate_limit_state('api', $session_limit, $session_window, null, intval($api_user->key));
		if (!$session_state['allowed']) {
			api_rate_limited($session_state, 'Your account', $session_limit, $session_window);
		}
	} else {
		$api_state = RequestLogger::address_rate_limit_state('api', $api_rate_limit, $api_rate_window);
		if (!$api_state['allowed']) {
			api_rate_limited($api_state, 'This address', $api_rate_limit, $api_rate_window);
		}
	}
} elseif ($api_entry->is_session()) {
	// A session key is a person's own device: their budget, shared with their
	// browser, on every route. Its rows are left out of the address count, so
	// this is the limit that bounds it (a keyed action request skipped the
	// address check above; any other route also passed it).
	$session_limit  = (int)($settings->get_setting('api_session_rate_limit_requests') ?: 5000);
	$session_window = (int)($settings->get_setting('api_session_rate_limit_window') ?: 3600);
	$session_state = RequestLogger::rate_limit_state('api', $session_limit, $session_window, null, intval($api_user->key));
	if (!$session_state['allowed']) {
		api_rate_limited($session_state, 'Your account', $session_limit, $session_window);
	}
} elseif ($key_shaped) {
	// Any other key on an action request skipped the address check above.
	$api_state = RequestLogger::address_rate_limit_state('api', $api_rate_limit, $api_rate_window);
	if (!$api_state['allowed']) {
		api_rate_limited($api_state, 'This address', $api_rate_limit, $api_rate_window);
	}
}

// The anonymous browser-session principal (valid CSRF proof, no logged-in
// user — api_user === null) may only reach action dispatch, where
// ApiAuth::authorize() enforces the per-action allow_guest contract. Every
// other route family (CRUD verbs, forms, auth, app, management, backups)
// reads $api_user unconditionally and has no guest vocabulary.
if ($api_user === null && strtolower($url_segments[2] ?? '') !== 'action') {
	api_error('Authentication required', 'AuthenticationError', 401);
}

// A scoped machine key reaches only what its scope names. Action dispatch
// and the record endpoints check the name in ApiAuth::authorize(); every
// other route family refuses the key here, including the ones that never
// call authorize().
if (strtolower($url_segments[2] ?? '') !== 'action') {
	ApiAuth::refuseScopedKeyOutsideItsScope($api_entry, $url_segments[2] ?? '');
	ApiAuth::refuseAppCredentialOutsideActions($api_entry, strtolower($url_segments[2] ?? ''));
}

// Sessioned action endpoint — sessionless actions executed pre-auth above.
// Dispatched before the CRUD surface is assembled: an action never touches
// it, and assembling it means loading every model class on the platform
// (~180 ms per call, paid by each of the several calls a page fires as it
// mounts).
if (strtolower($url_segments[2] ?? '') === 'action' && isset($url_segments[3])) {
	ApiLogicEndpoint::dispatchActionAuthenticated($url_segments, $api_entry, $api_user);
	// dispatchActionAuthenticated() always exits.
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

// Drive chunk transport (PUT/GET /api/v1/drive_upload/{token}) — raw-body upload
// that reads its own body and writes its own response. Its own rate-limit bucket keeps a
// multi-GB upload from exhausting the general 1000/hr API budget for other calls.
if (strtolower($url_segments[2] ?? '') === 'drive_upload') {
	$up_limit  = (int)($settings->get_setting('api_upload_rate_limit_requests') ?: 10000);
	$up_window = (int)($settings->get_setting('api_upload_rate_limit_window') ?: 3600);
	$up_state = RequestLogger::rate_limit_state('api_upload', $up_limit, $up_window);
	if (!$up_state['allowed']) {
		api_rate_limited($up_state, 'This address', $up_limit, $up_window, 'upload requests');
	}
	require_once(PathHelper::getIncludePath('includes/DriveUploadTransport.php'));
	DriveUploadTransport::dispatch($url_segments, $auth_data, $request_method, $api_entry);
	// dispatch() always exits, logging the api_upload outcome as it goes.
}

// Discover all model classes, then apply the Layer 1 exposure opt-in. discover_model_classes()
// must keep returning ALL models (shared with schema/deletion subsystems); the CRUD surface is
// the filtered subset. An unexposed class is indistinguishable from a nonexistent one (404).
$classes = LibraryFunctions::discover_model_classes(['include_plugins' => true, 'plugin_status' => 'active']);
$readable_classes = array_values(array_filter($classes, fn($c) => api_flag($c, 'api_readable')));
$writable_classes = array_values(array_filter($classes, fn($c) => api_flag($c, 'api_writable')));

if (in_array($operation, $classes)) {
	$class_name = $operation;

	if ($request_method == 'get') {

		if (!in_array($class_name, $readable_classes)) {
			api_error('Not found', 'NotFound', 404);
		}

		ApiAuth::authorize(['capability' => ApiAuth::CAP_READ], $api_entry,
			$auth_data['current_user_permission'], 'Fetch object',
			null, ['model' => $class_name, 'verb' => 'read']);

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
		} catch (VaultLockedException $e) {
			// The record exists and the caller may read it — its content is
			// sealed and nobody who can open it is present. That is a different
			// answer from "broken", and a client can act on it: prompt to
			// unlock, then retry. Never the ciphertext, and never a 200.
			RequestLogger::log('api', $request_method . ' ' . $operation, false, [
				'user_id' => $api_user->key,
				'status_code' => 423,
				'error_type' => 'VaultLocked',
			]);
			api_error('That content is sealed. Unlock your vault to read it.', 'VaultLocked', 423);
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
			$auth_data['current_user_permission'], 'Update object',
			null, ['model' => $class_name, 'verb' => 'update']);

		parse_str($_SERVER['QUERY_STRING'], $url_parts);

		try {
			$object = new $class_name($entity_id, TRUE);
			// Authorize the row as it exists in the DB, BEFORE applying input — you may
			// only update a row you already own (the owner column is unwritable below, so
			// it cannot be flipped to forge ownership mid-update).
			api_authorize_row($object, 'authenticate_write', $auth_data);
			foreach (api_sanitize_write_input(api_record_write_fields($url_parts), $class_name) as $key => $value) {
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
			$auth_data['current_user_permission'], 'Create object',
			null, ['model' => $class_name, 'verb' => 'create']);

		try {
			// Boundary sanitize BEFORE dispatch so the floor wraps both CreateNew() and the
			// raw set-loop; then stamp the owner server-side so created rows belong to the
			// caller by construction (and a supplied owner cannot spoof).
			$write_input = api_sanitize_write_input(api_record_write_fields($_POST), $class_name);
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
			$auth_data['current_user_permission'], 'Delete object',
			null, ['model' => $class_name, 'verb' => 'delete']);

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
		$auth_data['current_user_permission'], 'Fetch objects',
		null, ['model' => $class_name, 'verb' => 'read']);

	// A model with no owner column, not public, keeping SystemBase's own read
	// rule is staff only: a member is never shown a row. The list would still
	// answer num_results for whatever the member filtered on, a yes-or-no on
	// rows they may not see, so the list is refused before it is counted.
	if (!$class_name::$api_public_read && api_owner_column($class_name) === null
			&& (int)$auth_data['current_user_permission'] < 5
			&& (new ReflectionMethod($class_name, 'authenticate_read'))->getDeclaringClass()->getName() === 'SystemBase') {
		api_error('Fetch objects: ' . $class_name . ' can be read by staff only', 'AuthenticationError', 403);
	}

	$multiclassname = 'Multi' . $class_name;

	parse_str($_SERVER['QUERY_STRING'], $url_parts);

	// __route is routing metadata injected by the Apache rewrite; RouteHelper
	// strips it from the superglobals, but re-parsing the raw query string
	// resurrects it here. It is not a filter.
	unset($url_parts['__route']);

	$page = isset($url_parts['page']) ? $url_parts['page'] : 0;
	unset($url_parts['page']);

	// At most API_MAX_PAGE rows in one answer: a whole large model is read
	// page by page, never in one response.
	$numperpage = isset($url_parts['numperpage']) ? max(1, min((int)$url_parts['numperpage'], API_MAX_PAGE)) : 3;
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
	} catch (UnsortableColumnException $e) {
		// A bad sort is a caller error too. The model layer refuses it — a sort
		// column is interpolated, not bound, so it is validated there rather
		// than trusted from here.
		api_error($e->getMessage(), 'TransactionError', 400);
	}

	$response_array = array();
	foreach ($objects as $object) {
		try {
			// Public-read models skip the per-record scope; owned models enforce it (backstop).
			if (!$is_public_read) {
				api_authorize_row($object, 'authenticate_read', $auth_data);
			}
			$response_array[] = $object->export_for_api();
		} catch (VaultLockedException $e) {
			// Authorized, but the row's sealed content is not openable right
			// now. The single-object GET answers 423; a collection includes the
			// row with sealed fields masked and content_locked set — dropping it
			// would present the list as complete while quietly omitting rows.
			$response_array[] = $object->export_for_api_locked();
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
	$actions = array();
	foreach (ApiLogicEndpoint::actionCatalog() as $name => $entry) {
		$meta = $entry['meta'];
		$actions[$name] = [
			'description' => $meta['description'] ?? '',
			'requires_session' => $meta['requires_session'] ?? true,
			// Typed input schema, when the descriptor declares one
			'input' => (isset($meta['input']) && is_array($meta['input'])) ? $meta['input'] : null,
			// Form builder companion → GET /api/v1/form/{action} works
			'has_form' => $entry['has_form'],
		];
	}

	$response = array(
		'api_version' => '1.0',
		'success_message' => 'Available actions',
		'data' => $actions
	);

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
