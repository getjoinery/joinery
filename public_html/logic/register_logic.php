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
		// Population-2 precondition at account creation
		// (specs/mailbox_security_levels.md § Password reset): a login email that is
		// a mailbox hosted on this platform is circular — a forgotten-password link
		// would land in an inbox that requires this very account to read. A brand-new
		// account holds no non-email reset path yet, so choosing such an address up
		// front is refused; the user signs up with an external address and may switch
		// to a hosted one later (account_edit) once a passkey/authenticator/recovery
		// address exists. State the locked-out floor now, not during the crisis.
		else if (_register_email_is_platform_hosted($fixed_fields['usr_email'])) {
			return LogicResult::error('That address is a mailbox hosted here, so it cannot be your login email yet: a forgotten-password link would land in an inbox you would be locked out of. Sign up with an outside email address (Gmail, Outlook, etc.). Once you are in, you can add a passkey or authenticator app and then switch your login to a hosted address.');
		}
		else{
			$user = User::CreateCompleteNew($fixed_fields, true, true, $fixed_fields['setcookie']);
			$user->set('usr_terms_accepted_time', gmdate('Y-m-d H:i:s'));
			$user->save();
			$_SESSION['terms_accepted'] = true;
		}

		require_once(PathHelper::getIncludePath('includes/SignalBus.php'));
		SignalBus::dispatch('account.signup', array(
			'user_id'        => $user->key,
			'email'          => $user->get('usr_email'),
			'display_name'   => trim($user->display_name()),
			'source_user_id' => $user->key,
		));

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

/**
 * True when the email's domain is a mailbox domain hosted on this platform
 * (specs/mailbox_security_levels.md § Password reset, Population 2). The mailbox
 * plugin may be absent; treat "no plugin" as "not hosted".
 */
function _register_email_is_platform_hosted(string $email): bool {
	$at = strrpos($email, '@');
	if ($at === false) {
		return false;
	}
	$domain = strtolower(trim(substr($email, $at + 1)));
	if ($domain === '') {
		return false;
	}
	$domain_class = PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php');
	if (!is_file($domain_class)) {
		return false;
	}
	require_once($domain_class);
	if (!class_exists('InboundEmailDomain')) {
		return false;
	}
	return InboundEmailDomain::isHostedEmailAddress($email);
}

function register_logic_api() {
    return [
        'requires_session' => false,
        'description' => 'Register a new user account',
    ];
}

/**
 * Form builder — single source for the web register form and the JSON form
 * definition (GET /api/v1/form/register). Web-only bot defences (antispam,
 * honeypot, captcha) stay in the web view, not here.
 */
function register_logic_form($formwriter, $user = null, $input = []) {
	require_once(PathHelper::getIncludePath('data/address_class.php'));
	$settings = Globalvars::get_instance();

	$formwriter->textinput('usr_first_name', 'First Name:', [
		'maxlength' => 32,
		'required' => true,
	]);
	$formwriter->textinput('usr_last_name', 'Last Name:', [
		'maxlength' => 32,
		'required' => true,
	]);

	$nickname_display = $settings->get_setting('nickname_display_as');
	if ($nickname_display) {
		$formwriter->textinput('usr_nickname', htmlspecialchars($nickname_display) . ':', [
			'maxlength' => 32,
		]);
	}

	$formwriter->textinput('usr_email', 'Email Address:', [
		'type' => 'email',
		'maxlength' => 64,
		'required' => true,
	]);

	$formwriter->passwordinput('password', 'Choose Password:', [
		'maxlength' => 255,
		'required' => true,
	]);

	$formwriter->dropinput('usr_timezone', 'Timezone:', [
		'options' => Address::get_timezone_drop_array(),
		'value' => $settings->get_setting('default_timezone'),
	]);

	$privacy_url = trim((string)$settings->get_setting('privacy_url'));
	$privacy_label = $privacy_url !== ''
		? "I have read and agree to the <a href='" . htmlspecialchars($privacy_url, ENT_QUOTES, 'UTF-8') . "' target='_blank' rel='noopener'>privacy policy</a>"
		: "I have read and agree to the privacy policy";
	$formwriter->checkboxinput('privacy', $privacy_label, [
		'value' => 'yes',
	]);
	$formwriter->checkboxinput('newsletter', 'Please add me to the mailing list', [
		'value' => 'yes',
	]);
	$formwriter->checkboxinput('setcookie', 'Keep me logged in', [
		'value' => 'yes',
		'checked' => true,
	]);

	$formwriter->submitbutton('btn_submit', 'Register Now');
}

function register_logic_descriptor(): array {
	return [
		'description'      => 'Create a new user account.',
		'requires_session' => false,
		'mutates'          => true,
		'ai_agent'         => 'confirm',
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
