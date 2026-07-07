<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function vault_setup_options_logic(array $input): LogicResult {
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

	// The vault-activation flip (specs/mailbox_security_levels.md § Authentication):
	// a passkey never opens both session and vault on the same account, so a
	// vault holder must have a working password as the second factor. Setup
	// refuses to start until one is set - the existing password-change form
	// handles setting it, this ceremony does not duplicate that UI.
	if (!$user->get('usr_password')) {
		return LogicResult::error(
			'Set an account password before enabling your vault - a vault holder always keeps password sign-in as a second factor.',
			['requires_password' => true]
		);
	}

	$existing = new MultiUserEncryptionVault(['user_id' => $user->key, 'scope' => UserEncryptionVault::SCOPE_USER]);
	if ($existing->count_all() > 0) {
		return LogicResult::error('Your vault is already set up.');
	}

	try {
		$service = new PasskeyService();
		$options = $service->getDerivationOptions($user, 'vault-kek');
	} catch (Exception $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(['options' => $options]);
}

function vault_setup_options_logic_api() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Begin Sealed Vault setup (returns WebAuthn PRF request options); requires an existing account password',
	];
}
?>
