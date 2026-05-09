<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function password_reset_2_logic(array $input): LogicResult{
	require_once(PathHelper::getIncludePath('includes/Activation.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	if(!$settings->get_setting('register_active')){
			return LogicResult::error('This feature is turned off');
	}

	$act_code = $input['act_code'];
	if(!$act_code){
		$act_code = $input['act_code'];
	}
	$page_vars['act_code'] = $act_code;

	// Decide whether to render the terms checkbox. Show it when this code resolves
	// to a user who has never accepted terms (typically a recipient auto-create or
	// admin-add user using the reset link as activation).
	$page_vars['terms_already_accepted'] = true;
	if ($act_code) {
		require_once(PathHelper::getIncludePath('data/users_class.php'));
		$pre_user_id = Activation::getIdFromTempCode($act_code, 2);
		if ($pre_user_id) {
			$pre_user = new User($pre_user_id, true);
			$page_vars['terms_already_accepted'] = !empty($pre_user->get('usr_terms_accepted_time'));
		}
	}

	if (!empty($_POST)) {

		if (!RequestLogger::check_rate_limit('password_reset_complete', 5, 900, false)) {
			return LogicResult::error('Too many reset attempts. Please wait a few minutes and try again.');
		}

		$success = Activation::checkTempCode($act_code, 2);

		if(!$success){
			return LogicResult::error('Sorry, this code has expired.  Please <a href="/password-reset-1">click here</a> to send another password reset email.');
		}

		if(!isset($input['usr_password']) || !isset($input['usr_password_again'])){
			return LogicResult::error('The following required fields were not set: passwords');
		}

		if ($input['usr_password'] != $input['usr_password_again']) {
			return LogicResult::error('Your password did not match in both fields.');
		}

		// Attempt to activiate the user if they aren't already activated and get the user
		$user = Activation::ActivateUser($act_code);

		if (!$user) {
			return LogicResult::error('Sorry, this form has expired.  Please <a href="/password-reset-1">click here</a> to send another password reset email.');
		}

		if($user->get('usr_password_recovery_disabled')){
				return LogicResult::error('This feature is turned off for this user.  Please email us to recover your password.');
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

		// Now delete the code
		Activation::deleteTempCode($act_code);
		$page_vars['message_type'] = 'success';
		$page_vars['message_title'] = 'Password reset';
		$page_vars['message'] = 'Your password has been reset. <a href="/login">Click here to log in</a>.';
	}

	return LogicResult::render($page_vars);
}

function password_reset_2_logic_api() {
    return [
        'requires_session' => false,
        'description' => 'Set new password via reset code',
    ];
}

function password_reset_2_logic_descriptor(): array {
	return [
		'description'      => 'Set a new password using a one-time reset code.',
		'requires_session' => false,
		'mutates'          => true,
		'input'            => [
			'act_code' => ['type' => 'string', 'required' => true, 'label' => 'Reset code'],
			'usr_password' => ['type' => 'password', 'required' => true, 'label' => 'New password'],
			'usr_password_again' => ['type' => 'password', 'required' => true, 'label' => 'Confirm new password'],
		],
	];
}
?>
