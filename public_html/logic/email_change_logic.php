<?php
/**
 * Email-change confirm step. The link mailed to the new address
 * (Activation::email_change_send) opens this page, which names the address and
 * asks for one click; the click (a POST) makes the change. Opening the link
 * changes nothing, so a mail scanner fetching it cannot spend the code. No
 * sign-in is required: the code proves control of the new inbox, and the
 * change was asked for from a step-up-gated session (account_edit_logic).
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../includes/PathHelper.php');

function email_change_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/Activation.php'));

	$session = SessionControl::get_instance();
	$page_vars = array('session' => $session, 'settings' => Globalvars::get_instance());

	$act_code = isset($input['act_code']) ? trim((string)$input['act_code']) : '';
	$record = $act_code !== '' ? Activation::getTempCodeInfo($act_code, Activation::EMAIL_CHANGE) : null;
	if (!$record) {
		$page_vars['state'] = 'invalid';
		$page_vars['message'] = 'This link has expired or has already been used. Ask for the change again from your account page.';
		return LogicResult::render($page_vars);
	}

	$page_vars['act_code'] = $act_code;
	$page_vars['new_email'] = strtolower(trim((string)$record->act_usr_email));

	if (!LibraryFunctions::isFormSubmission()) {
		$page_vars['state'] = 'confirm';
		return LogicResult::render($page_vars);
	}

	// No one need be signed in: the code mailed to the new address is the authority.
	try {
		$user = SystemBase::rule_checked_write(function () use ($act_code) {
			return Activation::ChangeEmailUser($act_code);
		});
	} catch (DisplayableUserException $e) {
		$page_vars['state'] = 'invalid';
		$page_vars['message'] = $e->getMessage();
		return LogicResult::render($page_vars);
	}

	if (!$user) {
		$page_vars['state'] = 'invalid';
		$page_vars['message'] = 'This link has expired or has already been used. Ask for the change again from your account page.';
		return LogicResult::render($page_vars);
	}

	$page_vars['state'] = 'done';
	$page_vars['message'] = 'Your sign-in email is now ' . $page_vars['new_email'] . '.';
	return LogicResult::render($page_vars);
}
?>
