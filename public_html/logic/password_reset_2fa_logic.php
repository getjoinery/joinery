<?php
/**
 * Vault-holder passkey reset — the second-factor step
 * (specs/mailbox_security_levels.md § Password reset, Population 3).
 *
 * Reached only after password_reset_passkey_verify proved the passkey AND found
 * the account is a vault holder with an INDEPENDENT second factor pending. The
 * pending state lives in $_SESSION (no login session exists — a reset never
 * leaves a passkey-only login standing for a vault holder). This handles the
 * TOTP/backup-code submission; the passkey-as-second-factor path runs through
 * the sibling sessionless actions (password_reset_2fa_passkey_options/verify).
 *
 * On success it hands off to the built completion path (/password-reset-2), which
 * sets the new password and fires the credential-event wiring.
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../includes/PathHelper.php');

function password_reset_2fa_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
	require_once(PathHelper::getIncludePath('includes/PasswordResetAuthorizers.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$page_vars = array('session' => $session, 'settings' => Globalvars::get_instance());

	$uid = $_SESSION['pwreset_pk_user_id'] ?? null;
	$expires = $_SESSION['pwreset_pk_expires'] ?? 0;
	if (!$uid || $expires < time()) {
		unset($_SESSION['pwreset_pk_user_id'], $_SESSION['pwreset_pk_used_cred'], $_SESSION['pwreset_pk_expires']);
		return LogicResult::redirect('/password-reset-1');
	}

	$user = new User((int)$uid, TRUE);
	if (!$user || !$user->key) {
		unset($_SESSION['pwreset_pk_user_id'], $_SESSION['pwreset_pk_used_cred'], $_SESSION['pwreset_pk_expires']);
		return LogicResult::redirect('/password-reset-1');
	}

	$used_cred = (string)($_SESSION['pwreset_pk_used_cred'] ?? '');
	$req = PasswordResetAuthorizers::secondFactorRequirement($user, $used_cred);
	$page_vars['has_totp'] = $req['has_totp'];
	$page_vars['has_passkey'] = $req['other_passkeys'] && (bool)$page_vars['settings']->get_setting('passkeys_enabled');
	$page_vars['error'] = null;

	// No usable second factor to offer (e.g. the only independent factor is another
	// passkey but passkeys were disabled after the ticket was minted). Explain
	// rather than render a blank page.
	if (!$page_vars['has_totp'] && !$page_vars['has_passkey']) {
		$page_vars['error'] = 'No second factor is available to confirm right now. Please try another reset option.';
		return LogicResult::render($page_vars);
	}

	// TOTP / backup-code submission (the passkey path is the sessionless actions).
	if (!empty($_POST) && $req['has_totp']) {
		// Throttle against the 'password_reset' bucket — the one every failure in
		// these flows is logged under. This is the brute-force guard on the
		// independent second factor, so it must count real attempts.
		if (!RequestLogger::check_rate_limit('password_reset', 10, 900, false)) {
			return LogicResult::error('Too many attempts. Please wait a few minutes and try again.');
		}
		$submitted = isset($input['totp_code']) ? trim($input['totp_code']) : '';
		$canonical = strtoupper(preg_replace('/[\s-]+/', '', $submitted));
		$valid = false;
		if (preg_match('/^\d{6}$/', $canonical)) {
			$valid = $user->verify_totp($canonical);
		} elseif (preg_match('/^[A-Z0-9]{8}$/', $canonical)) {
			$valid = $user->verify_backup_code($canonical);
		}
		if (!$valid) {
			RequestLogger::log('password_reset', 'passkey_reset_2fa', false, ['user_id' => (int)$uid]);
			$page_vars['error'] = 'That code did not match. Please try again.';
			return LogicResult::render($page_vars);
		}
		// Passkey + independent second factor both proven — authorize the reset.
		unset($_SESSION['pwreset_pk_user_id'], $_SESSION['pwreset_pk_used_cred'], $_SESSION['pwreset_pk_expires']);
		RequestLogger::log('password_reset', 'passkey_reset_2fa', true, ['user_id' => (int)$uid]);
		return LogicResult::redirect(PasswordResetAuthorizers::issueResetUrl($user));
	}

	return LogicResult::render($page_vars);
}

function password_reset_2fa_logic_descriptor() {
	return [
		'requires_session' => false,
		'description' => 'Confirm the second factor for a vault-holder passkey password reset (TOTP path)',
	];
}
?>
