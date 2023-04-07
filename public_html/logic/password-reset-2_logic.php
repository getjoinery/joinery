<?php

function password_reset_2_logic($get_vars, $post_vars){
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

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
			
			if(!isset($post_vars['usr_password']) || !isset($post_vars['usr_password_again'])){
				throw new SystemDisplayableError(
					'The following required fields were not set: passwords');
			}
		
		

		if ($post_vars['usr_password'] != $post_vars['usr_password_again']) {
			throw new SystemDisplayableError(
				'Your password did not match in both fields.');
		}

		// Attempt to activiate the user if they aren't already activated and get the user
		$user = Activation::ActivateUser($act_code);

		if (!$user) {
			throw new SystemDisplayableError(
				'Sorry, this form has expired.  Please <a href="/password-reset-1">click here</a> to send another password reset email.');
		}
		
		if($user->get('usr_password_recovery_disabled')){
				echo 'This feature is turned off for this user.  Please email us to recover your password.';
				exit();
		}

		$user->set('usr_password', User::GeneratePassword($post_vars['usr_password']));
		$user->save();

		// Now delete the code
		Activation::deleteTempCode($act_code);
		$page_vars['message_type'] = 'success';
		$page_vars['message_title'] = 'Password reset';
		$page_vars['message'] = 'Your password has been reset. <a href="/login">Click here to log in</a>.';
	} 
	else{
		$act_code = $get_vars['act_code'];
		if(!$act_code){
			$act_code = $post_vars['act_code'];
		}
		$success = Activation::checkTempCode($act_code, 2);

		if(!$success){
			throw new SystemDisplayableError(
				'Sorry, this code has expired.  Please <a href="/password-reset-1">click here</a> to send another password reset email.');
		}
	}
	return $page_vars;
}
?>
