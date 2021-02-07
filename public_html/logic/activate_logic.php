<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');

$settings = Globalvars::get_instance();
if(!$settings->get_setting('register_active')){
	require_once(LibraryFunctions::display_404_page());	
}

$session = SessionControl::get_instance();

if ($session->get_user_id()) {
	$user = new User($session->get_user_id(), TRUE);
} else {
	$user = NULL;
}

$act_code = LibraryFunctions::fetch_variable('act_code', NULL);
$activated_user = NULL;
$activated = FALSE;


if ($act_code) {
	// If we have an activate code and a logged in user, make sure the code matches the user
	// and then activate them.  If we don't have a logged in user, just activate them!
	if ($activated_user = Activation::ActivateUser($act_code, $user ? $user->key : NULL)) {
		$activated = TRUE;

		// IF LOGGED IN, REDIRECT
		if ($user) {
			if (!$activated_user->get('usr_password')) { 
				LibraryFunctions::Redirect('/profile');
			}
			
			LibraryFunctions::Redirect('/profile');
				
		} else {
			// Does this user need to create a password? 
			if (!$activated_user->get('usr_password')) { 

				// Login the user and let them create a password
				$session->store_session_variables($activated_user);

				if ($session->get_initial_user_id() == $session->get_user_id()) {
					LoginClass::StoreUserLogin($activated_user->key, LoginClass::LOGIN_FORM);
				}

				LibraryFunctions::Redirect('/password-set');
			} else {
				LibraryFunctions::Redirect('/page/verify-email-confirm');
			}
		}
	}
	else {
		throw new SystemDisplayableError('You cannot activate a user while being logged in as another user.');
		exit();
	}
}
else{
	throw new SystemDisplayableError('There was no activation code entered.');
	exit();
}

?>
