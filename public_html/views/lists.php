<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	PathHelper::requireOnce('/includes/SessionControl.php');
	PathHelper::requireOnce('/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPage.php', '/includes'));
	require_once (LibraryFunctions::get_logic_file_path('lists_logic.php'));

	$page_vars = lists_logic($_GET, $_POST, $params);
	$messages = $page_vars['messages'];
	$session = $page_vars['session'];
	$mailing_lists = $page_vars['mailing_lists'];
	$numlists = $page_vars['numlists'];
	
	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Newsletter',
	);
	$page->public_header($hoptions);
	

	$options['subtitle'] = 'Get updates from us.';
	echo PublicPage::BeginPage('Lists', $options);
	echo PublicPage::BeginPanel();

	
	if(!empty($messages)){
		foreach ($messages as $message){
			echo PublicPage::alert($message['message_title'], $message['message'], $message['message_type']);
		}
	}
	
	if($numlists == 0){
		echo '<p>There are currently no mailing lists to register for.</p>';
	}
	else{

		$settings = Globalvars::get_instance();
		$formwriter = LibraryFunctions::get_formwriter_object('form1', $settings->get_setting('form_style'));
		
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
		$validation_rules = $formwriter->antispam_question_validate($validation_rules);
		echo $formwriter->set_validate($validation_rules);		
		
		echo $formwriter->begin_form("", "post", "/lists", true);

		if(!$session->get_user_id()){
			echo $formwriter->textinput("First Name", "usr_first_name", NULL, 30, '', "", 32, "");
			echo $formwriter->textinput("Last Name", "usr_last_name", NULL, 30, '', "", 32, "");
			$settings = Globalvars::get_instance();
			$nickname_display = $settings->get_setting('nickname_display_as');
			if($nickname_display){
				echo $formwriter->textinput($nickname_display, "usr_nickname", NULL, 20, NULL, "" , 32, "");
			}
			echo $formwriter->textinput("Email", "usr_email", NULL, 30, strip_tags($_GET['email']), "", 64, "");
			
			$optionvals = Address::get_timezone_drop_array();
			$default_timezone = $settings->get_setting('default_timezone');
			echo $formwriter->dropinput("Your timezone", "usr_timezone", NULL, $optionvals, $default_timezone, '', FALSE);	
			
			echo $formwriter->checkboxinput("I consent to the privacy policy.", "privacy", "", "left", 1, NULL, "");
		}

		$optionvals = $mailing_lists->get_dropdown_array();	
		$checkedvals = $user_subscribed_list;
		$readonlyvals = array(); //DEFAULT
		$disabledvals = array();

		echo $formwriter->checkboxList("Check the box to subscribe:", 'new_list_subscribes', "ctrlHolder", $optionvals, $checkedvals, $disabledvals, $readonlyvals);	
		echo $formwriter->hiddeninput('form_submitted', 1);
		
		if(!$session->get_user_id()){
			echo $formwriter->antispam_question_input();
			echo $formwriter->honeypot_hidden_input();
			echo $formwriter->captcha_hidden_input();
		}
		
		
		echo '<div>';
		echo $formwriter->new_form_button('Submit');
		echo '</div>';
		echo $formwriter->end_form();
		
	}
	
	echo PublicPage::EndPanel();
	echo PublicPage::EndPage();
	$page->public_footer(array('track'=>TRUE));
?>