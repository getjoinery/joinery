<?php
/**
 * Vault-holder passkey reset — second factor via a DIFFERENT passkey: options
 * (specs/mailbox_security_levels.md § Password reset, Population 3).
 *
 * Offered on /password-reset-2fa when the pending account has another live
 * passkey besides the one that authorized the reset. The allow list excludes the
 * reset credential so the second factor is genuinely independent — one stolen
 * authenticator cannot satisfy both.
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../includes/PathHelper.php');

function password_reset_2fa_passkey_options_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
	require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('passkeys_enabled')) {
		return LogicResult::error('Passkeys are not enabled.');
	}

	$uid = $_SESSION['pwreset_pk_user_id'] ?? null;
	$expires = $_SESSION['pwreset_pk_expires'] ?? 0;
	if (!$uid || $expires < time()) {
		return LogicResult::error('Your reset request has expired. Please start again.');
	}

	if (!RequestLogger::check_rate_limit('password_reset', 10, 900, false)) {
		return LogicResult::error('Too many attempts. Please wait a few minutes and try again.');
	}

	$user = new User((int)$uid, TRUE);
	if (!$user || !$user->key) {
		return LogicResult::error('Your reset request has expired. Please start again.');
	}

	$used_cred = (string)($_SESSION['pwreset_pk_used_cred'] ?? '');

	try {
		$service = new PasskeyService();
		$options = $service->getStepUpOptions($user, $used_cred !== '' ? [$used_cred] : []);
	} catch (Exception $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(['options' => $options]);
}

function password_reset_2fa_passkey_options_logic_api() {
	return [
		'requires_session' => false,
		'description' => 'Begin the passkey second factor for a vault-holder password reset',
	];
}
?>
