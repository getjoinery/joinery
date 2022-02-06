<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

$settings = Globalvars::get_instance();
if(!$settings->get_setting('register_active')){
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();
}

$act_code = LibraryFunctions::fetch_variable('act_code', '', 1, '');
$success = Activation::checkTempCode($act_code, 2);

if(!$success){
	throw new SystemDisplayableError(
		'Sorry, this code has expired.  Please <a href="/password-reset-1">click here</a> to send another password reset email.');
}

if ($_POST) {
		
		if(!isset($_POST['usr_password']) || !isset($_POST['usr_password_again'])){
			throw new SystemDisplayableError(
				'The following required fields were not set: passwords');
		}
	
	

	if ($_POST['usr_password'] != $_POST['usr_password_again']) {
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
			header("HTTP/1.0 404 Not Found");
			echo 'This feature is turned off for this user.  Please email us to recover your password.';
			exit();
	}

	$user->set('usr_password', User::GeneratePassword($_POST['usr_password']));
	$user->save();

	// Now delete the code
	Activation::deleteTempCode($act_code);
	$message_type = 'success';
	$message_title = 'Password reset';
	$message = 'Your password has been reset. <a href="/login">Click here to log in</a>.';
} 
?>
