<?php

// Check if the page was requested with jQuery, if so, we should process this page differently
$ajax = !(empty($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] != 'XMLHttpRequest');

if ($ajax) {
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AjaxErrorHandler.php');
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/data/login_class.php');
if($_POST){
	if ((empty($_POST['email']) && empty($_POST['lbx_email'])) ||
		(empty($_POST['password']) && empty($_POST['lbx_password']))) {
		if ($ajax) {
			throw new SystemDisplayableError('Please enter both a username and a password to login.');
		} else {
			header("Location: /login?retry=1");
			exit;
		}
	}


	$email = empty($_POST['email']) ? $_POST['lbx_email'] : $_POST['email'];
	$password = empty($_POST['password']) ? $_POST['lbx_password'] : $_POST['password'];
	$user = User::GetByEmail($email);

	if (!$user || !$user->check_password($password)) {
		// Email or password was incorrect
		if ($ajax) {
			throw new SystemDisplayableError('Your username or password was incorrect. Please try again, or sign up if you don\'t have an account.');
		} else {
			header("Location: /login?retry=1&e=" . rawurlencode($email));
			exit;
		}
	}


	// Here we know the user/password was good
	$session = SessionControl::get_instance();
	$settings = Globalvars::get_instance();

	// Save their session
	$session->store_session_variables($user);
	LoginClass::StoreUserLogin($user->key, LoginClass::LOGIN_FORM);

	// Potentially save a cookie if they set "Remember Me"
	if ((isset($_POST['setcookie']) && $_POST['setcookie']=="yes") ||
		(isset($_POST['lbx_setcookie']) && $_POST['lbx_setcookie'] == "yes")) {
		$session->save_user_to_cookie();
	}

	if (isset($_SESSION['forcelogin'])) {
		$_SESSION['forcelogin'] = FALSE;
	}

	if ($ajax) {
		echo json_encode(array('success' => 1));
	} else {

		$returnurl = $session->get_return();
		$_SESSION['returnurl'] = NULL;

		if ($returnurl) {
			header("Location: $returnurl");
		} else {
			header("Location: /profile");
		}
	}
	exit();
}

$LOGIN_MESSAGES = array(
	'email_verified'=>'Your email is now verified.  Please log in to improve your profile.',
	'email_not_verified'=>'Your email address was unable to be verified because of an incorrect or expired verification code.  Please log in to resend your verification code',
	'login_to_email_verify'=>'Please log in to verify your email address.',
);

$email = '';
if (isset($_GET['e'])) {
	$e = rawurldecode($_GET['e']);
	if (LibraryFunctions::IsValidEmail($e)) {
		$email = $e;
	}
}
?>
