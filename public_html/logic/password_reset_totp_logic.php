<?php
/**
 * TOTP-alone password reset (specs/mailbox_security_levels.md § Password reset,
 * Population 3) — for accounts WITHOUT a vault only.
 *
 * Proving possession of the enrolled authenticator is at least as strong as
 * proving control of an inbox, so a no-vault account may reset with email + a
 * current TOTP/backup code. A VAULT HOLDER is refused here and steered to the
 * passkey reset: a bare TOTP would re-issue the session while the passkey (the
 * vault's own gate) sits unused, exactly the transitive both-doors shape the
 * passkey path closes. Rate-limited; the completion path notifies like every
 * reset.
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../includes/PathHelper.php');

function password_reset_totp_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
	require_once(PathHelper::getIncludePath('includes/PasswordResetAuthorizers.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$page_vars = array('session' => $session, 'settings' => Globalvars::get_instance());
	$page_vars['error'] = null;
	$page_vars['email'] = isset($input['email']) ? trim((string)$input['email']) : '';

	if (!$page_vars['settings']->get_setting('register_active')) {
		return LogicResult::error('This feature is turned off');
	}

	if (empty($_POST)) {
		return LogicResult::render($page_vars);
	}

	if (!RequestLogger::check_rate_limit('password_reset', 5, 900, false)) {
		return LogicResult::error('Too many attempts. Please wait a few minutes and try again.');
	}

	$email = strtolower(trim((string)($input['email'] ?? '')));
	$submitted = isset($input['totp_code']) ? trim((string)$input['totp_code']) : '';
	$canonical = strtoupper(preg_replace('/[\s-]+/', '', $submitted));

	$generic = 'That email and code combination was not recognized.';

	$user = $email !== '' ? User::GetByEmail($email) : null;

	// A vault holder is not eligible for TOTP-alone reset — steer to the passkey
	// path. Checked BEFORE verifying the code so a denied reset never consumes a
	// finite backup code (verify_backup_code burns the code on match). This does
	// not leak anything new: passkey_login_options already reveals vault status by
	// email. The generic-error branch below still masks whether a non-vault email
	// exists or has 2FA.
	if ($user && $user->key && $user->has_active_vault()) {
		RequestLogger::log('password_reset', 'totp_reset_refused_vault', false, ['user_id' => (int)$user->key]);
		$page_vars['error'] = 'This account protects sealed mail, so it cannot be reset with an authenticator code alone. '
			. 'Use <a href="/password-reset-1">Reset with your passkey</a> instead.';
		return LogicResult::render($page_vars);
	}

	// Validate possession of the enrolled authenticator.
	$valid = false;
	if ($user && $user->key && $user->has_totp_enabled() && !$user->get('usr_password_recovery_disabled')) {
		if (preg_match('/^\d{6}$/', $canonical)) {
			$valid = $user->verify_totp($canonical);
		} elseif (preg_match('/^[A-Z0-9]{8}$/', $canonical)) {
			$valid = $user->verify_backup_code($canonical);
		}
	}

	if (!$valid) {
		RequestLogger::log('password_reset', 'totp_reset', false, ['note' => 'TOTP-alone reset for: ' . $email]);
		$page_vars['error'] = $generic;
		return LogicResult::render($page_vars);
	}

	RequestLogger::log('password_reset', 'totp_reset', true, ['user_id' => (int)$user->key]);
	return LogicResult::redirect(PasswordResetAuthorizers::issueResetUrl($user));
}

function password_reset_totp_logic_descriptor() {
	return [
		'requires_session' => false,
		'description' => 'Reset a password using email plus an authenticator code (accounts without a vault only)',
	];
}
?>
