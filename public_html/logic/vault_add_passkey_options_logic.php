<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function vault_add_passkey_options_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
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
		return LogicResult::error('Set up your vault before adding another passkey to it.');
	}
	// Wrapping the vault secret under a new credential needs the secret
	// itself, which only exists in-window - the caller must already be
	// unlocked (e.g. via the passkey/recovery/passphrase they already hold).
	if (!VaultUnlock::isOpen($user->key, UserEncryptionVault::SCOPE_USER)) {
		return LogicResult::error('Unlock your vault before adding another passkey to it.', ['locked' => true]);
	}

	try {
		$service = new PasskeyService();
		$options = $service->getDerivationOptions($user, 'vault-kek');
	} catch (Exception $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(['options' => $options]);
}

function vault_add_passkey_options_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'Begin adding a vault wrapping for another PRF-capable passkey (returns WebAuthn PRF request options); vault must already be unlocked',
	];
}
?>
