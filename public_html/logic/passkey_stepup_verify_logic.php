<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function passkey_stepup_verify_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('passkeys_enabled')) {
		return LogicResult::error('Passkeys are not enabled.');
	}

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user = new User($session->get_user_id(), TRUE);

	$credential = $input['credential'] ?? null;
	if (!is_array($credential)) {
		return LogicResult::error('Missing passkey credential response.');
	}

	try {
		$service = new PasskeyService();
		$service->verifyStepUp(json_encode($credential), $user);
	} catch (Exception $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(['verified' => true]);
}

function passkey_stepup_verify_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'Complete passkey step-up confirmation, marking the session recently re-verified',
	];
}
?>
