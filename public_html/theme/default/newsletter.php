<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/theme/integralzen/includes/FormWriterPublic.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');

	$settings = Globalvars::get_instance();
	if(!$settings->get_setting('newsletter_active')){
		include("404.php");
		exit();
	}

	$session = SessionControl::get_instance();
	$session->set_return();

	$page = new PublicPage();
	$hoptions = array(
		//'title' => '',
		//'description' => '',
		'body_id' => 'about-integral-zen',
	);
	$page->public_header($hoptions);
	
	if($_POST){
		
		
		if(!FormWriterPublic::honeypot_check($_POST)){
			throw new SystemDisplayableError(
				'Please leave the "Extra email" field blank.');			
		}
		
		if(!FormWriterPublic::antispam_question_check($_POST)){
			throw new SystemDisplayableError(
				'Please type the correct value into the anti-spam field.');			
		}		
		
		$captcha_success = FormWriterPublic::captcha_check($_POST);
		if (!$captcha_success) {
			$errormsg = 'Sorry, '.strip_tags($_POST['usr_first_name']).' '.strip_tags($_POST['usr_last_name']).', you must click the CAPTCHA to submit the form.';
			throw new SystemDisplayableError($errormsg);	
		}	
		
		
		//IF USER IS LOGGED IN, LOAD THEIR INFO...IF NOT SEE IF THERE IS EXISTING USER...IF NOT CREATE ONE
		$user = NULL;
		if($session->get_user_id()){ 
			$user = new User($session->get_user_id(), TRUE);
		}
		else if(!$user = User::GetByEmail($_POST['usr_email'])){
			$user = User::CreateNewUser($_POST['usr_first_name'], $_POST['usr_last_name'], $_POST['usr_email'], NULL, FALSE);	//DO NOT SEND WELCOME EMAIL	
		}
		
		if($user->get('usr_contact_preferences')){
			echo '<h1 class="entry-title">Newsletters</h1>';
			echo '<p>You are already subscribed to our newsletter.  If you would like to unsubscribe, visit <a href="/profile">the My Profile page</a>.</p>';
			$page->public_footer(array('track'=>TRUE));
			exit();
		}
		

		$status = $user->add_to_mailing_list();	
			
		echo '<h1 class="entry-title">Newsletters</h1>';
		if(!$status){
			echo '<p>We were unable to add you to our mailing list.  Please try again later.</p>';
		}			
		else if($status->title == 'Member Exists'){
			echo '<p>You are already signed up for our mailing list.</p>';
		}
		else{
			echo '<p>You are now signed up for our mailing list.</p>';
		}
		$page->public_footer(array('track'=>TRUE));
		exit();	
				
	}
	
	echo PublicPage::BeginPage('Newsletters');
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