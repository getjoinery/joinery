<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPage.php', '/includes'));
	require_once (LibraryFunctions::get_logic_file_path('register_logic.php'));

	$page_vars = register_logic($_GET, $_POST);


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
	$formwriter = LibraryFunctions::get_formwriter_object('form1', $settings->get_setting('form_style'));

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
	echo $formwriter->set_validate($validation_rules);
	
	echo $formwriter->begin_form("form1", "post", "/register", TRUE);
	echo $formwriter->hiddeninput("prevformname", "register");

	echo $formwriter->textinput("First Name", "usr_first_name", '', 20, @$form_fields->usr_first_name , "",32, "");	
	echo $formwriter->textinput("Last Name", "usr_last_name", '', 20, @$form_fields->usr_last_name, "" , 32, "");
	
	if($nickname_display){
		echo $formwriter->textinput($nickname_display, "usr_nickname", '', 20, @$form_fields->usr_nickname, "" , 32, "");
	}
	echo $formwriter->textinput("Email", "usr_email", '', 20, '', "" , 64, "");

	echo $formwriter->passwordinput("Create Password", "password", '', 20, "" , "", 255,"");

	$optionvals = Address::get_timezone_drop_array();
	$default_timezone = $settings->get_setting('default_timezone');
	echo $formwriter->dropinput("Timezone", "usr_timezone", '', $optionvals, $default_timezone, '', FALSE);	
	
	echo $formwriter->antispam_question_input();
	//echo $formwriter->textinput("Zip Code", "usa_zip_code_id", NULL, 20, @$form_fields->usa_zip_code_id, "", 255,"");


	echo $formwriter->checkboxinput("I have read and agree to the <a href='/privacy'>privacy policy</a>", "privacy", "", "normal", NULL, "yes", '');
	echo $formwriter->checkboxinput("Please add me to the mailing list", "newsletter", "", "normal", NULL, "yes", '');	
	echo $formwriter->checkboxinput("Keep me logged in", "setcookie", "", "normal", 'yes', "yes", '');
	echo $formwriter->honeypot_hidden_input();	

	echo $formwriter->captcha_hidden_input();
	//echo $formwriter->start_buttons();
	//echo $formwriter->new_form_button('Cancel', 'secondary');
	echo $formwriter->new_form_button('Submit', 'primary', 'full');
	//echo $formwriter->end_buttons();
	echo $formwriter->end_form(true);

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'fbconnect'=>TRUE));

?>
