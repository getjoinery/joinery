<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

/**
 * Client-side passkey ceremony failure telemetry. The browser's WebAuthn
 * layer refuses ceremonies with opaque errors (NotAllowedError) that never
 * reach any server action - the JS helper posts them here so failures are
 * visible in the request log (feature 'passkey_client') with the surface,
 * error name, timing, and focus state attached.
 */
function passkey_client_report_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$context = substr(preg_replace('#[^a-zA-Z0-9:_/.\-]#', '', (string)($input['context'] ?? 'unknown')), 0, 80);
	$name = substr(trim((string)($input['error_name'] ?? 'Error')), 0, 40);
	$message = substr(trim((string)($input['error_message'] ?? '')), 0, 140);
	$focus = !empty($input['focus']) ? '1' : '0';
	$visibility = substr(preg_replace('#[^a-z]#', '', (string)($input['visibility'] ?? '')), 0, 10);
	$elapsed_ms = isset($input['elapsed_ms']) ? (int)$input['elapsed_ms'] : null;

	$log_options = [
		'user_id' => $session->get_user_id(),
		'note' => $name . ': ' . $message . ' [focus=' . $focus . ' vis=' . $visibility . ']',
	];
	if ($elapsed_ms !== null) {
		$log_options['response_ms'] = $elapsed_ms;
	}
	RequestLogger::log('passkey_client', $context, false, $log_options);

	return LogicResult::render(['recorded' => true]);
}

function passkey_client_report_logic_api() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Record a browser-side passkey ceremony failure (error name, surface, timing) for diagnostics',
	];
}
?>
