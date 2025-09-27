<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function password_reset_1_logic($get_vars, $post_vars){
	PathHelper::requireOnce('includes/Activation.php');
PathHelper::requireOnce('includes/LogicResult.php');
	PathHelper::requireOnce('includes/EmailTemplate.php');

	PathHelper::requireOnce('includes/SessionControl.php');

	PathHelper::requireOnce('data/users_class.php');

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	if(!$settings->get_setting('register_active')){
			header("HTTP/1.0 404 Not Found");
			echo 'This feature is turned off';
			exit();
	}

	if (isset($post_vars['email'])){

		$email = strtolower(trim($post_vars['email']));

		$user = User::GetByEmail($email);

		if ($user) {
			if($user->get('usr_password_recovery_disabled')){
					header("HTTP/1.0 404 Not Found");
					echo 'This feature is turned off for this user.  Please email us to recover your password.';
					exit();
			}

			Activation::email_forgotpw_send($email);
			$page_vars['message_type'] = 'success';
			$page_vars['message_title'] = 'Reset code sent';
			$page_vars['message'] = 'Next step: Check your email for a message from us with a link to enter your new password.  If you don not receive an email from us within a few minutes, please check your spam folder.';
		}
		else{
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
?>
