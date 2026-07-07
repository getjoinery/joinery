<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function passkey_stepup_options_logic(array $input): LogicResult {
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

	try {
		$service = new PasskeyService();
		$options = $service->getStepUpOptions($user);
	} catch (Exception $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(['options' => $options]);
}

function passkey_stepup_options_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'Begin passkey step-up confirmation (returns WebAuthn request options scoped to the current user)',
	];
}
?>
