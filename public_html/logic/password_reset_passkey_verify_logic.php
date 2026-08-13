<?php
/**
 * Sessionless passkey password-reset — step 2: verify
 * (specs/mailbox_security_levels.md § Password reset, Population 3).
 *
 * The security-critical authorizer. It verifies the passkey assertion (reusing
 * PasskeyService::verifyAuthentication), then:
 *
 *   - For an account with NO active vault (or a vault holder with no INDEPENDENT
 *     second factor): the passkey alone authorizes the reset — a no-vault passkey
 *     is the unphishable front door, and a vault holder who never enrolled a
 *     factor separate from that passkey accepts passkey-alone as their floor.
 *   - For a vault holder WITH an independent second factor: the passkey is not
 *     enough. A stolen authorizer could otherwise reset + log in + unlock with a
 *     single key (the passkey transitively opening both doors, which § The role
 *     split forbids). So the response demands the second factor first; the
 *     browser runs it against /password-reset-2fa, then completes.
 *
 * verifyAuthentication() establishes a session as a side effect (its sign-in
 * contract). A reset must NOT leave a passkey-only login standing — least of all
 * for a vault holder, for whom sole passkey sign-in is disabled account-wide — so
 * that session is discarded here and replaced with a scoped, expiring reset
 * ticket in $_SESSION. Authorization always completes on /password-reset-2.
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../includes/PathHelper.php');

function password_reset_passkey_verify_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
	require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));
	require_once(PathHelper::getIncludePath('includes/PasswordResetAuthorizers.php'));

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

	$credential = $input['credential'] ?? null;
	if (!is_array($credential)) {
		return LogicResult::error('Missing passkey credential response.');
	}

	try {
		$service = new PasskeyService();
		$user = $service->verifyAuthentication(json_encode($credential));
	} catch (Exception $e) {
		RequestLogger::log('password_reset', 'passkey_verify', false);
		return LogicResult::error($e->getMessage());
	}

	$uid = (int)$user->key;
	if ($user->get('usr_password_recovery_disabled')) {
		$_SESSION = array();
		return LogicResult::error('Password recovery is turned off for this account. Please contact us.');
	}

	$is_vault  = $user->has_active_vault();
	$used_cred = PasswordResetAuthorizers::usedCredentialId($credential);
	$second    = PasswordResetAuthorizers::secondFactorRequirement($user, $used_cred);

	// Discard the passkey-established login. A reset completes on /password-reset-2;
	// for a vault holder a standing passkey-only session would itself be the hole.
	$_SESSION = array();
	session_regenerate_id(true);

	if ($is_vault && $second['needs']) {
		// Stash a scoped, expiring ticket: passkey proven, second factor pending.
		$_SESSION['pwreset_pk_user_id']  = $uid;
		$_SESSION['pwreset_pk_used_cred'] = $used_cred;
		$_SESSION['pwreset_pk_expires']  = time() + PasswordResetAuthorizers::PENDING_TTL;
		RequestLogger::log('password_reset', 'passkey_verify_pending_2fa', true, ['user_id' => $uid]);
		return LogicResult::render([
			'second_factor_required' => true,
			'redirect' => '/password-reset-2fa',
		]);
	}

	// Passkey alone authorizes: no vault, or vault holder with no independent factor.
	RequestLogger::log('password_reset', 'passkey_verify', true, ['user_id' => $uid]);
	return LogicResult::render([
		'reset_authorized' => true,
		'redirect' => PasswordResetAuthorizers::issueResetUrl($user),
	]);
}

function password_reset_passkey_verify_logic_descriptor() {
	return [
		'requires_session' => false,
		'description' => 'Complete a passkey password reset; vault holders are told when a second factor is still required',
	];
}
?>
