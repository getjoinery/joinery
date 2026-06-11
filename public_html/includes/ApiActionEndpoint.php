<?php
/**
 * API Action Endpoint
 *
 * Executes POST /api/v1/action/{action_name}: a logic function exposed to the
 * API via its {action}_logic_api() companion.
 *
 * Auth mirrors the action's requires_session declaration:
 *   - sessionless actions (register, password_reset_1/2) are dispatched before
 *     key authentication — a first-launch client has no credentials yet
 *   - sessioned actions require key auth and run under session simulation as
 *     the acting user
 *
 * Dispatched from api/apiv1.php in two places, mirroring ApiFormEndpoint:
 * dispatchPreAuth() before the key-header requirement, dispatchAuthenticated()
 * after $api_user resolves. Uses the api_error()/api_success()/
 * api_translate_logic_result() helpers defined in apiv1.php.
 *
 * Action names resolve through api_resolve_logic_path() (apiv1.php):
 * single-segment names are core actions (theme chain); {plugin}/{action}
 * names resolve directly to the active plugin's logic directory.
 *
 * @version 1.1.0
 */

class ApiActionEndpoint {

	/**
	 * Resolve the action path to its metadata and logic function, or exit
	 * with an API error.
	 *
	 * @param array $url_segments ['api', 'v1', 'action', '{action_name}'] or
	 *                            ['api', 'v1', 'action', '{plugin}', '{action}']
	 * @return array [$action_label, $meta, $logic_function]
	 */
	protected static function resolve($url_segments) {
		if (strtolower($_SERVER['REQUEST_METHOD']) !== 'post') {
			api_error('Actions must use POST method', 'ActionError', 405);
		}

		list($action_label, $action_name) = api_resolve_logic_path($url_segments, 'action');

		// Check for opt-in: the logic file must define {action_name}_logic_api()
		$api_meta_function = $action_name . '_logic_api';
		$logic_function = $action_name . '_logic';

		if (!function_exists($api_meta_function)) {
			api_error('Unknown action: ' . $action_label, 'ActionError', 404);
		}

		if (!function_exists($logic_function)) {
			api_error('Action is misconfigured: ' . $action_label, 'ActionError', 500);
		}

		$meta = call_user_func($api_meta_function);

		return array($action_label, $meta, $logic_function);
	}

	/**
	 * Pre-authentication dispatch. Executes sessionless actions and exits;
	 * returns for sessioned actions so key authentication continues, after
	 * which dispatchAuthenticated() handles the request.
	 */
	public static function dispatchPreAuth($url_segments) {
		list($action_label, $meta, $logic_function) = self::resolve($url_segments);

		if ($meta['requires_session'] ?? true) {
			return;
		}

		self::execute($action_label, $logic_function, NULL);
	}

	/**
	 * Post-authentication dispatch for sessioned actions. Always exits.
	 */
	public static function dispatchAuthenticated($url_segments, $api_entry, $api_user) {
		list($action_label, $meta, $logic_function) = self::resolve($url_segments);

		if ($api_entry->get('apk_permission') < 2) {
			api_error('Insufficient API key permission for actions', 'AuthenticationError', 403);
		}

		// Sessionless actions were executed pre-auth; anything reaching here
		// with requires_session=false would be a dispatch-order bug, so run it
		// the same way regardless.
		$acting_user = ($meta['requires_session'] ?? true) ? $api_user : NULL;

		self::execute($action_label, $logic_function, $acting_user);
	}

	/**
	 * Run the logic function and send the translated result. Always exits.
	 *
	 * @param string $action_label Full action name for logs ('{plugin}/{action}' or '{action}')
	 * @param string $logic_function
	 * @param User|null $api_user Acting user for session simulation (null = sessionless)
	 */
	protected static function execute($action_label, $logic_function, $api_user) {
		$user_id = $api_user ? $api_user->key : NULL;

		// Set up session simulation if needed
		$session = SessionControl::get_instance();
		if ($api_user) {
			$session->set_api_user($api_user->key);
		}

		// Build parameters from JSON request body or form data
		$get_params = $_GET;
		$raw_input = file_get_contents('php://input');
		$json_params = json_decode($raw_input, true);
		$post_params = is_array($json_params) ? $json_params : $_POST;

		// Populate $_POST from JSON body so logic files can use !empty($_POST)
		// to detect submission consistently across browser POSTs and JSON API calls.
		$_POST = $post_params;

		// Call the logic function
		require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
		try {
			$result = call_user_func($logic_function, array_merge($get_params, $post_params));
		} catch (Exception $e) {
			if ($api_user) {
				$session->clear_api_user();
			}
			RequestLogger::log('api', 'action ' . $action_label, false, [
				'user_id' => $user_id,
				'status_code' => 422,
				'error_type' => 'ActionError',
				'note' => $e->getMessage()
			]);
			$result = LogicResult::error($e->getMessage());
		}

		// Clean up session simulation
		if ($api_user) {
			$session->clear_api_user();
		}

		// Translate LogicResult to API response
		$translated = api_translate_logic_result($result, $action_label);
		$response_ms = isset($GLOBALS['api_start_time'])
			? round((microtime(true) - $GLOBALS['api_start_time']) * 1000) : NULL;

		if ($translated['status_code'] >= 400) {
			RequestLogger::log('api', 'action ' . $action_label, false, [
				'user_id' => $user_id,
				'status_code' => $translated['status_code'],
				'error_type' => $translated['response']['errortype'] ?? 'ActionError',
				'response_ms' => $response_ms,
				'note' => $translated['response']['error'] ?? ''
			]);
		} else {
			RequestLogger::log('api', 'action ' . $action_label, true, [
				'user_id' => $user_id,
				'status_code' => $translated['status_code'],
				'response_ms' => $response_ms
			]);
		}

		header("Content-Type: application/json");
		http_response_code($translated['status_code']);
		echo json_encode($translated['response']) . PHP_EOL;
		exit;
	}
}

?>
