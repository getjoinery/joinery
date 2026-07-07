<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function vault_rotate_options_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('passkeys_enabled')) {
		return LogicResult::error('Passkeys are not enabled.');
	}

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user = new User($session->get_user_id(), TRUE);

	if (!UserEncryptionVault::loadForUser($user->key)) {
		return LogicResult::error('Your vault is not set up yet.');
	}

	try {
		$service = new PasskeyService();
		$options = $service->getDerivationOptions($user, 'vault-kek');
	} catch (Exception $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(['options' => $options]);
}

function vault_rotate_options_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'Begin vault key rotation (returns WebAuthn PRF request options); use an already-enrolled passkey',
	];
}
?>
