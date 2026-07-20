<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

/**
 * Superadmin passkey lab: verify a diagnostic assertion. Grants nothing -
 * no step-up marker, no unlock. See adm/admin_passkey_lab.php.
 */
function passkey_lab_verify_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));
	require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('passkeys_enabled')) {
		return LogicResult::error('Passkeys are not enabled.');
	}

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	if ((int)$session->get_permission() < 10) {
		return LogicResult::error('Not authorized.');
	}
	$user = new User($session->get_user_id(), TRUE);

	$variant_key = substr(trim((string)($input['variant'] ?? 'unnamed')), 0, 40);
	$credential = $input['credential'] ?? null;
	if (!is_array($credential)) {
		return LogicResult::error('Missing passkey credential response.');
	}

	try {
		$service = new PasskeyService();
		$result = $service->verifyDiagnostic(json_encode($credential), $user);
	} catch (Exception $e) {
		RequestLogger::log('passkey_lab', 'verify:' . $variant_key, false, [
			'user_id' => $user->key,
			'note' => $e->getMessage(),
		]);
		return LogicResult::error($e->getMessage());
	}

	RequestLogger::log('passkey_lab', 'verify:' . $variant_key, true, [
		'user_id' => $user->key,
		'note' => 'answered by "' . $result['label'] . '" prf_returned=' . ($result['prf_returned'] ? '1' : '0'),
	]);

	return LogicResult::render($result);
}

function passkey_lab_verify_logic_api() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Passkey lab (superadmin): verify a diagnostic assertion (no step-up marker is set)',
	];
}
?>
