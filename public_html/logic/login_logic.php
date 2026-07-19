<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function login_logic(array $input): LogicResult{

	require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
	require_once(PathHelper::getIncludePath('includes/Activation.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/login_class.php'));

	//HANDLE ACTIVATION FIRST IF PRESENT
	if (!empty($input['act_code'])) {
		$act_code = $input['act_code'];
		$activated_user = NULL;
		$activated = FALSE;

		$session = SessionControl::get_instance();
		$page_vars['session'] = $session;
		$settings = Globalvars::get_instance();
		$page_vars['settings'] = $settings;

		if(!$settings->get_setting('register_active')){
			return LogicResult::error('This feature is turned off');
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
					return LogicResult::redirect('/password-set');
				}
				else{
					return LogicResult::redirect('/page/verify-email-confirm');
				}

			} else {
				// Does this user need to create a password?
				if (!$activated_user->get('usr_password')) {

					// Login the user and let them create a password
					$session->store_session_variables($activated_user);

					if ($session->get_initial_user_id() == $session->get_user_id()) {
						LoginClass::StoreUserLogin($activated_user->key, LoginClass::LOGIN_FORM);
					}

					return LogicResult::redirect('/password-set');
				}
				else {
					return LogicResult::redirect('/page/verify-email-confirm');
				}
			}
		}
		else {
			return LogicResult::error('You cannot activate a user while being logged in as another user.');
		}
	}

	//NOW PROCESS REGULAR LOGIN

	$page_vars = array();
	// Check if the page was requested with jQuery, if so, we should process this page differently
	$ajax = !(empty($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] != 'XMLHttpRequest');

	// AJAX requests will be handled by the new ErrorManager system

	if (!empty($_POST)) {
		// Rate limiting: block after too many failed login attempts from this IP
		require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
		if (!RequestLogger::check_rate_limit('login', 10, 900, false)) {
			if ($ajax) {
				require_once(__DIR__ . '/../includes/Exceptions/AuthenticationException.php');
				throw new AuthenticationException('Too many failed login attempts. Please try again in 15 minutes.');
			} else {
				return LogicResult::error('Too many failed login attempts. Please try again in 15 minutes.');
			}
		}

		if ((empty($input['email']) && empty($input['lbx_email'])) ||
			(empty($input['password']) && empty($input['lbx_password']))) {
			if ($ajax) {
				require_once(__DIR__ . '/../includes/Exceptions/ValidationException.php');
				throw new ValidationException('Please enter both a username and a password to login.', [
					'email' => 'Email is required',
					'password' => 'Password is required'
				]);
			} else {
				return LogicResult::redirect('/login?retry=1');
			}
		}

		$email = empty($input['email']) ? $input['lbx_email'] : $input['email'];
		$password = empty($input['password']) ? $input['lbx_password'] : $input['password'];
		$user = User::GetByEmail($email);

		if (!$user || !$user->check_password($password)) {
			// Email or password was incorrect
			RequestLogger::log('login', 'login_attempt', false, [
				'note' => 'Failed login for: ' . $email,
			]);
			if ($ajax) {
				require_once(__DIR__ . '/../includes/Exceptions/AuthenticationException.php');
				throw new AuthenticationException('Your username or password was incorrect. Please try again, or sign up if you don\'t have an account.');
			} else {
				return LogicResult::redirect("/login?retry=1&e=" . rawurlencode($email));
			}
		}

		// Check IP restriction (if configured for this user)
		$client_ip = $_SERVER['REMOTE_ADDR'];
		if (!$user->is_ip_allowed($client_ip)) {
			if ($ajax) {
				require_once(__DIR__ . '/../includes/Exceptions/AuthenticationException.php');
				throw new AuthenticationException('Login from this IP address (' . $client_ip . ') is not permitted for this account.');
			} else {
				return LogicResult::redirect("/login?retry=1&e=" . rawurlencode($email) . "&ip_blocked=1&ip=" . rawurlencode($client_ip));
			}
		}

		// Here we know the user/password was good and IP is allowed
		$session = SessionControl::get_instance();
		$page_vars['session'] = $session;
		$settings = Globalvars::get_instance();
		$page_vars['settings'] = $settings;

		if($settings->get_setting('activation_required_login')){
			if(!$user->get('usr_is_activated')){
				Activation::email_activate_send($user);
				return LogicResult::error('This site requires email activation before you can log in.  An activation email has been sent to '.$user->get('usr_email').'. Please click on the link inside to activate');
			}
		}

		// 2FA check: if the user holds ANY second factor (TOTP or a step-up-capable
		// passkey) and has no valid trusted-device cookie, stash a pending state and
		// redirect to /verify-totp instead of completing login. Keying on
		// user_has_second_factor (not has_totp_enabled) closes the quirk where a
		// passkey-only Fortress user was never asked a second factor at sign-in
		// (specs/mailbox_security_levels.md § 5.4). The 2FA cadence (§ 5.2) decides
		// whether the factor is asked at sign-in: 'every_login' asks it here;
		// 'sensitive_only' signs in password-only and defers the factor to sensitive
		// actions (step-up). That is sound because every escalation from a bare
		// session — password/email change, 2FA changes, recovery-code use,
		// protected-mail routing — is independently gated; a phished password on
		// 'sensitive_only' sees the mailbox's shape and opens nothing.
		if ($session->user_has_second_factor($user) && $user->two_factor_cadence() === 'every_login'
				&& !$session->has_valid_trusted_device_cookie($user)) {
			session_regenerate_id(true);
			$_SESSION['totp_pending_user_id']  = $user->key;
			$_SESSION['totp_pending_remember'] = !empty($input['setcookie']) || !empty($input['lbx_setcookie']);
			$_SESSION['totp_pending_return']   = $session->get_return();
			$_SESSION['totp_pending_expires']  = time() + 600;

			if ($ajax) {
				return LogicResult::redirect('/verify-totp');
			}
			return LogicResult::redirect('/verify-totp');
		}

		// Log successful login
		RequestLogger::log('login', 'login_attempt', true, [
			'user_id' => $user->key,
		]);

		// Save their session
		$session->store_session_variables($user);
		LoginClass::StoreUserLogin($user->key, LoginClass::LOGIN_FORM);

		// Potentially save a cookie if they set "Remember Me"
		if (!empty($input['setcookie']) || !empty($input['lbx_setcookie'])) {
			$session->save_user_to_cookie();
		}

		if (isset($_SESSION['forcelogin'])) {
			$_SESSION['forcelogin'] = FALSE;
		}

		$returnurl = $session->get_return();
		$_SESSION['returnurl'] = NULL;

		// Only a local path may be followed — no scheme, no protocol-relative
		// '//host'. The slot is server-written today; this keeps the redirect
		// from becoming an open redirect if any future caller stores user
		// input in it. '/login' itself is never a destination.
		if ($returnurl && (strpos($returnurl, '/') !== 0 || strpos($returnurl, '//') === 0
			|| strpos($returnurl, '/login') === 0)) {
			$returnurl = FALSE;
		}

		if ($returnurl) {
			return LogicResult::redirect($returnurl);
		} else {
			$alternate_homepage = $settings->get_setting('alternate_loggedin_homepage');
			return LogicResult::redirect($alternate_homepage ?: '/profile');
		}
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

	if(isset($input['msgtext'])){
		if (array_key_exists($input['msgtext'], $login_messages)) {
			$message = new DisplayMessage(htmlspecialchars($login_messages[$input['msgtext']]), 'Login warning', '/\/login.*/', DisplayMessage::MESSAGE_WARNING, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, "loginbox", TRUE);
			$session->save_message($message);
		}
	}
	if(isset($input['retry'])){
		if(isset($input['ip_blocked'])){
			$blocked_ip = isset($input['ip']) ? htmlspecialchars($input['ip']) : 'unknown';
			$message = new DisplayMessage('Login from your IP address (' . $blocked_ip . ') is not permitted for this account. Please contact an administrator if you believe this is an error.', 'Login blocked', '/\/login.*/', DisplayMessage::MESSAGE_WARNING, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, "loginbox", TRUE);
		} else {
			$message = new DisplayMessage('Your username or password was incorrect.  Please try again below, or sign up if you don\'t have an account.  If you forgot your password, <a href="/password-reset-1">click here</a> and we\'ll send you a new one.', 'Login warning', '/\/login.*/', DisplayMessage::MESSAGE_WARNING, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, "loginbox", TRUE);
		}
		$session->save_message($message);
	}

	$email = '';
	if (isset($input['e'])) {
		$e = rawurldecode($input['e']);
		if (LibraryFunctions::IsValidEmail($e)) {
			$page_vars['email'] = $e;
		}
	}

	$page_vars['display_messages'] = $session->get_messages($_SERVER['REQUEST_URI']);
	$session->clear_clearable_messages();
	return LogicResult::render($page_vars);
}
?>
