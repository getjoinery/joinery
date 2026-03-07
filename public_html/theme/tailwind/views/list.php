<?php
	
	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('list_logic.php', 'logic'));

	$page_vars = process_logic(list_logic($_GET, $_POST, $mailing_list, $params));
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
		$formwriter = $page->getFormWriter('form1', ['action' => $mailing_list->get_url(), 'method' => 'post']);
	
	$formwriter->antispam_question_validate([]);

	echo $formwriter->begin_form();

	if(!$session->get_user_id()){
		echo $formwriter->textinput('usr_first_name', 'First Name', ['maxlength' => 32]);
		echo $formwriter->textinput('usr_last_name', 'Last Name', ['maxlength' => 32]);
		$settings = Globalvars::get_instance();
		$nickname_display = $settings->get_setting('nickname_display_as');
		if($nickname_display){
			echo $formwriter->textinput('usr_nickname', $nickname_display, ['maxlength' => 32]);
		}
		echo $formwriter->textinput('usr_email', 'Email', ['type' => 'email', 'maxlength' => 64]);
		
		$optionvals = Address::get_timezone_drop_array();
		$default_timezone = $settings->get_setting('default_timezone');
		echo $formwriter->dropinput('usr_timezone', 'Your Timezone', ['options' => $optionvals, 'value' => $default_timezone]);
		echo $formwriter->checkboxinput('privacy', 'I consent to the privacy policy.', ['value' => 1]);
	}	

	if(!$member_of_list){
		echo $formwriter->hiddeninput('mlt_mailing_list_id', $mailing_list->key);
		echo $formwriter->checkboxinput('mlt_mailing_list_id_subscribe', 'Subscribe to this list.', ['value' => 1]);
		
	}	
	else{
		echo $formwriter->checkboxinput('mlt_mailing_list_id_unsubscribe', 'Unsubscribe from this list.', ['value' => 1]);
	}
	
	if(!$session->get_user_id()){
		echo $formwriter->antispam_question_input();
		echo $formwriter->honeypot_hidden_input();
		echo $formwriter->captcha_hidden_input();
	}

	echo $formwriter->submitbutton('btn_submit', 'Submit', ['class' => 'btn btn-primary']);

	echo $formwriter->end_form();
	
	echo PublicPage::EndPanel();
	echo PublicPage::EndPage();
	$page->public_footer(array('track'=>TRUE));
?>