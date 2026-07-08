<?php
/**
 * Vault-holder passkey reset — second factor via a DIFFERENT passkey: verify
 * (specs/mailbox_security_levels.md § Password reset, Population 3).
 *
 * Verifies the step-up assertion against the pending account and confirms the
 * responding credential is NOT the passkey that authorized the reset — checked
 * against the client-sent id before the ceremony (fast path) and against the
 * server-verified credential after it (the authoritative check). On success the
 * passkey + an independent second factor are both proven, so it hands off to
 * the built completion path.
 *
 * @version 1.1
 */
require_once(__DIR__ . '/../includes/PathHelper.php');

function password_reset_2fa_passkey_verify_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
	require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));
	require_once(PathHelper::getIncludePath('includes/PasswordResetAuthorizers.php'));
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

	$credential = $input['credential'] ?? null;
	if (!is_array($credential)) {
		return LogicResult::error('Missing passkey credential response.');
	}

	$user = new User((int)$uid, TRUE);
	if (!$user || !$user->key) {
		return LogicResult::error('Your reset request has expired. Please start again.');
	}

	$used_cred = (string)($_SESSION['pwreset_pk_used_cred'] ?? '');
	$responding = PasswordResetAuthorizers::usedCredentialId($credential);
	if ($responding !== '' && $responding === $used_cred) {
		RequestLogger::log('password_reset', 'passkey_reset_2fa_passkey', false, ['user_id' => (int)$uid]);
		return LogicResult::error('Use a different passkey than the one you started the reset with.');
	}

	try {
		$service = new PasskeyService();
		$verified_cred = $service->verifyStepUp(json_encode($credential), $user);
	} catch (Exception $e) {
		RequestLogger::log('password_reset', 'passkey_reset_2fa_passkey', false, ['user_id' => (int)$uid]);
		return LogicResult::error($e->getMessage());
	}

	// The independence guarantee, asserted locally: the check above compared the
	// CLIENT-sent id (honest in the current webauthn-lib, but that is a library
	// internal); this compares the credential the ceremony actually verified.
	if ($used_cred !== '' && $verified_cred === $used_cred) {
		RequestLogger::log('password_reset', 'passkey_reset_2fa_passkey', false, ['user_id' => (int)$uid]);
		return LogicResult::error('Use a different passkey than the one you started the reset with.');
	}

	unset($_SESSION['pwreset_pk_user_id'], $_SESSION['pwreset_pk_used_cred'], $_SESSION['pwreset_pk_expires']);
	RequestLogger::log('password_reset', 'passkey_reset_2fa_passkey', true, ['user_id' => (int)$uid]);
	return LogicResult::render([
		'reset_authorized' => true,
		'redirect' => PasswordResetAuthorizers::issueResetUrl($user),
	]);
}

function password_reset_2fa_passkey_verify_logic_api() {
	return [
		'requires_session' => false,
		'description' => 'Complete the passkey second factor for a vault-holder password reset',
	];
}
?>
