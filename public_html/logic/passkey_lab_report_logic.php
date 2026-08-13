<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

/**
 * Superadmin passkey lab: record the browser-side outcome of a diagnostic
 * ceremony. This is the only place a client-side WebAuthn rejection
 * (NotAllowedError etc.) becomes visible server-side - the browser never
 * reaches the verify action when navigator.credentials.get() fails.
 */
function passkey_lab_report_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	if ((int)$session->get_permission() < 10) {
		return LogicResult::error('Not authorized.');
	}

	$variant_key = substr(trim((string)($input['variant'] ?? 'unnamed')), 0, 40);
	$success = ($input['outcome'] ?? '') === 'success';
	$elapsed_ms = isset($input['elapsed_ms']) ? (int)$input['elapsed_ms'] : null;

	if ($success) {
		$note = trim((string)($input['detail'] ?? 'ceremony completed'));
	} else {
		$note = trim((string)($input['error_name'] ?? 'Error')) . ': '
			. trim((string)($input['error_message'] ?? '(no message)'));
	}

	$log_options = ['user_id' => $session->get_user_id(), 'note' => $note];
	if ($elapsed_ms !== null) {
		$log_options['response_ms'] = $elapsed_ms;
	}
	RequestLogger::log('passkey_lab', 'client:' . $variant_key, $success, $log_options);

	return LogicResult::render(['recorded' => true]);
}

function passkey_lab_report_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Passkey lab (superadmin): record the browser-side outcome of a diagnostic ceremony',
	];
}
?>
