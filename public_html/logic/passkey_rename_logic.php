<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function passkey_rename_logic(array $input): LogicResult {
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

	$credential_id = (int)($input['credential_id'] ?? 0);
	$label = isset($input['label']) ? trim($input['label']) : '';

	try {
		$service = new PasskeyService();
		$service->rename($credential_id, $user, $label);
	} catch (Exception $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(['renamed' => true]);
}

function passkey_rename_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'Rename an enrolled passkey',
	];
}
?>
