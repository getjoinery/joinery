<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/PublicPage.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublic.php');
	require_once (LibraryFunctions::get_logic_file_path('register_logic.php'));


	$settings = Globalvars::get_instance();
	$page = new PublicPage(TRUE);
	$hoptions=array(
		'title'=>'Register',
	);
	$page->public_header($hoptions,NULL);

	echo PublicPage::BeginPage('Register');
		echo '<div class="section padding-top-20">
			<div class="container">';
			
	if(isset($_GET['msgtext'])){
		if (array_key_exists($_GET['msgtext'], $LOGIN_MESSAGES)) {
			echo '<div class="status_warning">'.htmlspecialchars($LOGIN_MESSAGES[$_GET['msgtext']]).'</div>';
		}
	}		
			


	$formwriter = new FormWriterPublic("form1", TRUE);

	$validation_rules = array();
	$validation_rules['usr_first_name']['required']['value'] = 'true';
	$validation_rules['usr_first_name']['minlength']['value'] = 1;
	$validation_rules['usr_first_name']['maxlength']['value'] = 32;
	$validation_rules['usr_first_name']['required']['message'] = "'Please enter your first name.'";
	$validation_rules['usr_last_name']['required']['value'] = 'true';
	$validation_rules['usr_last_name']['minlength']['value'] = 2;
	$validation_rules['usr_last_name']['maxlength']['value'] = 32;
	$validation_rules['privacy']['required']['value'] = 'true';
	$validation_rules['usr_email']['required']['value'] = 'true';
	$validation_rules['usr_email']['email']['value'] = 'true';
	$validation_rules['usr_email']['maxlength']['value'] = 64;
	$validation_rules['usr_email']['remote']['value'] = "'/ajax/email_check_ajax'";	
	$validation_rules['usr_email']['remote']['message'] = "'This email already exists.'";
	$validation_rules['usr_password']['required']['value'] = 'true';
	$validation_rules['usr_password']['minlength']['value'] = 5;	
	$validation_rules['usr_password']['minlength']['message'] = "'Password must be at least {0} characters'";
	if($nickname_display){
		$validation_rules['usr_nickname']['maxlength']['value'] = 32;
	}
	$validation_rules = FormWriterPublic::antispam_question_validate($validation_rules);
	echo $formwriter->set_validate($validation_rules);

	echo $formwriter->begin_form("", "post", "/register");
	echo $formwriter->hiddeninput("prevformname", "register");
	?>
	<h2>Register</h2>
	<div><a href="/login<?php if(isset($_GET['m'])){ echo '?m='.htmlspecialchars($_GET['m']); } ?>">Already a member? Log in</a></div>
	<?php

	echo $formwriter->textinput("First Name", "usr_first_name", "ctrlHolder", 20, @$form_fields->usr_first_name , "",32, "");	
	echo $formwriter->textinput("Last Name", "usr_last_name", "ctrlHolder", 20, @$form_fields->usr_last_name, "" , 32, "");
	$nickname_display = $settings->get_setting('nickname_display_as');
	if($nickname_display){
		echo $formwriter->textinput($nickname_display, "usr_nickname", "ctrlHolder", 20, @$form_fields->usr_nickname, "" , 32, "");
	}
	echo $formwriter->textinput("Email", "usr_email", "ctrlHolder", 20, '', "" , 64, "");

	echo $formwriter->passwordinput("Create Password", "usr_password", "ctrlHolder", 20, "" , "", 255,"");
	echo $formwriter->antispam_question_input();
	//echo $formwriter->textinput("Zip Code", "usa_zip_code_id", "ctrlHolder", 20, @$form_fields->usa_zip_code_id, "", 255,"");

	echo $formwriter->checkboxinput("I have read and agree to the <a href='/privacy-policy'>privacy policy</a>", "privacy", "ctrlHolder", "normal", NULL, "yes", '');
	echo $formwriter->checkboxinput("Please add me to the mailing list", "mailing_list", "ctrlHolder", "normal", NULL, "yes", '');	
	echo $formwriter->checkboxinput("Keep me logged in", "setcookie", "ctrlHolder", "normal", 'yes', "yes", '');
	echo $formwriter->honeypot_hidden_input();	

	echo $formwriter->captcha_hidden_input();
	echo $formwriter->new_form_button('Submit', 'button button-lg button-dark', 'submit1');

	echo $formwriter->end_form();

	echo '</div></div>';
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'fbconnect'=>TRUE));

?>
