<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php', '/includes'));
	require_once(LibraryFunctions::get_theme_file_path('FormWriterPublicTW.php', '/includes'));
	require_once (LibraryFunctions::get_logic_file_path('event_waiting_list_logic.php'));
	
	$event_id = LibraryFunctions::fetch_variable('event_id', 0, 1, 'You must pass an event.', TRUE, 'int');
	$page_vars = event_waiting_list_logic($_GET, $_POST, $event_id);
	$event = $page_vars['event'];
	
	$page = new PublicPageTW();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Waiting List',
		'description' => ''
	);
	$page->public_header($hoptions);
	
	$options = array();
	$options['subtitle'] = 'Add yourself to the waiting list, and we will notify you as soon as registration is available.';
	echo PublicPageTW::BeginPage('Waiting list for '.$event->get('evt_name'), $options);
	echo PublicPageTW::BeginPanel();

	if($page_vars['display_message']){
		echo PublicPageTW::alert('Success', $page_vars['display_message'], $page_vars['message_type']);
	}
	else{

		$formwriter = new FormWriterPublicTW("form1", TRUE);
		$validation_rules = array();
		$validation_rules['usr_first_name']['required']['value'] = 'true';
		$validation_rules['usr_first_name']['minlength']['value'] = 1;
		$validation_rules['usr_first_name']['required']['message'] = "'Please enter your first name.'";
		$validation_rules['usr_first_name']['maxlength']['value'] = 32;
		$validation_rules['usr_last_name']['required']['value'] = 'true';
		$validation_rules['usr_last_name']['minlength']['value'] = 2;
		$validation_rules['usr_last_name']['maxlength']['value'] = 32;
		$validation_rules['privacy']['required']['value'] = 'true';
		$validation_rules['usr_email']['required']['value'] = 'true';
		$validation_rules['usr_email']['email']['value'] = 'true';
		$validation_rules['usr_email']['maxlength']['value'] = 64;
		$validation_rules = FormWriterPublicTW::antispam_question_validate($validation_rules);
		echo $formwriter->set_validate($validation_rules);		
		
		echo $formwriter->begin_form("", "post", "/event_waiting_list");
		echo $formwriter->hiddeninput("event_id", $event->key);
		if($page_vars['session']->get_user_id()){
			echo '<p>Click the button below to be added to this waiting list.</p>';
		}
		else{
			echo $formwriter->textinput("First Name", "usr_first_name", NULL, 30, '', "", 32, "");
			echo $formwriter->textinput("Last Name", "usr_last_name", NULL, 30, '', "", 32, "");
			
			$nickname_display = $page_vars['settings']->get_setting('nickname_display_as');
			if($nickname_display){
				echo $formwriter->textinput($nickname_display, "usr_nickname", NULL, 20, NULL, "" , 32, "");
			}
			echo $formwriter->textinput("Email", "usr_email", NULL, 30, '', "", 64, "");
			
			$optionvals = Address::get_timezone_drop_array();
			$default_timezone = $page_vars['settings']->get_setting('default_timezone');
			echo $formwriter->dropinput("Your timezone", "usr_timezone", NULL, $optionvals, $default_timezone, '', FALSE);			
			
			echo $formwriter->antispam_question_input();
			echo $formwriter->honeypot_hidden_input();


			echo $formwriter->checkboxinput("I consent to the privacy policy.", "privacy", "checkbox", "left", NULL, 1, "");
			echo $formwriter->checkboxinput("Add me to the newsletter", "newsletter", "checkbox", "left", NULL, 1, "");
			if(!$page_vars['session']->get_user_id()){
				echo $formwriter->captcha_hidden_input();
			}
		}

		echo $formwriter->new_form_button('Add me to the waiting list');
		echo $formwriter->end_form();
	}

	echo PublicPageTW::EndPanel();
	echo PublicPageTW::EndPage();	
	$page->public_footer(array('track'=>TRUE));
?>