<?php
/**
 * Second-factor step-up ceremony (specs/mailbox_security_levels.md § 5.5).
 *
 * A sensitive administration action redirects here when the session has no
 * recent second-factor confirmation. The user re-confirms with a passkey (the
 * built passkey_stepup ceremony, which stamps the shared marker itself) or a
 * TOTP/backup code (verified here, then stamped via
 * SessionControl::stamp_second_factor()), and returns to the origin action.
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../includes/PathHelper.php');

function verify_stepup_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::redirect('/login');
	}
	$settings = Globalvars::get_instance();
	$user = new User($session->get_user_id(), TRUE);

	// Same-site relative return only (never an open redirect).
	$return = isset($input['return']) ? (string)$input['return'] : '/profile';
	if ($return === '' || $return[0] !== '/' || (isset($return[1]) && $return[1] === '/')) {
		$return = '/profile';
	}

	// Nothing to confirm — no factor enrolled, or already confirmed recently.
	if (!$session->user_has_second_factor($user) || $session->has_recent_second_factor()) {
		return LogicResult::redirect($return);
	}

	$has_totp = $user->has_totp_enabled();
	require_once(PathHelper::getIncludePath('data/passkeys_class.php'));
	$passkeys = new MultiPasskey(array('user_id' => (int)$user->key));
	$passkeys->load();
	$has_passkey = (count($passkeys) > 0) && (bool)$settings->get_setting('passkeys_enabled');

	// TOTP step-up submission.
	if (!empty($_POST) && $has_totp) {
		if (!RequestLogger::check_rate_limit('stepup', 5, 300, false)) {
			return LogicResult::error('Too many attempts. Please wait 5 minutes and try again.');
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
			RequestLogger::log('stepup', 'stepup_attempt', false, array('user_id' => $user->key));
			return LogicResult::render(array(
				'session' => $session, 'settings' => $settings,
				'return' => $return, 'has_totp' => $has_totp, 'has_passkey' => $has_passkey,
				'error' => 'That code did not match. Please try again.',
			));
		}
		RequestLogger::log('stepup', 'stepup_attempt', true, array('user_id' => $user->key));
		$session->stamp_second_factor('totp_stepup');
		return LogicResult::redirect($return);
	}

	return LogicResult::render(array(
		'session' => $session, 'settings' => $settings,
		'return' => $return, 'has_totp' => $has_totp, 'has_passkey' => $has_passkey,
		'error' => null,
	));
}
?>
