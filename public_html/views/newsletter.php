<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/PublicPage.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublic.php');
	require_once (LibraryFunctions::get_logic_file_path('newsletter_logic.php'));

	$page = new PublicPage();
	$hoptions = array(
		'title' => 'Newsletter',
	);
	$page->public_header($hoptions);
	

	
	echo PublicPage::BeginPage('Newsletters');
			
	echo '<div class="section padding-top-20">
			<div class="container">';
	
	if($message){
		echo '<p>'.$message.'</p>';
	}
	
	?>
	<h3>Get updates on upcoming retreats, online classes, and other news.</h3>
	<?php

	$formwriter = new FormWriterPublic("form1", TRUE);
	
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
	
	echo $formwriter->begin_form("", "post", "/newsletter");
	echo '<fieldset class="inlineLabels">';
	echo $formwriter->textinput("First Name", "usr_first_name", "ctrlHolder", 30, '', "", 32, "");
	echo $formwriter->textinput("Last Name", "usr_last_name", "ctrlHolder", 30, '', "", 32, "");
	$settings = Globalvars::get_instance();
	$nickname_display = $settings->get_setting('nickname_display_as');
	if($nickname_display){
		echo $formwriter->textinput($nickname_display, "usr_nickname", "ctrlHolder", 20, NULL, "" , 32, "");
	}
	echo $formwriter->textinput("Email", "usr_email", "ctrlHolder", 30, '', "", 64, "");
	
	$optionvals = Address::get_timezone_drop_array();
	$default_timezone = $settings->get_setting('default_timezone');
	echo $formwriter->dropinput("Your timezone", "usr_timezone", "ctrlHolder", $optionvals, $default_timezone, '', FALSE);	
	
	
	echo $formwriter->antispam_question_input();
	echo $formwriter->honeypot_hidden_input();

	echo $formwriter->checkboxinput("I consent to the privacy policy.", "privacy", "checkbox", "left", NULL, 1, "");
	
	echo $formwriter->captcha_hidden_input();
	echo $formwriter->new_form_button('Sign up for the newsletter', 'button button-lg button-dark', 'submit1');
	echo $formwriter->end_form();
	
	echo '</div></div>';
	echo PublicPage::EndPage();
	$page->public_footer(array('track'=>TRUE));
?>