<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function verify_totp_logic($get_vars, $post_vars){
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

	if ($post_vars) {
		if (!RequestLogger::check_rate_limit('totp', 5, 300, false)) {
			return LogicResult::error('Too many verification attempts. Please wait 5 minutes and try again.');
		}

		$user = new User($_SESSION['totp_pending_user_id'], TRUE);
		if (!$user || !$user->key) {
			unset($_SESSION['totp_pending_user_id'], $_SESSION['totp_pending_remember'],
				$_SESSION['totp_pending_return'], $_SESSION['totp_pending_expires']);
			return LogicResult::redirect('/login');
		}

		$submitted = isset($post_vars['totp_code']) ? trim($post_vars['totp_code']) : '';
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

		$remember        = !empty($_SESSION['totp_pending_remember']);
		$returnurl       = $_SESSION['totp_pending_return'] ?? null;

		unset($_SESSION['totp_pending_user_id'], $_SESSION['totp_pending_remember'],
			$_SESSION['totp_pending_return'], $_SESSION['totp_pending_expires']);

		$session->store_session_variables($user);
		LoginClass::StoreUserLogin($user->key, LoginClass::LOGIN_FORM);

		if ($remember) {
			$session->save_user_to_cookie();
		}

		// Trust this device if the setting is on
		$session->set_trusted_device_cookie($user);

		if ($used_backup_code) {
			$msgtxt = 'A backup code was used to log in. Consider regenerating your backup codes from the security settings page.';
			$message = new DisplayMessage($msgtxt, 'Backup code used', '/.*/',
				DisplayMessage::MESSAGE_WARNING, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'topbox', TRUE);
			$session->save_message($message);
		}

		$alternate_homepage = $page_vars['settings']->get_setting('alternate_loggedin_homepage');
		return LogicResult::redirect($returnurl ?: ($alternate_homepage ?: '/profile'));
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
?>
