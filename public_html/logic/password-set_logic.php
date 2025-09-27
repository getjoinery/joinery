<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function password_set_logic($get_vars, $post_vars){
	PathHelper::requireOnce('includes/Activation.php');
PathHelper::requireOnce('includes/LogicResult.php');

	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	if(!$settings->get_setting('register_active')){
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();
	}

	if ($post_vars) {

		if(!$session->get_user_id()){
			throw new SystemDisplayableError('You must be logged in to set a password.');
			exit();
		}
		else{
			$user = new User($session->get_user_id(), TRUE);
		}

		if(!$user || $user->get('usr_password') !== NULL){
			throw new SystemDisplayablePermanentError(
				'Sorry, your password is already set.  If you need to reset it, <a href="/password-reset-1">click here</a> to send a password reset email.');
		}

		if(!isset($post_vars['usr_password']) || !isset($post_vars['usr_password_again'])){
				throw new SystemDisplayableError(
					'The following required fields were not set: passwords');
		}

		if ($post_vars['usr_password'] != $post_vars['usr_password_again']) {
			throw new SystemDisplayableError(
				'Your password did not match in both fields.');
		}

		$user->set('usr_password', User::GeneratePassword($post_vars['usr_password']));
		$user->save();

		$page_vars['message_type'] = 'success';
		$page_vars['message_title'] = 'Reset code sent';
		$page_vars['message'] = 'Your password has been set. <a href="/login">Click here to log in</a>.';
	}
	return LogicResult::render($page_vars);
}
?>
