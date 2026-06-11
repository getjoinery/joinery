<?php
/**
 * Form Definition API Endpoint
 *
 * Serves GET /api/v1/form/{action_name}: a JSON form definition built by the
 * action's form builder companion ({action}_logic_form) rendered through
 * FormWriterV2JSON. A form is exposed iff both {action}_logic_api() and
 * {action}_logic_form() exist.
 *
 * Auth mirrors the action's requires_session declaration:
 *   - sessionless forms (register, password_reset_1/2) are served before
 *     key authentication — a first-launch client has no credentials yet
 *   - sessioned forms require key auth and prefill from the acting user
 *
 * Dispatched from api/apiv1.php in two places: dispatchPreAuth() before the
 * key-header requirement, dispatchAuthenticated() after $api_user resolves.
 * Uses the api_error()/api_success() helpers defined in apiv1.php.
 *
 * @version 1.0.0
 */

class ApiFormEndpoint {

	/**
	 * Resolve {action_name} to its metadata and builder, or exit with an
	 * API error. Both companion functions must exist for the form to be
	 * exposed (the exposure rule).
	 *
	 * @param array $url_segments ['api', 'v1', 'form', '{action_name}']
	 * @return array [$action_name, $meta, $form_function]
	 */
	protected static function resolve($url_segments) {
		if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
			api_error('Form definitions must use GET method', 'ActionError', 405);
		}

		if (empty($url_segments[3])) {
			api_error('Form name required', 'ActionError', 400);
		}

		$action_name = strtolower($url_segments[3]);

		// Validate action name format (security: prevent path traversal)
		if (!preg_match('/^[a-zA-Z0-9_]+$/', $action_name)) {
			api_error('Invalid form name', 'ActionError', 400);
		}

		try {
			$logic_filepath = PathHelper::getThemeFilePath($action_name . '_logic.php', 'logic');
		} catch (Exception $e) {
			api_error('Unknown form: ' . $action_name, 'ActionError', 404);
		}

		if (!file_exists($logic_filepath)) {
			api_error('Unknown form: ' . $action_name, 'ActionError', 404);
		}

		require_once($logic_filepath);

		$api_meta_function = $action_name . '_logic_api';
		$form_function = $action_name . '_logic_form';

		if (!function_exists($api_meta_function) || !function_exists($form_function)) {
			api_error('Unknown form: ' . $action_name, 'ActionError', 404);
		}

		$meta = call_user_func($api_meta_function);

		return array($action_name, $meta, $form_function);
	}

	/**
	 * Pre-authentication dispatch. Serves sessionless forms and exits;
	 * returns for sessioned forms so key authentication continues, after
	 * which dispatchAuthenticated() handles the request.
	 */
	public static function dispatchPreAuth($url_segments) {
		list($action_name, $meta, $form_function) = self::resolve($url_segments);

		if ($meta['requires_session'] ?? true) {
			return;
		}

		self::serve($action_name, $form_function, null, null);
	}

	/**
	 * Post-authentication dispatch for sessioned forms. Always exits.
	 */
	public static function dispatchAuthenticated($url_segments, $api_entry, $api_user) {
		list($action_name, $meta, $form_function) = self::resolve($url_segments);

		// Fetching a definition is a read — same gate as object reads
		// (permission 2 is write-only)
		if ($api_entry->get('apk_permission') == 2) {
			api_error('Unable to fetch form, insufficient api permission', 'AuthenticationError', 403);
		}

		// Session simulation so the builder sees the acting user's timezone
		// and session context, exactly as the action endpoint provides
		$session = SessionControl::get_instance();
		$session->set_api_user($api_user->key);

		self::serve($action_name, $form_function, $api_user, $api_user->key);
	}

	/**
	 * Build the definition and send it. Always exits.
	 *
	 * @param string $action_name
	 * @param string $form_function Builder function name
	 * @param User|null $user Acting user for prefill (null for sessionless)
	 * @param int|null $user_id For request logging
	 */
	protected static function serve($action_name, $form_function, $user, $user_id) {
		require_once(PathHelper::getIncludePath('includes/FormWriterV2JSON.php'));

		$formwriter = new FormWriterV2JSON($action_name);

		try {
			call_user_func($form_function, $formwriter, $user, $_GET);
			$definition = $formwriter->getDefinition();
		} catch (Exception $e) {
			if ($user_id) {
				SessionControl::get_instance()->clear_api_user();
			}
			RequestLogger::log('api', 'form ' . $action_name, false, [
				'user_id' => $user_id,
				'status_code' => 500,
				'error_type' => 'ActionError',
				'note' => $e->getMessage()
			]);
			api_error('Unable to build form definition (' . $e->getMessage() . ')', 'ActionError', 500);
		}

		if ($user_id) {
			SessionControl::get_instance()->clear_api_user();
		}

		RequestLogger::log('api', 'form ' . $action_name, true, [
			'user_id' => $user_id,
			'status_code' => 200
		]);

		api_success($definition, 'Form definition for \'' . $action_name . '\'');
	}
}

?>
