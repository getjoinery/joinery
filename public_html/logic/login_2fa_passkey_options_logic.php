<?php
/**
 * Passkey as a second factor at password sign-in — options
 * (specs/mailbox_security_levels.md § 5.4).
 *
 * An alternative to TOTP in the pending-login state that login_logic sets. The
 * pending user is identified from $_SESSION (they already passed the password),
 * so this needs no login session of its own — it mirrors the sessionless dispatch
 * of the reset ceremonies while operating on the pending-login user.
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../includes/PathHelper.php');

function login_2fa_passkey_options_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
	require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('passkeys_enabled')) {
		return LogicResult::error('Passkeys are not enabled.');
	}

	$uid = $_SESSION['totp_pending_user_id'] ?? null;
	$expires = $_SESSION['totp_pending_expires'] ?? 0;
	if (!$uid || $expires < time()) {
		return LogicResult::error('Your sign-in request has expired. Please sign in again.');
	}

	if (!RequestLogger::check_rate_limit('totp', 10, 300, false)) {
		return LogicResult::error('Too many attempts. Please wait a few minutes and try again.');
	}

	$user = new User((int)$uid, TRUE);
	if (!$user || !$user->key) {
		return LogicResult::error('Your sign-in request has expired. Please sign in again.');
	}

	try {
		$service = new PasskeyService();
		$options = $service->getStepUpOptions($user);
	} catch (Exception $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(['options' => $options]);
}

function login_2fa_passkey_options_logic_api() {
	return [
		'requires_session' => false,
		'description' => 'Begin passkey second-factor confirmation during password sign-in',
	];
}
?>
