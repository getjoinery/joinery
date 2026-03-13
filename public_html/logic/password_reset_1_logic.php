<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function password_reset_1_logic($get_vars, $post_vars){
	require_once(PathHelper::getIncludePath('includes/Activation.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/EmailTemplate.php'));

	require_once(PathHelper::getIncludePath('includes/SessionControl.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	if(!$settings->get_setting('register_active')){
			return LogicResult::error('This feature is turned off');
	}

	if (isset($post_vars['email']) || isset($post_vars['usr_email'])){

		// Rate limiting: block after too many password reset requests from this IP
		require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
		if (!RequestLogger::check_rate_limit('password_reset', 5, 900)) {
			return LogicResult::error('Too many password reset requests. Please try again in 15 minutes.');
		}

		$email = strtolower(trim($post_vars['email'] ?? $post_vars['usr_email']));

		$user = User::GetByEmail($email);

		if ($user) {
			if($user->get('usr_password_recovery_disabled')){
					return LogicResult::error('This feature is turned off for this user.  Please email us to recover your password.');
			}

			RequestLogger::log('password_reset', 'reset_request', true, [
				'note' => 'Password reset sent to: ' . $email,
			]);
			Activation::email_forgotpw_send($email);
			$page_vars['message_type'] = 'success';
			$page_vars['message_title'] = 'Reset code sent';
			$page_vars['message'] = 'Next step: Check your email for a message from us with a link to enter your new password.  If you don not receive an email from us within a few minutes, please check your spam folder.';
		}
		else{
			RequestLogger::log('password_reset', 'reset_request', false, [
				'note' => 'Password reset for unknown email: ' . $email,
			]);
			$page_vars['message_type'] = 'error';
			$page_vars['message_title'] = 'Email not found';
			$page_vars['message'] = '
			We could not find that email address.  Please use your back button to go back and double check the one you entered.';
		}

	}
	else{

		$email = '';
		if (isset($get_vars['e'])) {
			$e = rawurldecode($get_vars['e']);
			if (LibraryFunctions::IsValidEmail($e)) {
				$email = $e;
			}
		}
	}

	return LogicResult::render($page_vars);
}

function password_reset_1_logic_api() {
    return [
        'requires_session' => false,
        'description' => 'Request password reset email',
    ];
}
?>
