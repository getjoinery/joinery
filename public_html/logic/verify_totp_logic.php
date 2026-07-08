<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function verify_totp_logic(array $input): LogicResult{
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/login_class.php'));

	$session = SessionControl::get_instance();
	$page_vars = array();
	$page_vars['session'] = $session;
	$page_vars['settings'] = Globalvars::get_instance();

	if (empty($_SESSION['totp_pending_user_id'])) {
		return LogicResult::redirect('/login');
	}

	if (empty($_SESSION['totp_pending_expires']) || $_SESSION['totp_pending_expires'] < time()) {
		unset($_SESSION['totp_pending_user_id'], $_SESSION['totp_pending_remember'],
			$_SESSION['totp_pending_return'], $_SESSION['totp_pending_expires']);
		return LogicResult::redirect('/login?msgtext=session_expired');
	}

	// Which factors this pending user can present (specs/mailbox_security_levels.md
	// § 5.4): TOTP and/or a passkey step-up. A passkey-only user (Fortress enrolled
	// via a step-up passkey, no TOTP) sees only the passkey button — the whole point
	// of keying the login divert on user_has_second_factor rather than TOTP alone.
	$pending_user = new User($_SESSION['totp_pending_user_id'], TRUE);
	if (!$pending_user || !$pending_user->key) {
		unset($_SESSION['totp_pending_user_id'], $_SESSION['totp_pending_remember'],
			$_SESSION['totp_pending_return'], $_SESSION['totp_pending_expires']);
		return LogicResult::redirect('/login');
	}
	$page_vars['has_totp'] = $pending_user->has_totp_enabled();
	require_once(PathHelper::getIncludePath('data/passkeys_class.php'));
	$pending_passkeys = new MultiPasskey(array('user_id' => (int)$pending_user->key));
	$pending_passkeys->load();
	$page_vars['has_passkey'] = (count($pending_passkeys) > 0)
		&& (bool)$page_vars['settings']->get_setting('passkeys_enabled');

	if (!empty($_POST) && $page_vars['has_totp']) {
		if (!RequestLogger::check_rate_limit('totp', 5, 300, false)) {
			return LogicResult::error('Too many verification attempts. Please wait 5 minutes and try again.');
		}

		$user = $pending_user;

		$submitted = isset($input['totp_code']) ? trim($input['totp_code']) : '';
		$canonical = strtoupper(preg_replace('/[\s-]+/', '', $submitted));

		$valid = false;
		$used_backup_code = false;

		if (preg_match('/^\d{6}$/', $canonical)) {
			$valid = $user->verify_totp($canonical);
		}
		else if (preg_match('/^[A-Z0-9]{8}$/', $canonical)) {
			$valid = $user->verify_backup_code($canonical);
			$used_backup_code = $valid;
		}
		else {
			RequestLogger::log('totp', 'totp_attempt', false, [
				'user_id' => $user->key,
				'note' => 'Invalid format',
			]);
			return LogicResult::error('Please enter a 6-digit code from your authenticator app, or an 8-character backup code.');
		}

		if (!$valid) {
			RequestLogger::log('totp', 'totp_attempt', false, [
				'user_id' => $user->key,
			]);
			return LogicResult::error('That code did not match. Please try again.');
		}

		// Verified — complete the login
		RequestLogger::log('totp', 'totp_attempt', true, [
			'user_id' => $user->key,
		]);

		if ($used_backup_code) {
			$msgtxt = 'A backup code was used to log in. Consider regenerating your backup codes from the security settings page.';
			$message = new DisplayMessage($msgtxt, 'Backup code used', '/.*/',
				DisplayMessage::MESSAGE_WARNING, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'topbox', TRUE);
			$session->save_message($message);
		}

		require_once(PathHelper::getIncludePath('includes/Login2fa.php'));
		return LogicResult::redirect(Login2fa::completePendingLogin($user));
	}

	$page_vars['display_messages'] = $session->get_messages($_SERVER['REQUEST_URI']);
	$session->clear_clearable_messages();
	return LogicResult::render($page_vars);
}

function verify_totp_logic_api() {
    return [
        'requires_session' => false,
        'description' => 'Verify TOTP code during login',
    ];
}

function verify_totp_logic_descriptor(): array {
	return [
		'description'      => 'Verify a TOTP or backup code to complete two-factor login.',
		'requires_session' => false,
		'mutates'          => true,
		'input'            => [
			'totp_code' => ['type' => 'string', 'required' => true, 'label' => 'Authenticator code or backup code'],
		],
	];
}
?>
