<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function password_reset_2_logic($get_vars, $post_vars){
	require_once(PathHelper::getIncludePath('includes/Activation.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	if(!$settings->get_setting('register_active')){
			return LogicResult::error('This feature is turned off');
	}

	$act_code = $get_vars['act_code'];
	if(!$act_code){
		$act_code = $post_vars['act_code'];
	}
	$page_vars['act_code'] = $act_code;

	if ($post_vars) {

		$success = Activation::checkTempCode($act_code, 2);

		if(!$success){
			return LogicResult::error('Sorry, this code has expired.  Please <a href="/password-reset-1">click here</a> to send another password reset email.');
		}

		if(!isset($post_vars['usr_password']) || !isset($post_vars['usr_password_again'])){
			return LogicResult::error('The following required fields were not set: passwords');
		}

		if ($post_vars['usr_password'] != $post_vars['usr_password_again']) {
			return LogicResult::error('Your password did not match in both fields.');
		}

		// Attempt to activiate the user if they aren't already activated and get the user
		$user = Activation::ActivateUser($act_code);

		if (!$user) {
			return LogicResult::error('Sorry, this form has expired.  Please <a href="/password-reset-1">click here</a> to send another password reset email.');
		}

		if($user->get('usr_password_recovery_disabled')){
				return LogicResult::error('This feature is turned off for this user.  Please email us to recover your password.');
		}

		$user->set('usr_password', User::GeneratePassword($post_vars['usr_password']));
		$user->save();

		// Now delete the code
		Activation::deleteTempCode($act_code);
		$page_vars['message_type'] = 'success';
		$page_vars['message_title'] = 'Password reset';
		$page_vars['message'] = 'Your password has been reset. <a href="/login">Click here to log in</a>.';
	}

	return LogicResult::render($page_vars);
}
?>
