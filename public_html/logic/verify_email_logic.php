<?php
/**
 * Activation confirm step. The activation link (activation_content, which
 * points at /login?act_code=; /login, /profile and the ScrollDaddy profile send
 * any act_code here) opens this page, which asks for one click: "Verify my
 * email". The click (a POST) verifies the address and continues as the link
 * used to: a member with no password signs in and sets one, a signed-in member
 * returns to where the link pointed, everyone else is told it worked.
 *
 * Verifying an account works whether or not registration is open: an admin
 * adds members on a site that takes no sign-ups, and they verify here too.
 *
 * Opening the link verifies nothing and does not spend the code, so a mail
 * scanner fetching it cannot verify the address, or confirm what verifying it
 * confirms (account.email_verified, method activation), on the person's behalf.
 *
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

	// A signed-in member may only verify their own account with it.
	$activated_user = Activation::ActivateUser($act_code, $signed_in_id ? $signed_in_id : NULL, 'activation');
	if (!$activated_user) {
		return LogicResult::redirect('/login?msgtext=activate_other_user');
	}

	if ($signed_in_id) {
		if (!$activated_user->get('usr_password')) {
			return LogicResult::redirect('/password-set');
		}
		return LogicResult::redirect($then === 'profile' ? '/profile' : '/page/verify-email-confirm');
	}

	// Not signed in and no password yet: sign them in so they can set one.
	if (!$activated_user->get('usr_password')) {
		$session->store_session_variables($activated_user);
		if ($session->get_initial_user_id() == $session->get_user_id()) {
			Login::record($activated_user->key, Login::LOGIN_FORM);
		}
		return LogicResult::redirect('/password-set');
	}

	return LogicResult::redirect('/page/verify-email-confirm');
}
?>
