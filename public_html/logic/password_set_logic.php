<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function password_set_logic(array $input): LogicResult{
	require_once(PathHelper::getIncludePath('includes/Activation.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	if(!$settings->get_setting('register_active')){
		return LogicResult::error('This feature is turned off');
	}

	// Determine whether the consent checkbox should be shown.
	$page_vars['terms_already_accepted'] = false;
	if ($session->get_user_id()) {
		$current_user = new User($session->get_user_id(), TRUE);
		$page_vars['terms_already_accepted'] = !empty($current_user->get('usr_terms_accepted_time'));
	}

	if (!empty($_POST)) {

		if(!$session->get_user_id()){
			return LogicResult::error('You must be logged in to set a password.');
		}
		else{
			$user = new User($session->get_user_id(), TRUE);
		}

		if(!$user || $user->get('usr_password') !== NULL){
			return LogicResult::error('Sorry, your password is already set.  If you need to reset it, <a href="/password-reset-1">click here</a> to send a password reset email.');
		}

		if(!isset($input['usr_password']) || !isset($input['usr_password_again'])){
				return LogicResult::error('The following required fields were not set: passwords');
		}

		if ($input['usr_password'] != $input['usr_password_again']) {
			return LogicResult::error('Your password did not match in both fields.');
		}

		$terms_already_accepted = !empty($user->get('usr_terms_accepted_time'));
		if (!$terms_already_accepted && empty($input['accept_terms'])) {
			return LogicResult::error('You must agree to the Terms of Use and Privacy Policy to continue.');
		}

		$user->set('usr_password', User::GeneratePassword($input['usr_password']));
		if (!$terms_already_accepted) {
			$user->set('usr_terms_accepted_time', gmdate('Y-m-d H:i:s'));
		}
		$user->save();

		$_SESSION['terms_accepted'] = true;

		$page_vars['message_type'] = 'success';
		$page_vars['message_title'] = 'Reset code sent';
		$page_vars['message'] = 'Your password has been set. <a href="/login">Click here to log in</a>.';
	}
	return LogicResult::render($page_vars);
}

function password_set_logic_api() {
    return [
        'requires_session' => false,
        'description' => 'Set password on first login',
    ];
}

function password_set_logic_descriptor(): array {
	return [
		'description'      => 'Set an initial password for an account that has none.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => [
			'usr_password' => ['type' => 'password', 'required' => true, 'label' => 'Password'],
			'usr_password_again' => ['type' => 'password', 'required' => true, 'label' => 'Confirm password'],
		],
	];
}
?>
