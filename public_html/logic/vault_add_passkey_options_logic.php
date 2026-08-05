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

	// Activation is per-credential, so the ceremony is scoped to the passkey the
	// caller named. Unscoped, the browser decides which credential answers: pick
	// the security key's row, tap Touch ID at the prompt, and Touch ID gets the
	// wrapping while the row you clicked still reads Not activated. Omitting the
	// id keeps the old any-credential behaviour for a caller that genuinely has
	// no preference.
	$credential_id = isset($input['credential_id']) ? (int)$input['credential_id'] : 0;

	try {
		$service = new PasskeyService();
		$options = $service->getDerivationOptions($user, 'vault-kek',
			$credential_id ? [$credential_id] : null);
	} catch (Exception $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(['options' => $options]);
}

function vault_add_passkey_options_logic_api() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Begin adding a vault wrapping for another PRF-capable passkey (returns WebAuthn PRF request options); pass credential_id to scope the ceremony to one passkey; vault must already be unlocked',
	];
}
?>
