<?php

	require_once(PathHelper::getThemeFilePath('register_logic.php', 'logic'));

	$page_vars = register_logic($_GET, $_POST);
	// Handle LogicResult return format
	if ($page_vars->redirect) {
		LibraryFunctions::redirect($page_vars->redirect);
		exit();
	}
	$page_vars = $page_vars->data;

	$page = new PublicPage();
	$hoptions=array(
		'is_valid_page' => $is_valid_page,
		'title'=>'Register',
	);
	$page->public_header($hoptions,NULL);

	$extra = '';
	if(isset($_GET['m'])){
		$extra = '?m='.htmlspecialchars($_GET['m']);
	}
	$options['subtitle'] = '<a href="/login'.$extra.'">Already a member? Log in</a>';
	echo PublicPage::BeginPage('Register', $options);

	if(isset($_GET['msgtext'])){
		if (array_key_exists($_GET['msgtext'], $page_vars['LOGIN_MESSAGES'])) {
			echo PublicPage::alert('Login warning', htmlspecialchars($LOGIN_MESSAGES[$_GET['msgtext']]), 'warn');
		}
	}

	$settings = Globalvars::get_instance();
	$nickname_display = $settings->get_setting('nickname_display_as');

	$formwriter = $page->getFormWriter('form1', [
		'action' => '/register'
	]);

	$validation_rules = array();
	$validation_rules['usr_first_name']['required']['value'] = 'true';
	$validation_rules['usr_first_name']['minlength']['value'] = 1;
	$validation_rules['usr_first_name']['maxlength']['value'] = 32;
	$validation_rules['usr_first_name']['required']['message'] = "'Please enter your first name.'";
	$validation_rules['usr_last_name']['required']['value'] = 'true';
	$validation_rules['usr_last_name']['minlength']['value'] = 2;
	$validation_rules['usr_last_name']['maxlength']['value'] = 32;
	if($nickname_display){
	$validation_rules['usr_nickname']['maxlength']['value'] = 32;
	}
	$validation_rules['usr_email']['required']['value'] = 'true';
	$validation_rules['usr_email']['email']['value'] = 'true';
	$validation_rules['usr_email']['maxlength']['value'] = 64;
	$validation_rules['usr_email']['remote']['value'] = "'/ajax/email_check_ajax'";
	$validation_rules['usr_email']['remote']['message'] = "'This email already exists.'";
	$validation_rules['password']['required']['value'] = 'true';
	$validation_rules['password']['minlength']['value'] = 5;
	$validation_rules['password']['minlength']['message'] = "'Password must be at least {0} characters'";
	$validation_rules['privacy']['required']['value'] = 'true';
	if($nickname_display){
		$validation_rules['usr_nickname']['maxlength']['value'] = 32;
	}
	$validation_rules = $formwriter->antispam_question_validate($validation_rules);

	$formwriter->begin_form();
	$formwriter->hiddeninput("prevformname", "register");

	$formwriter->textinput("usr_first_name", "First Name", [
		'value' => @$form_fields->usr_first_name,
		'maxlength' => 32
	]);
	$formwriter->textinput("usr_last_name", "Last Name", [
		'value' => @$form_fields->usr_last_name,
		'maxlength' => 32
	]);

	if($nickname_display){
		$formwriter->textinput("usr_nickname", $nickname_display, [
			'value' => @$form_fields->usr_nickname,
			'maxlength' => 32
		]);
	}
	$formwriter->textinput("usr_email", "Email", [
		'maxlength' => 64
	]);

	$formwriter->passwordinput("password", "Create Password", [
		'maxlength' => 255
	]);

	$optionvals = Address::get_timezone_drop_array();
	$default_timezone = $settings->get_setting('default_timezone');
	$formwriter->dropinput("usr_timezone", "Timezone", [
		'options' => $optionvals,
		'value' => $default_timezone
	]);

	$formwriter->antispam_question_input();

	$formwriter->checkboxinput("privacy", "I have read and agree to the <a href='/privacy'>privacy policy</a>", [
		'value' => 'yes'
	]);
	$formwriter->checkboxinput("newsletter", "Please add me to the mailing list", [
		'value' => 'yes'
	]);
	$formwriter->checkboxinput("setcookie", "Keep me logged in", [
		'value' => 'yes',
		'checked' => true
	]);
	$formwriter->honeypot_hidden_input();
	$formwriter->captcha_hidden_input();

	$formwriter->submitbutton('btn_submit', 'Submit');
	$formwriter->end_form(true);

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'fbconnect'=>TRUE));

?>
