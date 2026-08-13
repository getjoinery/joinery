<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function passkey_login_verify_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
	require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));
	require_once(PathHelper::getIncludePath('data/login_class.php'));

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('passkeys_enabled')) {
		return LogicResult::error('Passkey sign-in is not enabled.');
	}

	if (!RequestLogger::check_rate_limit('passkey_login', 20, 900, false)) {
		return LogicResult::error('Too many attempts. Please wait a few minutes and try again.');
	}

	$credential = $input['credential'] ?? null;
	if (!is_array($credential)) {
		return LogicResult::error('Missing passkey credential response.');
	}

	require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));

	try {
		$service = new PasskeyService();
		$user = $service->verifyAuthentication(json_encode($credential));
	} catch (Exception $e) {
		RequestLogger::log('passkey_login', 'verify', false);
		return LogicResult::error($e->getMessage());
	}

	// The vault-activation flip (docs/sealed_vault.md): a passkey never signs a
	// vault holder in - password sign-in stays the second factor alongside it.
	// verifyAuthentication() already established the session
	// (SessionControl::store_session_variables()), so a rejection here must
	// undo it rather than leave a live authenticated session behind.
	if (UserEncryptionVault::loadForUser($user->key)) {
		$_SESSION = array();
		RequestLogger::log('passkey_login', 'verify', false, ['user_id' => $user->key]);
		return LogicResult::error(
			'This account signs in with a password - your vault requires it as a second factor. '
			. 'Your passkey still works to confirm sensitive actions and unlock your vault.'
		);
	}

	LoginClass::StoreUserLogin($user->key, LoginClass::LOGIN_FORM);

	RequestLogger::log('passkey_login', 'verify', true, ['user_id' => $user->key]);

	$alternate_homepage = $settings->get_setting('alternate_loggedin_homepage');
	return LogicResult::render([
		'user' => [
			'usr_user_id' => $user->key,
			'usr_email'   => $user->get('usr_email'),
			'usr_first_name' => $user->get('usr_first_name'),
			'usr_last_name'  => $user->get('usr_last_name'),
		],
		'redirect' => $alternate_homepage ?: '/profile',
	]);
}

function passkey_login_verify_logic_descriptor() {
	return [
		'requires_session' => false,
		'description' => 'Complete passwordless passkey sign-in and establish the browser session',
	];
}
?>
