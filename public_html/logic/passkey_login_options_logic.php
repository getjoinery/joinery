<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function passkey_login_options_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
	require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('passkeys_enabled')) {
		return LogicResult::error('Passkey sign-in is not enabled.');
	}

	if (!RequestLogger::check_rate_limit('passkey_login', 20, 900, false)) {
		return LogicResult::error('Too many attempts. Please wait a few minutes and try again.');
	}

	$email = isset($input['email']) ? trim($input['email']) : '';

	// Best-effort early check for the vault-activation flip (docs/sealed_vault.md):
	// only fires for a resolved account with a vault, so a nonexistent or
	// vault-less account still falls through to the normal (ambiguous) options
	// response below. The verify-side check is the actual enforcement - a
	// usernameless request can't be checked here at all.
	if ($email !== '') {
		require_once(PathHelper::getIncludePath('data/users_class.php'));
		require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
		$existing_user = User::GetByEmail($email);
		if ($existing_user && $existing_user->key && UserEncryptionVault::loadForUser($existing_user->key)) {
			RequestLogger::log('passkey_login', 'options', false);
			return LogicResult::error(
				'This account signs in with a password - your vault requires it as a second factor. '
				. 'Your passkey still works to confirm sensitive actions and unlock your vault.'
			);
		}
	}

	try {
		$service = new PasskeyService();
		$options = $service->getAuthenticationOptions($email !== '' ? $email : null);
	} catch (Exception $e) {
		RequestLogger::log('passkey_login', 'options', false);
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(['options' => $options]);
}

function passkey_login_options_logic_descriptor() {
	return [
		'requires_session' => false,
		'description' => 'Begin passwordless passkey sign-in (returns WebAuthn request options)',
	];
}
?>
