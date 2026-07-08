<?php
/**
 * Sessionless passkey password-reset — step 1: request options
 * (specs/mailbox_security_levels.md § Password reset, Population 3).
 *
 * Mirrors passkey_login_options exactly (usernameless-capable), but is a RESET
 * authorizer, not a sign-in: it never establishes a login by itself. The verify
 * step decides whether a vault holder additionally needs an independent second
 * factor before the reset is authorized.
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../includes/PathHelper.php');

function password_reset_passkey_options_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
	require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('passkeys_enabled')) {
		return LogicResult::error('Passkey sign-in is not enabled.');
	}
	if (!$settings->get_setting('register_active')) {
		return LogicResult::error('This feature is turned off');
	}

	if (!RequestLogger::check_rate_limit('password_reset', 10, 900, false)) {
		return LogicResult::error('Too many attempts. Please wait a few minutes and try again.');
	}

	$email = isset($input['email']) ? trim($input['email']) : '';

	try {
		$service = new PasskeyService();
		$options = $service->getAuthenticationOptions($email !== '' ? $email : null);
	} catch (Exception $e) {
		RequestLogger::log('password_reset', 'passkey_options', false);
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(['options' => $options]);
}

function password_reset_passkey_options_logic_api() {
	return [
		'requires_session' => false,
		'description' => 'Begin a passkey password reset (returns WebAuthn request options)',
	];
}
?>
