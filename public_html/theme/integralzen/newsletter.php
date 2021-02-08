<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once('includes/PublicPage.php');
	require_once('includes/FormWriterPublic.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	$logic_path = LibraryFunctions::get_logic_file_path('newsletter_logic.php');
	require_once ($logic_path);	

	$page = new PublicPage();
	$hoptions = array(
		//'title' => '',
		//'description' => '',
		'body_id' => 'about-integral-zen',
	);
	$page->public_header($hoptions);
	

	
	echo PublicPage::BeginPage('Newsletters');
	if($message){
		echo '<p>'.$message.'</p>';
	}
	
	?>
	<h3>Get updates on upcoming retreats, online classes, and other news from Integral Zen.</h3>
	<?php

	$formwriter = new FormWriterPublic("form1", TRUE);
	
	$validation_rules = array();
	$validation_rules['usr_first_name']['required']['value'] = 'true';
	$validation_rules['usr_first_name']['minlength']['value'] = 1;
	$validation_rules['usr_first_name']['required']['message'] = "'Please enter your first name.'";
	$validation_rules['usr_last_name']['required']['value'] = 'true';
	$validation_rules['privacy']['required']['value'] = 'true';
	$validation_rules['usr_email']['required']['value'] = 'true';
	$validation_rules['usr_email']['email']['value'] = 'true';
	$validation_rules = FormWriterPublic::antispam_question_validate($validation_rules);
	echo $formwriter->set_validate($validation_rules);		
	
	echo $formwriter->begin_form("uniForm", "post", "/newsletter");
	echo '<fieldset class="inlineLabels">';
	echo $formwriter->textinput("First Name", "usr_first_name", "ctrlHolder", 30, '', "", 255, "");
	echo $formwriter->textinput("Last Name", "usr_last_name", "ctrlHolder", 30, '', "", 255, "");
	echo $formwriter->textinput("Email", "usr_email", "ctrlHolder", 30, '', "", 255, "");
	echo $formwriter->antispam_question_input();
	echo $formwriter->honeypot_hidden_input();

	echo $formwriter->start_buttons();
	echo $formwriter->checkboxinput("I consent to the privacy policy.", "privacy", "checkbox", "left", NULL, 1, "");
	
	echo $formwriter->captcha_hidden_input();
	echo $formwriter->new_form_button('Sign up for the newsletter', '', 'submit1');
	echo $formwriter->end_buttons();
	echo '</fieldset>';
	echo $formwriter->end_form();
	
	echo PublicPage::EndPage();
	$page->public_footer(array('track'=>TRUE));
?>