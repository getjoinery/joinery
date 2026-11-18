<?php
/**
 * Activation confirm step. The activation link (activation_content, which
 * points at /login?act_code=; /login, /profile and the ScrollDaddy profile send
 * any act_code here) opens this page, which asks for one click: "Verify my
 * email". The click (a POST) verifies the address and continues as the link
 * used to: a member with no password signs in and sets one, and a signed-in
 * member returns to where the link pointed. Anyone else is signed in by it
 * when the link was mailed within Activation::SIGN_IN_MINUTES (the code is
 * spent, and a second factor the account holds is still asked for), and sent
 * where they belong (Activation::landingFor(): a plugin's page for its own
 * members, else where a sign-in lands). An older link verifies and asks for a
 * sign-in.
 *
 * Verifying an account works whether or not registration is open: an admin
 * adds members on a site that takes no sign-ups, and they verify here too.
 *
 * Opening the link verifies nothing and does not spend the code, so a mail
 * scanner fetching it cannot verify the address, or confirm what verifying it
 * confirms (account.email_verified, method activation), on the person's behalf.
 *
 * @version 1.1 - a link mailed a short while ago signs the member in and lands them where they belong
 * @version 1.0
 */
function verify_email_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$settings = Globalvars::get_instance();
	$page_vars = array('session' => $session, 'settings' => $settings);

	$act_code = isset($input['act_code']) ? trim((string)$input['act_code']) : '';
	$then = (($input['then'] ?? '') === 'profile') ? 'profile' : '';
	$page_vars['act_code'] = $act_code;
	$page_vars['then'] = $then;

	if ($act_code === '' || !Activation::checkTempCode($act_code, Activation::EMAIL_VERIFY)) {
		$page_vars['state'] = 'invalid';
		$page_vars['message'] = 'This link has expired or has already been used. Sign in to send yourself a new one.';
		return LogicResult::render($page_vars);
	}

	if (!LibraryFunctions::isFormSubmission()) {
		$page_vars['state'] = 'confirm';
		return LogicResult::render($page_vars);
	}

	$signed_in_id = $session->get_user_id();
	// Read before anything spends the code.
	$signs_in = Activation::codeSignsIn($act_code);

	// A signed-in member may only verify their own account with it.
	$activated_user = Activation::ActivateUser($act_code, $signed_in_id ? $signed_in_id : NULL, 'activation');
	if (!$activated_user) {
		return LogicResult::redirect('/login?msgtext=activate_other_user');
	}

	// Where this member belongs: a plugin that knows them says (a hosting
	// buyer's sites page); otherwise where a sign-in lands.
	$landing = Activation::landingFor($activated_user);

	if ($signed_in_id) {
		if (!$activated_user->get('usr_password')) {
			return LogicResult::redirect('/password-set');
		}
		return LogicResult::redirect($then === 'profile' ? '/profile' : ($landing ?: '/page/verify-email-confirm'));
	}

	// Not signed in and no password yet: sign them in so they can set one.
	if (!$activated_user->get('usr_password')) {
		$session->store_session_variables($activated_user);
		if ($session->get_initial_user_id() == $session->get_user_id()) {
			Login::record($activated_user->key, Login::LOGIN_FORM);
		}
		return LogicResult::redirect('/password-set');
	}

	// Not signed in, on a link mailed a short while ago: the button signs them
	// in too. The code is spent by it, so the email is a sign-in once, and a
	// second factor the account holds is still asked for.
	if ($signs_in && $activated_user->actions_allowed() && !$activated_user->is_machine_account()
			&& $activated_user->is_ip_allowed((string)SessionControl::get_client_ip(true))) {
		Activation::deleteTempCode($act_code);
		$dest = $then === 'profile' ? '/profile'
			: ($landing ?: ($settings->get_setting('alternate_loggedin_homepage') ?: '/profile'));

		if ($session->user_has_second_factor($activated_user) && $activated_user->two_factor_cadence() === 'every_login'
				&& !$session->has_valid_trusted_device_cookie($activated_user)) {
			session_regenerate_id(true);
			$_SESSION['totp_pending_user_id']  = $activated_user->key;
			$_SESSION['totp_pending_remember'] = false;
			$_SESSION['totp_pending_return']   = $dest;
			$_SESSION['totp_pending_expires']  = time() + 600;
			unset($_SESSION['totp_pending_from_cookie']);
			return LogicResult::redirect('/verify-totp');
		}

		RequestLogger::log('login', 'login_attempt', true, array('user_id' => $activated_user->key, 'note' => 'activation link'));
		$session->store_session_variables($activated_user);
		Login::record($activated_user->key, Login::LOGIN_FORM);
		return LogicResult::redirect($dest);
	}

	// An older link verifies and asks for a sign-in, which returns them to
	// where they belong.
	if ($landing !== '') {
		return LogicResult::redirect('/login?msgtext=email_verified&return=' . rawurlencode($landing));
	}
	return LogicResult::redirect('/page/verify-email-confirm');
}
?>
