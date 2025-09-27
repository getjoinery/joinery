<?php
	
	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('list_logic.php', 'logic'));

	$page_vars = list_logic($_GET, $_POST, $mailing_list, $params);
// Handle LogicResult return format
if ($page_vars->redirect) {
    LibraryFunctions::redirect($page_vars->redirect);
    exit();
}
$page_vars = $page_vars->data;
	$messages = $page_vars['messages'];
	$member_of_list = $page_vars['member_of_list'];
	$session = $page_vars['session'];
	
	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Newsletter',
	);
	$page->public_header($hoptions);

	$options['subtitle'] = $mailing_list->get('mlt_description');
	echo PublicPage::BeginPage($mailing_list->get('mlt_name'), $options);
	echo PublicPage::BeginPanel();
	
	if(!empty($messages)){
		foreach ($messages as $message){
			echo PublicPage::alert($message['message_title'], $message['message'], $message['message_type']);
		}
	}
	
		$settings = Globalvars::get_instance();
		$formwriter = $page->getFormWriter('form1');
	
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
	
	echo $formwriter->begin_form("", "post", $mailing_list->get_url(), true);

	if(!$session->get_user_id()){
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
		echo $formwriter->checkboxinput("I consent to the privacy policy.", "privacy", "", "left", 1, NULL, "");
	}	

	if(!$member_of_list){
		echo $formwriter->hiddeninput('mlt_mailing_list_id', $mailing_list->key);
		echo $formwriter->checkboxinput("Subscribe to this list.", "mlt_mailing_list_id_subscribe", "", "left", 1, NULL, "");
		
	}	
	else{
		echo $formwriter->checkboxinput("Unsubscribe from this list.", "mlt_mailing_list_id_unsubscribe", "", "left", 1, NULL, "");
	}
	
	if(!$session->get_user_id()){
		echo $formwriter->antispam_question_input();
		echo $formwriter->honeypot_hidden_input();
		echo $formwriter->captcha_hidden_input();
	}

	echo $formwriter->new_form_button('Submit');

	echo $formwriter->end_form();
	
	echo PublicPage::EndPanel();
	echo PublicPage::EndPage();
	$page->public_footer(array('track'=>TRUE));
?>