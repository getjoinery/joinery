<?php
/**
 * Passkey as a second factor at password sign-in — verify
 * (specs/mailbox_security_levels.md § 5.4).
 *
 * Verifies the step-up assertion against the pending-login user and, on success,
 * completes the login through the shared Login2fa helper — the same completion the
 * TOTP path uses. This is what makes a passkey a first-class alternative to TOTP
 * at sign-in, closing the quirk where a passkey-only Fortress user was never asked
 * a second factor.
 *
 * @version 1.1
 */
require_once(__DIR__ . '/../includes/PathHelper.php');

function login_2fa_passkey_verify_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
	require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));
	require_once(PathHelper::getIncludePath('includes/Login2fa.php'));
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

	$credential = $input['credential'] ?? null;
	if (!is_array($credential)) {
		return LogicResult::error('Missing passkey credential response.');
	}

	$user = new User((int)$uid, TRUE);
	if (!$user || !$user->key) {
		return LogicResult::error('Your sign-in request has expired. Please sign in again.');
	}

	try {
		$service = new PasskeyService();
		$service->verifyStepUp(json_encode($credential), $user);
	} catch (Exception $e) {
		RequestLogger::log('totp', 'passkey_2fa', false, ['user_id' => (int)$uid]);
		return LogicResult::error($e->getMessage());
	}

	RequestLogger::log('totp', 'passkey_2fa', true, ['user_id' => (int)$uid]);
	$redirect = Login2fa::completePendingLogin($user, !empty($input['trust_device']));
	return LogicResult::render(['redirect' => $redirect]);
}

function login_2fa_passkey_verify_logic_api() {
	return [
		'requires_session' => false,
		'description' => 'Complete passkey second-factor confirmation and finish password sign-in',
	];
}
?>
