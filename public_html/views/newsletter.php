<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/PublicPageTW.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublicTW.php');
	require_once (LibraryFunctions::get_logic_file_path('newsletter_logic.php'));

	$page = new PublicPageTW();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Newsletter',
	);
	$page->public_header($hoptions);
	

	$options['subtitle'] = 'Get updates on upcoming retreats, online classes, and other news.';
	echo PublicPageTW::BeginPage('Newsletters', $options);
	echo PublicPageTW::BeginPanel();

	
	if($message){
		echo PublicPageTW::alert($message_title, $message, $message_type);
	}
	

	$formwriter = new FormWriterPublicTW("form1", TRUE);
	
	$validation_rules = array();
	$validation_rules['usr_first_name']['required']['value'] = 'true';
	$validation_rules['usr_first_name']['minlength']['value'] = 1;
	$validation_rules['usr_first_name']['required']['message'] = "'Please enter your first name.'";
	$validation_rules['usr_first_name']['maxlength']['value'] = 32;
	$validation_rules['usr_last_name']['required']['value'] = 'true';
	$validation_rules['usr_last_name']['maxlength']['value'] = 32;
	$validation_rules['privacy']['required']['value'] = 'true';
	$validation_rules['usr_email']['required']['value'] = 'true';
	$validation_rules['usr_email']['email']['value'] = 'true';
	$validation_rules['usr_email']['maxlength']['value'] = 64;
	$validation_rules = FormWriterPublic::antispam_question_validate($validation_rules);
	echo $formwriter->set_validate($validation_rules);		
	
	echo $formwriter->begin_form("", "post", "/newsletter", true);

	echo $formwriter->textinput("First Name", "usr_first_name", NULL, 30, '', "", 32, "");
	echo $formwriter->textinput("Last Name", "usr_last_name", NULL, 30, '', "", 32, "");
	$settings = Globalvars::get_instance();
	$nickname_display = $settings->get_setting('nickname_display_as');
	if($nickname_display){
		echo $formwriter->textinput($nickname_display, "usr_nickname", NULL, 20, NULL, "" , 32, "");
	}
	echo $formwriter->textinput("Email", "usr_email", NULL, 30, '', "", 64, "");
	
	$optionvals = Address::get_timezone_drop_array();
	$default_timezone = $settings->get_setting('default_timezone');
	echo $formwriter->dropinput("Your timezone", "usr_timezone", NULL, $optionvals, $default_timezone, '', FALSE);	
	
	
	echo $formwriter->antispam_question_input();
	echo $formwriter->honeypot_hidden_input();

	echo $formwriter->checkboxinput("I consent to the privacy policy.", "privacy", "sm:col-span-6", "left", NULL, 1, "");
	
	echo $formwriter->captcha_hidden_input();
	echo $formwriter->new_form_button('Sign up for the newsletter');
	echo $formwriter->end_form();
	
	echo PublicPageTW::EndPanel();
	echo PublicPageTW::EndPage();
	$page->public_footer(array('track'=>TRUE));
?>