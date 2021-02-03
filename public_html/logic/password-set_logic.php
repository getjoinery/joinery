<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

$settings = Globalvars::get_instance();
if(!$settings->get_setting('register_active')){
	include("404.php");
	exit();
}
/*
$token = LibraryFunctions::fetch_variable('token', '', 1, 'You did not pass the needed information');

if($token != '3la8ghs8'){
	throw new SystemDisplayablePermanentError(
		'Sorry, this form has expired.  Please <a href="/password-reset-1">click here</a> to send a password reset email.');
}
*/
$session = SessionControl::get_instance();

if ($_POST) {
	
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
	
	if(!isset($_POST['usr_password']) || !isset($_POST['usr_password_again'])){
			throw new SystemDisplayableError(
				'The following required fields were not set: passwords');
	}
	

	if ($_POST['usr_password'] != $_POST['usr_password_again']) {
		throw new SystemDisplayableError(
			'Your password did not match in both fields.');
	}


	$user->set('usr_password', User::GeneratePassword($_POST['usr_password']));
	$user->save();

	$message = '<p>Your password has been set. <a href="/login">Click here to log in</a>.</p>;
} 
?>
