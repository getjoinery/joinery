<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/EmailTemplate.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');

$settings = Globalvars::get_instance();
if(!$settings->get_setting('register_active')){
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();
}

if (isset($_POST['email'])){

	$email = strtolower(trim($_POST['email']));

	$user = User::GetByEmail($email);

	if ($user) {
		if($user->get('usr_password_recovery_disabled')){
				header("HTTP/1.0 404 Not Found");
				echo 'This feature is turned off for this user.  Please email us to recover your password.';
				exit();
		}		

		Activation::email_forgotpw_send($email);
		$message_type = 'success';
		$message_title = 'Reset code sent';
		$message = 'Next step: Check your email for a message from us with a link to enter your new password.  If you don not receive an email from us within a few minutes, please check your spam folder.';
	}
	else{
		$message_type = 'error';
		$message_title = 'Email not found';
		$message = '
		We could not find that email address.  Please use your back button to go back and double check the one you entered.';
	} 
		

}
else{

	$email = '';
	if (isset($_GET['e'])) {
		$e = rawurldecode($_GET['e']);
		if (LibraryFunctions::IsValidEmail($e)) {
			$email = $e;
		}
	}
}

?>
