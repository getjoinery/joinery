<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/FormWriterPublic.php');
	require_once (LibraryFunctions::get_logic_file_path('event_waiting_list_logic.php'));
	
	$page = new PublicPage();
	$hoptions = array(
		'title' => 'Waiting List',
		'description' => ''
	);
	$page->public_header($hoptions);
	
	echo PublicPage::BeginPage('Waiting list for '.$event->get('evt_name'));
		echo '<div class="section">
			<div class="container">';
    echo '<h3>Add yourself to the waiting list, and we will notify you as soon as registration is available.</h3>';

	if($display_message){
		echo '<div class="'.$message_type.'">'.$display_message.'</div>';
	}
	else{

		$formwriter = new FormWriterPublic("form1", TRUE);
		$validation_rules = array();
		$validation_rules['usr_first_name']['required']['value'] = 'true';
		$validation_rules['usr_first_name']['minlength']['value'] = 1;
		$validation_rules['usr_first_name']['required']['message'] = "'Please enter your first name.'";
		$validation_rules['usr_last_name']['required']['value'] = 'true';
		$validation_rules['usr_last_name']['minlength']['value'] = 2;
		$validation_rules['privacy']['required']['value'] = 'true';
		$validation_rules['usr_email']['required']['value'] = 'true';
		$validation_rules['usr_email']['email']['value'] = 'true';
		$validation_rules = FormWriterPublic::antispam_question_validate($validation_rules);
		echo $formwriter->set_validate($validation_rules);		
		
		echo $formwriter->begin_form("", "post", "/event_waiting_list");
		echo $formwriter->hiddeninput("event_id", $event->key);
		echo $formwriter->textinput("First Name", "usr_first_name", "ctrlHolder", 30, '', "", 255, "");
		echo $formwriter->textinput("Last Name", "usr_last_name", "ctrlHolder", 30, '', "", 255, "");
		echo $formwriter->textinput("Email", "usr_email", "ctrlHolder", 30, '', "", 255, "");
		echo $formwriter->antispam_question_input();
		echo $formwriter->honeypot_hidden_input();


		echo $formwriter->checkboxinput("I consent to the privacy policy.", "privacy", "checkbox", "left", NULL, 1, "");
		echo $formwriter->checkboxinput("Add me to the newsletter", "newsletter", "checkbox", "left", NULL, 1, "");
		if(!$session->get_user_id()){
			echo $formwriter->captcha_hidden_input();
		}

		echo $formwriter->new_form_button('Add me to the waiting list', 'button button-lg button-dark', 'submit1');
		echo $formwriter->end_form();
	}

	echo '</div></div>';
	echo PublicPage::EndPage();	
	$page->public_footer(array('track'=>TRUE));
?>