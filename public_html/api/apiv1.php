<?php
/**
 * API v1 Endpoint
 *
 * @version 2.2
 */
require_once(__DIR__ . '/../includes/PathHelper.php');

$settings = Globalvars::get_instance();
require_once(PathHelper::getIncludePath('data/api_keys_class.php'));
require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));

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
		'error' => 'Error: ' . $message,
		'data' => ''
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
		header("Access-Control-Allow-Headers: public_key, secret_key, Content-Type");
		header("Access-Control-Max-Age: 86400");
	}
}

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
	http_response_code(204);
	exit;
}

// Enforce HTTPS (check both direct HTTPS and reverse proxy headers)
if ($settings->get_setting('api_require_https') !== 'false') {
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

// Discover all model classes available for API
$classes = LibraryFunctions::discover_model_classes();

$source_ip = $_SERVER['REMOTE_ADDR'];
// HTTP header names are case-insensitive (RFC 7230). Clients such as Go's
// net/http canonicalize `public_key` → `Public_key` on HTTP/1.1, where case is
// preserved on the wire. Normalize to lowercase for lookup.
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$public_key = isset($headers['public_key']) ? $headers['public_key'] : null;
$secret_key = isset($headers['secret_key']) ? $headers['secret_key'] : null;

if (!$public_key || !$secret_key) {
	RequestLogger::log('api_auth', 'auth_failure', false, [
		'status_code' => 400,
		'error_type' => 'AuthenticationError',
		'note' => 'Missing public/secret key headers'
	]);
	api_error('Public/secret keys not present', 'AuthenticationError', 400);
}

try {
	$api_entry = ApiKey::GetByColumn('apk_public_key', $public_key);
} catch (Exception $e) {
	RequestLogger::log('api_auth', 'auth_failure', false, [
		'status_code' => 400,
		'error_type' => 'AuthenticationError',
		'note' => 'Unable to find the api key'
	]);
	api_error('Unable to find the api key', 'AuthenticationError', 400);
}

if (!$api_entry->key) {
	RequestLogger::log('api_auth', 'auth_failure', false, [
		'status_code' => 400,
		'error_type' => 'AuthenticationError',
		'note' => 'Unable to find the api key'
	]);
	api_error('Unable to find the api key', 'AuthenticationError', 400);
}

// Validate API key status
if (!$api_entry->get('apk_is_active')) {
	RequestLogger::log('api_auth', 'auth_failure', false, [
		'status_code' => 401,
		'error_type' => 'AuthenticationError',
		'note' => 'API key is not active'
	]);
	api_error('API key is not active', 'AuthenticationError', 401);
}

if ($api_entry->get('apk_start_time')) {
	$now_utc = gmdate('Y-m-d H:i:s');
	if ($now_utc < $api_entry->get('apk_start_time')) {
		RequestLogger::log('api_auth', 'auth_failure', false, [
			'status_code' => 401,
			'error_type' => 'AuthenticationError',
			'note' => 'API key is not yet active'
		]);
		api_error('API key is not yet active', 'AuthenticationError', 401);
	}
}

if ($api_entry->get('apk_expires_time')) {
	$now_utc = gmdate('Y-m-d H:i:s');
	if ($now_utc > $api_entry->get('apk_expires_time')) {
		RequestLogger::log('api_auth', 'auth_failure', false, [
			'status_code' => 401,
			'error_type' => 'AuthenticationError',
			'note' => 'API key has expired'
		]);
		api_error('API key has expired', 'AuthenticationError', 401);
	}
}

try {
	$api_user = new User($api_entry->get('apk_usr_user_id'), TRUE);
} catch (Exception $e) {
	RequestLogger::log('api_auth', 'auth_failure', false, [
		'status_code' => 400,
		'error_type' => 'AuthenticationError',
		'note' => 'Unable to find the api user'
	]);
	api_error('Unable to find the api user', 'AuthenticationError', 400);
}

if (!$api_user->key) {
	RequestLogger::log('api_auth', 'auth_failure', false, [
		'status_code' => 400,
		'error_type' => 'AuthenticationError',
		'note' => 'Unable to find the api user'
	]);
	api_error('Unable to find the api user', 'AuthenticationError', 400);
}

if ($api_user->get('usr_delete_time')) {
	RequestLogger::log('api_auth', 'auth_failure', false, [
		'status_code' => 400,
		'error_type' => 'AuthenticationError',
		'note' => 'API user has been deleted'
	]);
	api_error('API user has been deleted', 'AuthenticationError', 400);
}

if ($authorized_ips = $api_entry->get('apk_ip_restriction')) {
	$ip_list = str_getcsv($authorized_ips);
	$ip_list = array_map('trim', $ip_list);
	if (count($ip_list)) {
		if (!in_array($_SERVER['REMOTE_ADDR'], $ip_list)) {
			RequestLogger::log('api_auth', 'auth_failure', false, [
				'status_code' => 401,
				'error_type' => 'AuthenticationError',
				'note' => 'Unauthorized IP: ' . $_SERVER['REMOTE_ADDR']
			]);
			api_error('Unauthorized IP', 'AuthenticationError', 401);
		}
	}
}

if (!$api_entry->check_secret_key($secret_key)) {
	RequestLogger::log('api_auth', 'auth_failure', false, [
		'status_code' => 401,
		'error_type' => 'AuthenticationError',
		'note' => 'Incorrect secret key'
	]);
	api_error('Incorrect secret key', 'AuthenticationError', 401);
}

// Authentication passed — parse URL segments from the request path
$request_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$url_segments = explode('/', trim($request_path, '/'));
// URL: /api/v1/{Entity}/{Id} → segments: ['api', 'v1', 'Entity', 'Id']
$operation = isset($url_segments[2]) ? ucwords($url_segments[2]) : '';
$entity_id = isset($url_segments[3]) ? $url_segments[3] : null;
$request_method = strtolower($_SERVER['REQUEST_METHOD']);
$auth_data = array('current_user_id' => $api_user->key, 'current_user_permission' => $api_user->get('usr_permission'));

$response = NULL;

// Management API — intercepted before class matching.
// Operation matches "management" (case-insensitive via ucwords above).
if (strtolower($url_segments[2] ?? '') === 'management') {
	require_once(PathHelper::getIncludePath('includes/ManagementApiRouter.php'));
	ManagementApiRouter::dispatch($url_segments, $auth_data, $request_method);
	// dispatch() always exits.
}

if (in_array($operation, $classes)) {
	$class_name = $operation;

	if ($request_method == 'get') {

		if ($api_entry->get('apk_permission') == 2) {
			api_error('Unable to fetch object, insufficient api permission', 'AuthenticationError', 403);
		}

		// Single object GET
		try {
			$object = new $class_name($entity_id, TRUE);
			$object->authenticate_read($auth_data);
			$response = array(
				'api_version' => '1.0',
				'success_message' => $class_name . ' found.',
				'data' => $object->export_as_array()
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

		if ($api_entry->get('apk_permission') < 2) {
			api_error('Unable to update object, insufficient api permission', 'AuthenticationError', 403);
		}

		parse_str($_SERVER['QUERY_STRING'], $url_parts);

		try {
			$object = new $class_name($entity_id, TRUE);
			foreach ($url_parts as $key => $value) {
				$object->set($key, $value);
			}
			$object->prepare();
			$object->authenticate_write($auth_data);
			$object->save();

			$response = array(
				'api_version' => '1.0',
				'success_message' => $class_name . ' update successful.',
				'data' => $object->export_as_array()
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

		if ($api_entry->get('apk_permission') < 2) {
			api_error('Unable to create object, insufficient api permission', 'AuthenticationError', 403);
		}

		try {
			if (!$object = $class_name::CreateNew($_POST)) {
				$object = new $class_name(NULL);
				foreach ($_POST as $key => $value) {
					$object->set($key, $value);
				}
				$object->prepare();
				$object->authenticate_write($auth_data);
				$object->save();
			}

			$response = array(
				'api_version' => '1.0',
				'success_message' => 'New ' . $class_name . ' successful.',
				'data' => $object->export_as_array()
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

		if ($api_entry->get('apk_permission') < 4) {
			api_error('Unable to delete object, insufficient api permission', 'AuthenticationError', 403);
		}

		try {
			$object = new $class_name($entity_id, TRUE);
			$object->authenticate_write($auth_data);
			$object->soft_delete();
			$object = new $class_name($entity_id, TRUE);

			$response = array(
				'api_version' => '1.0',
				'success_message' => 'Deletion successful.',
				'data' => $object->export_as_array()
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

	if ($api_entry->get('apk_permission') == 2) {
		api_error('Unable to fetch objects, insufficient permission', 'AuthenticationError', 403);
	}

	// Collection GET
	$class_name = substr($operation, 0, -1);
	$multiclassname = 'Multi' . $class_name;

	parse_str($_SERVER['QUERY_STRING'], $url_parts);

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
	$numobjects = $objects->count_all();
	$objects->load();

	$response_array = array();
	foreach ($objects as $object) {
		try {
			$object->authenticate_read($auth_data);
			$response_array[] = $object->export_as_array();
		} catch (Exception $e) {
			// Skip unauthorized objects
			continue;
		}
	}

	$response = array(
		'api_version' => '1.0',
		'success_message' => '',
		'num_results' => $numobjects,
		'page' => $page,
		'numperpage' => $numperpage,
		'data' => $response_array
	);

} else if (strtolower($url_segments[2] ?? '') === 'actions' && $request_method === 'get') {
	// Action discovery endpoint: GET /api/v1/actions
	$logic_dir = PathHelper::getIncludePath('logic');
	$actions = [];

	foreach (glob($logic_dir . '/*_logic.php') as $file) {
		$basename = basename($file, '.php');           // e.g., "register_logic"
		$action_name = substr($basename, 0, -6);       // e.g., "register" (strip "_logic")
		$api_meta_function = $basename . '_api';        // e.g., "register_logic_api"

		// Check file contents for the _api() function without including
		// (some legacy files have top-level code that would execute on include)
		$contents = file_get_contents($file);
		if (preg_match('/function\s+' . preg_quote($api_meta_function, '/') . '\s*\(/', $contents)) {
			require_once($file);
			if (function_exists($api_meta_function)) {
				$meta = call_user_func($api_meta_function);
				$actions[$action_name] = [
					'description' => $meta['description'] ?? '',
					'requires_session' => $meta['requires_session'] ?? true,
				];
			}
		}
	}

	ksort($actions);
	$response = array(
		'api_version' => '1.0',
		'success_message' => 'Available actions',
		'data' => $actions
	);

} else if (strtolower($url_segments[2] ?? '') === 'action' && isset($url_segments[3])) {
	// Action endpoint: POST /api/v1/action/{action_name}
	if ($request_method !== 'post') {
		api_error('Actions must use POST method', 'ActionError', 405);
	}

	if ($api_entry->get('apk_permission') < 2) {
		api_error('Insufficient API key permission for actions', 'AuthenticationError', 403);
	}

	$action_name = strtolower($url_segments[3]);

	// Validate action name format (security: prevent path traversal)
	if (!preg_match('/^[a-zA-Z0-9_]+$/', $action_name)) {
		api_error('Invalid action name', 'ActionError', 400);
	}

	// Convention: action name maps to logic/{action_name}_logic.php
	$logic_filename = $action_name . '_logic.php';
	try {
		$logic_filepath = PathHelper::getThemeFilePath($logic_filename, 'logic');
	} catch (Exception $e) {
		api_error('Unknown action: ' . $action_name, 'ActionError', 404);
	}

	if (!file_exists($logic_filepath)) {
		api_error('Unknown action: ' . $action_name, 'ActionError', 404);
	}

	require_once($logic_filepath);

	// Check for opt-in: the logic file must define {action_name}_logic_api()
	$api_meta_function = $action_name . '_logic_api';
	$logic_function = $action_name . '_logic';

	if (!function_exists($api_meta_function)) {
		api_error('Unknown action: ' . $action_name, 'ActionError', 404);
	}

	if (!function_exists($logic_function)) {
		api_error('Action is misconfigured: ' . $action_name, 'ActionError', 500);
	}

	// Get metadata
	$meta = call_user_func($api_meta_function);
	$requires_session = $meta['requires_session'] ?? true;

	// Set up session simulation if needed
	$session = SessionControl::get_instance();
	if ($requires_session) {
		$session->set_api_user($api_user->key);
	}

	// Build parameters from JSON request body or form data
	$get_params = $_GET;
	$raw_input = file_get_contents('php://input');
	$json_params = json_decode($raw_input, true);
	$post_params = is_array($json_params) ? $json_params : $_POST;

	// Call the logic function
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	try {
		$result = call_user_func($logic_function, $get_params, $post_params);
	} catch (Exception $e) {
		if ($requires_session) {
			$session->clear_api_user();
		}
		RequestLogger::log('api', 'action ' . $action_name, false, [
			'user_id' => $api_user->key,
			'status_code' => 422,
			'error_type' => 'ActionError',
			'note' => $e->getMessage()
		]);
		$result = LogicResult::error($e->getMessage());
	}

	// Clean up session simulation
	if ($requires_session) {
		$session->clear_api_user();
	}

	// Translate LogicResult to API response
	$translated = api_translate_logic_result($result, $action_name);
	$response_ms = round((microtime(true) - $api_start_time) * 1000);

	if ($translated['status_code'] >= 400) {
		RequestLogger::log('api', 'action ' . $action_name, false, [
			'user_id' => $api_user->key,
			'status_code' => $translated['status_code'],
			'error_type' => $translated['response']['errortype'] ?? 'ActionError',
			'response_ms' => $response_ms,
			'note' => $translated['response']['error'] ?? ''
		]);
	} else {
		RequestLogger::log('api', 'action ' . $action_name, true, [
			'user_id' => $api_user->key,
			'status_code' => $translated['status_code'],
			'response_ms' => $response_ms
		]);
	}

	header("Content-Type: application/json");
	http_response_code($translated['status_code']);
	echo json_encode($translated['response']) . PHP_EOL;
	exit;
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
