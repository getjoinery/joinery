<?php
/**
 * ManagementApiRouter
 *
 * Dispatches /api/v1/management/* requests to handler files under
 * includes/management_api/. Called by apiv1.php after authentication has
 * passed — the router does NOT re-do auth.
 *
 * Authorization: the owning user's permission must be >= 10 (superadmin).
 * apk_permission is the CRUD-axis permission; it is NOT the gate used here.
 *
 * Endpoint convention:
 *   URL:  /api/v1/management/stats
 *   File: includes/management_api/stats_handler.php
 *   Funcs:
 *     stats_handler($request)       — does the work, returns array|null
 *     stats_handler_api()           — returns ['method' => ..., 'description' => ...]
 *
 * Nested endpoints:
 *   URL:  /api/v1/management/backups/list
 *   File: includes/management_api/backups/list_handler.php
 *   Func: backups_list_handler(), backups_list_handler_api()
 *
 * Discovery:
 *   GET /api/v1/management  →  lists every available endpoint + metadata.
 *
 * @version 1.0
 */

class ManagementApiRouter {

	/**
	 * Dispatch a management-api request. Always exits.
	 *
	 * $url_segments: path segments from apiv1.php, e.g.
	 *   ['api', 'v1', 'management', 'stats']              → endpoint "stats"
	 *   ['api', 'v1', 'management', 'backups', 'list']    → endpoint "backups/list"
	 *   ['api', 'v1', 'management']                       → discovery endpoint
	 *
	 * $auth_data must contain 'current_user_permission' (superadmin gate).
	 * $request_method is the lowercased HTTP method from apiv1.php.
	 */
	public static function dispatch($url_segments, $auth_data, $request_method) {
		// Superadmin gate — single authorization check for all management endpoints.
		if (($auth_data['current_user_permission'] ?? 0) < 10) {
			api_error('Management API requires superadmin permission', 'AuthenticationError', 403);
		}

		// Extract the endpoint path (everything after "management")
		$endpoint_segments = array_slice($url_segments, 3);

		// Discovery endpoint: GET /api/v1/management
		if (empty($endpoint_segments)) {
			if (strtolower($request_method) !== 'get') {
				api_error('Discovery endpoint must use GET', 'ActionError', 405);
			}
			self::handle_discovery();
			return;
		}

		$endpoint_path = implode('/', $endpoint_segments);

		// Validate path segments for security (letters, numbers, underscores only).
		// No dots, no slashes in individual segments, no path traversal.
		foreach ($endpoint_segments as $seg) {
			if (!preg_match('/^[a-zA-Z0-9_]+$/', $seg)) {
				api_error('Invalid endpoint path', 'ActionError', 400);
			}
		}

		// Resolve file path: path/to/endpoint → includes/management_api/path/to/endpoint_handler.php
		$base_dir = PathHelper::getIncludePath('includes/management_api');
		$handler_file = $base_dir . '/' . $endpoint_path . '_handler.php';

		if (!file_exists($handler_file)) {
			api_error('Unknown management endpoint: ' . $endpoint_path, 'ActionError', 404);
		}

		require_once($handler_file);

		// Function names: collapse slashes to underscores
		// "backups/list" → "backups_list_handler", "backups_list_handler_api"
		$function_base = str_replace('/', '_', $endpoint_path) . '_handler';
		$meta_function = $function_base . '_api';

		// Meta function is mandatory — its absence means "not exposed"
		if (!function_exists($meta_function)) {
			api_error('Unknown management endpoint: ' . $endpoint_path, 'ActionError', 404);
		}
		if (!function_exists($function_base)) {
			api_error('Endpoint is misconfigured: ' . $endpoint_path, 'ActionError', 500);
		}

		$meta = call_user_func($meta_function);
		$expected_method = strtoupper($meta['method'] ?? 'GET');
		$actual_method   = strtoupper($request_method);

		if ($expected_method !== $actual_method) {
			api_error("Endpoint '{$endpoint_path}' requires {$expected_method}, got {$actual_method}",
				'ActionError', 405);
		}

		// Build $request
		$raw_input = file_get_contents('php://input');
		$decoded_body = null;
		if ($actual_method !== 'GET' && $raw_input !== '' && $raw_input !== false) {
			$decoded_body = json_decode($raw_input, true);
		}

		$request = [
			'method'  => $actual_method,
			'path'    => $endpoint_path,
			'query'   => $_GET,
			'body'    => $decoded_body,
			'headers' => getallheaders(),
		];

		// Invoke handler. Handler returns an array (wrapped by api_success),
		// or null when it has streamed its own response.
		try {
			$result = call_user_func($function_base, $request);
		} catch (Exception $e) {
			api_error($e->getMessage(), 'TransactionError', 500);
		}

		// Streaming handlers return null — they've already sent their response
		if ($result === null) {
			exit;
		}

		api_success($result, '', 200);
	}

	/**
	 * GET /api/v1/management — list every available endpoint with its metadata.
	 */
	private static function handle_discovery() {
		$base_dir = PathHelper::getIncludePath('includes/management_api');
		if (!is_dir($base_dir)) {
			api_success([], 'No management endpoints available', 200);
		}

		$endpoints = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($base_dir, RecursiveDirectoryIterator::SKIP_DOTS)
		);

		foreach ($iterator as $file) {
			if (!$file->isFile() || $file->getExtension() !== 'php') {
				continue;
			}
			$basename = $file->getBasename('.php'); // e.g. "stats_handler" or "list_handler"
			if (substr($basename, -8) !== '_handler') {
				continue;
			}

			// Derive endpoint path from the file's location under $base_dir
			$relative = substr($file->getPathname(), strlen($base_dir) + 1); // e.g. "backups/list_handler.php"
			$relative_no_ext = substr($relative, 0, -4);                     // "backups/list_handler"
			$endpoint_path = substr($relative_no_ext, 0, -8);                // "backups/list"

			// Read file and check for the meta function before requiring
			// (parallels the actions-discovery pattern in apiv1.php)
			$contents = file_get_contents($file->getPathname());
			$meta_function = str_replace('/', '_', $endpoint_path) . '_handler_api';
			if (!preg_match('/function\s+' . preg_quote($meta_function, '/') . '\s*\(/', $contents)) {
				continue;
			}

			require_once($file->getPathname());
			if (!function_exists($meta_function)) {
				continue;
			}

			$meta = call_user_func($meta_function);
			$endpoints[$endpoint_path] = [
				'method'      => strtoupper($meta['method'] ?? 'GET'),
				'description' => $meta['description'] ?? '',
			];
		}

		ksort($endpoints);
		api_success($endpoints, 'Available management endpoints', 200);
	}
}
?>
