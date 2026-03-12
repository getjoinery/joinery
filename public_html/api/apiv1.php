<?php
/**
 * API v1 Endpoint
 *
 * @version 2.1
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
$headers = getallheaders();
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
