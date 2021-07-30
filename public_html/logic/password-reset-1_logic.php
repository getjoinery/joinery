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

		$message = '<h2>Reset code sent</h2>
		<p>
		Next step: Check your email for a message from us with a link to enter your new password.
		</p>

		<p>
		If you don not receive an email from us within a few minutes, please check your spam folder.
		</p>';
	}
	else{
		$message = '<h2>Email not found</h2>
		<p>
		We could not find that email address.  Please use your back button to go back and double check the one you entered.
		</p>';
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
