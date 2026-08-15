<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function vault_unlock_options_logic(array $input): LogicResult {
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
		return LogicResult::error('Your vault is not set up yet.');
	}

	// A phrase-only vault (the compatibility fallback) has no passkey wrapping,
	// so a passkey ceremony can never unlock it. Refuse with a pointer to the
	// unlocker that works instead of listing credentials that open nothing.
	if (!VaultUnlock::hasPasskeyRoute((int)$user->key, UserEncryptionVault::SCOPE_USER)) {
		return LogicResult::error(
			'No passkey currently unlocks your vault - use your bypass phrase or a recovery code. Adding a passkey from your security page while unlocked gives you the passkey route.',
			['no_passkey_route' => true]
		);
	}

	try {
		$service = new PasskeyService();
		$options = $service->getDerivationOptions($user, 'vault-kek',
			VaultUnlock::offerableCredentialIds((int)$user->key, UserEncryptionVault::SCOPE_USER));
	} catch (Exception $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(['options' => $options]);
}

function vault_unlock_options_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Begin unlocking the vault with a passkey (returns WebAuthn PRF request options, userVerification required)',
	];
}
?>
