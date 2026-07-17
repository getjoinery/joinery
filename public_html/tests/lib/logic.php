<?php
/**
 * Call a logic function directly from a test.
 *
 * Requires tests/lib/harness.php (loaded here).
 *
 * Pages reach their logic through process_logic(), which exits on a redirect —
 * fatal to a test process, and the reason a suite that include()s admin pages
 * dies the moment a save succeeds. Calling the logic function itself hands back
 * the LogicResult instead, in-process, against whatever database the suite's
 * tier selected.
 */

if (!defined('JOINERY_HARNESS_LOGIC_LOADED')) {
	define('JOINERY_HARNESS_LOGIC_LOADED', 1);
	require_once(__DIR__ . '/harness.php');
}

/**
 * Run $logic_fn (defined in $logic_path, relative to public_html) against $data
 * and return its LogicResult. Errors are NOT thrown — assert on the result.
 *
 * $method matters, and getting it wrong fails quietly rather than loudly:
 *   - Saves are gated on LibraryFunctions::isFormSubmission(), which tests
 *     REQUEST_METHOD === 'POST'. The CLI has no request method, so without this
 *     simulation nothing ever saves and the logic still returns a clean result.
 *   - Actions like new_version are GET actions. Send them as POST and the form-
 *     save path claims them instead, nulling fields on the way through.
 *
 * The superglobals are restored afterwards so one call cannot leak request state
 * into the next.
 *
 * Useful LogicResult fields: ->redirect (carries a new record's id on create),
 * ->error, ->validation_errors / ->hasValidationErrors().
 */
function harness_call_logic($logic_path, $logic_fn, array $data = array(), $method = 'POST') {
	$method = strtoupper($method);

	$saved = array(
		'post'   => $_POST,
		'get'    => $_GET,
		'req'    => $_REQUEST,
		'method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null,
	);

	$_POST = ($method === 'POST') ? $data : array();
	$_GET = ($method === 'GET') ? $data : array();
	$_REQUEST = $data;
	$_SERVER['REQUEST_METHOD'] = $method;

	try {
		require_once(PathHelper::getIncludePath($logic_path));
		if (!function_exists($logic_fn)) {
			throw new RuntimeException("$logic_fn is not defined in $logic_path");
		}
		return $logic_fn($data);
	} finally {
		$_POST = $saved['post'];
		$_GET = $saved['get'];
		$_REQUEST = $saved['req'];
		if ($saved['method'] === null) {
			unset($_SERVER['REQUEST_METHOD']);
		} else {
			$_SERVER['REQUEST_METHOD'] = $saved['method'];
		}
	}
}

/**
 * harness_call_logic() plus the assertion that it worked, returning the result.
 * Throws on error or validation failure — for the setup steps in a suite, where
 * a failed fixture build should stop the test rather than be assert()ed one by
 * one.
 */
function harness_call_logic_ok($logic_path, $logic_fn, array $data = array(), $method = 'POST') {
	$result = harness_call_logic($logic_path, $logic_fn, $data, $method);
	if ($result->error) {
		throw new RuntimeException("$logic_fn error: " . $result->error);
	}
	if ($result->hasValidationErrors()) {
		throw new RuntimeException("$logic_fn validation errors: " . json_encode($result->validation_errors));
	}
	return $result;
}
