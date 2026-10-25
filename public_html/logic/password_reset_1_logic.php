<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function password_reset_1_logic(array $input): LogicResult{
	require_once(PathHelper::getIncludePath('includes/Activation.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/EmailTemplateRenderer.php'));

	require_once(PathHelper::getIncludePath('includes/SessionControl.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	if(!$settings->get_setting('register_active')){
			return LogicResult::error('This feature is turned off');
	}

	// Sending the reset is the form's button (POST). A link carrying ?email= is
	// a GET any site can make a browser follow, mailing someone a reset unasked;
	// opening the page with one only shows the form.
	if (LibraryFunctions::isFormSubmission() && (isset($input['email']) || isset($input['usr_email']))){

		// Rate limiting: block after too many password reset requests from this IP
		require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
		if (!RequestLogger::check_attempt_limit('password_reset', 5, 900)) {
			return LogicResult::error('Too many password reset requests. Please try again in 15 minutes.');
		}

		$email = strtolower(trim($input['email'] ?? $input['usr_email']));

		// What was typed: an email or a username.
		$user = User::GetByLogin($email);

		// One answer for every outcome, so the page says nothing about which accounts exist.
		$sent_message = 'Next step: Check your email for a message from us with a link to enter your new password.  If you do not receive an email from us within a few minutes, please check your spam folder.'
			. '  No email on your account? Reset with your authenticator app or passkey instead, or ask an administrator.';

		if ($user) {
			if($user->get('usr_password_recovery_disabled')){
					return LogicResult::error('This feature is turned off for this user.  Please email us to recover your password.');
			}

			RequestLogger::log('password_reset', 'reset_request', true, [
				'note' => 'Password reset sent to: ' . $email,
			]);
			Activation::email_forgotpw_send($user);
			$page_vars['message_type'] = 'success';
			$page_vars['message_title'] = 'Reset code sent';
			$page_vars['message'] = $sent_message;
		}
		else{
			RequestLogger::log('password_reset', 'reset_request', false, [
				'note' => 'Password reset for unknown email: ' . $email,
			]);
			// Return same success message to prevent user enumeration
			$page_vars['message_type'] = 'success';
			$page_vars['message_title'] = 'Reset code sent';
			$page_vars['message'] = $sent_message;
		}

	}
	else{

		$email = '';
		if (isset($input['e'])) {
			$e = rawurldecode($input['e']);
			if (LibraryFunctions::IsValidEmail($e)) {
				$email = $e;
			}
		}
	}

	return LogicResult::render($page_vars);
}

/**
 * Form builder — single source for the web reset-request form and the JSON
 * form definition (GET /api/v1/form/password_reset_1).
 */
function password_reset_1_logic_form($formwriter, $user = null, $input = []) {
	$formwriter->textinput('usr_email', 'Email or username:', [
		'required'     => true,
		'maxlength'    => 64,
		'autocomplete' => 'username',
	]);

	$formwriter->submitbutton('btn_submit', 'Send Reset Link');
}

function password_reset_1_logic_descriptor(): array {
	return [
		'auth' => ['min_user_permission' => 0],
		'description'      => 'Send a password reset email to the account with the given email or username.',
		'requires_session' => false,
		'mutates'          => true,
		'input'            => [
			'email' => ['type' => 'string', 'required' => true, 'label' => 'Email or username'],
		],
	];
}
?>
