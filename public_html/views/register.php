<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('register_logic.php', 'logic'));

	$page_vars = process_logic(register_logic($_GET, $_POST));

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

	$nickname_display = $settings->get_setting('nickname_display_as');

	$settings = Globalvars::get_instance();
	$formwriter = $page->getFormWriter('form1');

	$formwriter->begin_form([
		'id' => 'form1',
		'method' => 'POST',
		'action' => '/register',
		'ajax' => true
	]);

	$formwriter->hiddeninput('prevformname', ['value' => 'register']);

	$formwriter->textinput('usr_first_name', 'First Name', [
		'value' => @$form_fields->usr_first_name,
		'maxlength' => 32,
		'required' => true,
		'minlength' => 1,
		'data-msg-required' => 'Please enter your first name.'
	]);

	$formwriter->textinput('usr_last_name', 'Last Name', [
		'value' => @$form_fields->usr_last_name,
		'maxlength' => 32,
		'required' => true,
		'minlength' => 2
	]);

	if($nickname_display){
		$formwriter->textinput('usr_nickname', $nickname_display, [
			'value' => @$form_fields->usr_nickname,
			'maxlength' => 32
		]);
	}

	$formwriter->textinput('usr_email', 'Email', [
		'maxlength' => 64,
		'required' => true,
		'type' => 'email',
		'data-rule-remote' => '/ajax/email_check_ajax',
		'data-msg-remote' => 'This email already exists.'
	]);

	$formwriter->passwordinput('password', 'Create Password', [
		'maxlength' => 255,
		'required' => true,
		'minlength' => 5,
		'data-msg-minlength' => 'Password must be at least 5 characters'
	]);

	$optionvals = Address::get_timezone_drop_array();
	$default_timezone = $settings->get_setting('default_timezone');
	$formwriter->dropinput('usr_timezone', 'Timezone', [
		'options' => $optionvals,
		'value' => $default_timezone
	]);

	$formwriter->antispam_question_input();

	$formwriter->checkboxinput('privacy', 'I have read and agree to the <a href=\'/privacy\'>privacy policy</a>', [
		'required' => true
	]);

	$formwriter->checkboxinput('newsletter', 'Please add me to the mailing list', [
		'value' => 'yes'
	]);

	$formwriter->checkboxinput('setcookie', 'Keep me logged in', [
		'checked' => true,
		'value' => 'yes'
	]);

	$formwriter->honeypot_hidden_input();
	$formwriter->captcha_hidden_input();

	$formwriter->submitbutton('submit', 'Submit', [
		'class' => 'btn btn-primary btn-block'
	]);

	$formwriter->end_form();

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'fbconnect'=>TRUE));

?>
