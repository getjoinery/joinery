<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function passkey_register_verify_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('passkeys_enabled')) {
		return LogicResult::error('Passkeys are not enabled.');
	}

	$session = SessionControl::get_instance();
	$session->check_permission(0);

	$credential = $input['credential'] ?? null;
	if (!is_array($credential)) {
		return LogicResult::error('Missing passkey credential response.');
	}
	$label = isset($input['label']) ? trim($input['label']) : '';

	try {
		$service = new PasskeyService();
		$passkey = $service->verifyRegistration(json_encode($credential), $label);
	} catch (Exception $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(['passkey' => $passkey->export_for_api()]);
}

function passkey_register_verify_logic_api() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Complete passkey enrollment and persist the new credential',
	];
}
?>
