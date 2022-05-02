<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
$settings = Globalvars::get_instance();


require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/data/login_class.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/data/activation_codes_class.php');

if (empty($_POST['urbit_token']) || empty($_POST['urbit_ship'])) {
	throw new SystemDisplayableError('Please enter both a token and a ship name to login.');
}


$urbit_token = trim($_POST['urbit_token']);
$urbit_ship = trim($_POST['urbit_ship']);

if (!Activation::checkTempCode($urbit_token, Activation::NONE)) {

	throw new SystemDisplayableError('Your token was incorrect.');
	exit;
}

$session = SessionControl::get_instance();
if($session->get_user_id()){
	//USER IS LOGGED IN AND THEY ARE ADDING AN URBIT SHIP
	$user = new User($session->get_user_id(), TRUE);
	$user->set('usr_urbit_ship_name', $urbit_ship);
	$user->save();
	
	header("Location: /profile/account_edit");
}
else{
	//USER IS NOT LOGGED IN TODO: ALLOW NEW ACCOUNTS WITH ONLY URBIT SHIP
	/*
	if($settings->get_setting('activation_required_login')){
		if(!$user->get('usr_is_activated')){
			$message = 'This site requires email activation before you can log in.  An activation email has been sent to '.$user->get('usr_email').'. Please click on the link inside to activate';
			PublicPageTW::OutputGenericPublicPage('Email verification required', 'Email verification required', $message);
			Activation::email_activate_send($user);
			exit();
		}
	}
	*/
	
	if(!$user = User::GetByColumn('usr_urbit_ship_name', $urbit_ship)){
		//USER DOESN'T EXIST
		throw new SystemDisplayableError('There is no user with that urbit ship name.');
	}
	else{

		// Save their session
		$session->store_session_variables($user);
		LoginClass::StoreUserLogin($user->key, LoginClass::LOGIN_FORM);

		// Potentially save a cookie if they set "Remember Me"
		if ((isset($_POST['setcookie']) && $_POST['setcookie']=="yes")) {
			$session->save_user_to_cookie();
		}

		$returnurl = $session->get_return();
		$_SESSION['returnurl'] = NULL;

		if ($returnurl) {
			header("Location: $returnurl");
		} else {
			header("Location: /profile");
		}
	}
}


?>
