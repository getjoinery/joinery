<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function login_logic($get_vars, $post_vars){
	
	PathHelper::requireOnce('includes/ErrorHandler.php');
	PathHelper::requireOnce('includes/Globalvars.php');
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/Activation.php');
	PathHelper::requireOnce('data/users_class.php');
	PathHelper::requireOnce('data/login_class.php');
	
	//HANDLE ACTIVATION FIRST IF PRESENT
	if ($get_vars['act_code']) {
		$act_code = $get_vars['act_code'];
		$activated_user = NULL;
		$activated = FALSE;
	
		$session = SessionControl::get_instance();
		$page_vars['session'] = $session;
		$settings = Globalvars::get_instance();
		$page_vars['settings'] = $settings;

		if(!$settings->get_setting('register_active')){
			require_once(LibraryFunctions::display_404_page());	
		}

		if ($session->get_user_id()) {
			$user = new User($session->get_user_id(), TRUE);
		} else {
			$user = NULL;
		}
		
		// If we have an activate code and a logged in user, make sure the code matches the user
		// and then activate them.  If we don't have a logged in user, just activate them!
		if ($activated_user = Activation::ActivateUser($act_code, $user ? $user->key : NULL)) {
			$activated = TRUE;

			// IF LOGGED IN, REDIRECT
			if ($user) {
				if (!$activated_user->get('usr_password')) { 
					LibraryFunctions::Redirect('/password-set');
					exit;
				}
				else{
					LibraryFunctions::Redirect('/page/verify-email-confirm');
					exit;
				}
					
			} else {
				// Does this user need to create a password? 
				if (!$activated_user->get('usr_password')) { 

					// Login the user and let them create a password
					$session->store_session_variables($activated_user);

					if ($session->get_initial_user_id() == $session->get_user_id()) {
						LoginClass::StoreUserLogin($activated_user->key, LoginClass::LOGIN_FORM);
					}

					LibraryFunctions::Redirect('/password-set');
					exit;
				} 
				else {
					LibraryFunctions::Redirect('/page/verify-email-confirm');
					exit;
				}
			}
		}
		else {
			throw new SystemDisplayableError('You cannot activate a user while being logged in as another user.');
			exit();
		}
	}
	
	
	//NOW PROCESS REGULAR LOGIN
	
	$page_vars = array();
	// Check if the page was requested with jQuery, if so, we should process this page differently
	$ajax = !(empty($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] != 'XMLHttpRequest');

	if ($ajax) {
		PathHelper::requireOnce('includes/AjaxErrorHandler.php');
	}


	if($post_vars){
		if ((empty($post_vars['email']) && empty($post_vars['lbx_email'])) ||
			(empty($post_vars['password']) && empty($post_vars['lbx_password']))) {
			if ($ajax) {
				throw new SystemDisplayableError('Please enter both a username and a password to login.');
			} else {
				header("Location: /login?retry=1");
				exit;
			}
		}


		$email = empty($post_vars['email']) ? $post_vars['lbx_email'] : $post_vars['email'];
		$password = empty($post_vars['password']) ? $post_vars['lbx_password'] : $post_vars['password'];
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
		$page_vars['session'] = $session;
		$settings = Globalvars::get_instance();
		$page_vars['settings'] = $settings;

		if($settings->get_setting('activation_required_login')){
			if(!$user->get('usr_is_activated')){
				$message = 'This site requires email activation before you can log in.  An activation email has been sent to '.$user->get('usr_email').'. Please click on the link inside to activate';
				PublicPage::OutputGenericPublicPage('Email verification required', 'Email verification required', $message);
				Activation::email_activate_send($user);
				exit();
			}
		}

		// Save their session
		$session->store_session_variables($user);
		LoginClass::StoreUserLogin($user->key, LoginClass::LOGIN_FORM);

		// Potentially save a cookie if they set "Remember Me"
		if ((isset($post_vars['setcookie']) && $post_vars['setcookie']=="yes") ||
			(isset($post_vars['lbx_setcookie']) && $post_vars['lbx_setcookie'] == "yes")) {
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
	
	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;
	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
		
	$login_messages = array(
		'email_verified'=>'Your email is now verified.  Please log in to improve your profile.',
		'email_not_verified'=>'Your email address was unable to be verified because of an incorrect or expired verification code.  Please log in to resend your verification code',
		'login_to_email_verify'=>'Please log in to verify your email address.',
	);

	if(isset($get_vars['msgtext'])){
		if (array_key_exists($get_vars['msgtext'], $login_messages)) {
			$message = new DisplayMessage(htmlspecialchars($login_messages[$get_vars['msgtext']]), 'Login warning', '/\/login.*/', DisplayMessage::MESSAGE_WARNING, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, "loginbox", TRUE);
			$session->save_message($message);
		}
	}
	if(isset($get_vars['retry'])){
		$message = new DisplayMessage('Your username or password was incorrect.  Please try again below, or sign up if you don\'t have an account.  If you forgot your password, <a href="/password-reset-1">click here</a> and we\'ll send you a new one.', 'Login warning', '/\/login.*/', DisplayMessage::MESSAGE_WARNING, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, "loginbox", TRUE);
		$session->save_message($message);
	}



	$email = '';
	if (isset($get_vars['e'])) {
		$e = rawurldecode($get_vars['e']);
		if (LibraryFunctions::IsValidEmail($e)) {
			$page_vars['email'] = $e;
		}
	}
	
	$page_vars['display_messages'] = $session->get_messages($_SERVER['REQUEST_URI']);
	$session->clear_clearable_messages();
	return $page_vars;
}
?>
