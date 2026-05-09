<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getThemeFilePath('FormWriter.php', 'includes'));

function register_logic(array $input): LogicResult{
	// Check if the page was requested with jQuery, if so, we should process this page differently
	$ajax = !(empty($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] != 'XMLHttpRequest');

	require_once(PathHelper::getIncludePath('includes/Activation.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/EmailTemplate.php'));

	require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
	require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/address_class.php'));

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	if(!$settings->get_setting('register_active')){
		return LogicResult::error('This feature is turned off');
	}

	$page_vars['LOGIN_MESSAGES'] = array(
		'phone_reveal' => 'Before you can view this phone number, please log in or register with us.',
	);

	if ($session->get_user_id()) {
		return LogicResult::redirect('/profile/profile');
	}

	if (!empty($_POST)) {

		$formwriter = new FormWriter('form1');
		if(!$formwriter->honeypot_check($input)){
			return LogicResult::error('This feature is turned off');
		}

		if(!$formwriter->antispam_question_check($input)){
			return LogicResult::error('Please type the correct value into the anti-spam field.');
		}

		$captcha_success = $formwriter->captcha_check($input);
		if (!$captcha_success) {
			$errormsg = 'Sorry, '.strip_tags($input['usr_first_name']).' '.strip_tags($input['usr_last_name']).', you must click the CAPTCHA to submit the form.';
			return LogicResult::error($errormsg);
		}

		if(isset($input['prevformname'])){
			$session->save_formfields($input['prevformname']);
		}

		$required_fields = array(
			'usr_email' => 'Email Address',
			'usr_first_name' => 'First Name',
			'usr_last_name' => 'Last Name',
			//'usa_zip_code_id' => 'Zip Code',
			'password' => 'Password'
		);

		$fixed_fields = array();
		$error_fields = array();

		// Since each registration field may either be "name" or "lbx_reg_name", we have to go
		// through and pull them both out, and put them in fixed_fields
		foreach ($required_fields as $field => $description) {
			if (isset($input[$field])) {
				$fixed_fields[$field] = trim($input[$field]);
			} else if (isset($input['lbx_reg_' . $field])) {
				$fixed_fields[$field] = trim($input['lbx_reg_' . $field]);
			} else {
				$error_fields[] = $description;
				continue;
			}

			if (!$fixed_fields[$field]) {
				$error_fields[] = $description;
			}
		}

		if (isset($input['setcookie']) || isset($input['lbx_reg_setcookie'])) {
			$fixed_fields['setcookie'] = TRUE;
		} else {
			$fixed_fields['setcookie'] = FALSE;
		}

		if ($error_fields) {
			return LogicResult::error("The following required fields were left blank: " . implode(', ', $error_fields) . '.  Please try again.');
		}

		/*
		$zip_data = SingleRowFetch('zips.zip_codes', 'zip_code_id',
			$fixed_fields['usa_zip_code_id'], PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);

		if (!$zip_data) {
			throw new SystemDisplayableError(
				'We could not find that zip code.  Please try again.');
		}
		*/

		if (User::GetByEmail($fixed_fields['usr_email'])) {
			return LogicResult::error('An account has already been registered with this email address.  Please go back and double check the email you entered or <a href="/password-reset-1">click here</a> if you forgot your password.');
		}
		else{
			$user = User::CreateCompleteNew($fixed_fields, true, true, $fixed_fields['setcookie']);
			$user->set('usr_terms_accepted_time', gmdate('Y-m-d H:i:s'));
			$user->save();
			$_SESSION['terms_accepted'] = true;
		}

		$returnurl = $session->get_return();
		$session->set_return(NULL);

		if ($returnurl) {
			return LogicResult::redirect($returnurl);
		} else {
			return LogicResult::redirect('/page/register-thanks');
		}

	}
	else {

		$form_fields = $session->get_formfields('register');

		$session->set_formfields_save("register");
	}
	return LogicResult::render($page_vars);
}

function register_logic_api() {
    return [
        'requires_session' => false,
        'description' => 'Register a new user account',
    ];
}

function register_logic_descriptor(): array {
	return [
		'description'      => 'Create a new user account.',
		'requires_session' => false,
		'mutates'          => true,
		'input'            => [
			'usr_email' => ['type' => 'email', 'required' => true, 'label' => 'Email address'],
			'usr_first_name' => ['type' => 'string', 'required' => true, 'label' => 'First name'],
			'usr_last_name' => ['type' => 'string', 'required' => true, 'label' => 'Last name'],
			'password' => ['type' => 'password', 'required' => true, 'label' => 'Password'],
			'setcookie' => ['type' => 'bool', 'required' => false, 'label' => 'Remember me'],
		],
	];
}
?>
