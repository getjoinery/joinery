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
	$formwriter = $page->getFormWriter('form1', 'v2');

	$formwriter->begin_form([
		'id' => '',
		'method' => 'POST',
		'action' => $mailing_list->get_url(),
		'ajax' => true
	]);

	if(!$session->get_user_id()){
		$formwriter->textinput('usr_first_name', 'First Name', [
			'maxlength' => 32,
			'required' => true,
			'minlength' => 1,
			'data-msg-required' => 'Please enter your first name.'
		]);

		$formwriter->textinput('usr_last_name', 'Last Name', [
			'maxlength' => 32,
			'required' => true
		]);

		$settings = Globalvars::get_instance();
		$nickname_display = $settings->get_setting('nickname_display_as');
		if($nickname_display){
			$formwriter->textinput('usr_nickname', $nickname_display, [
				'maxlength' => 32
			]);
		}

		$formwriter->textinput('usr_email', 'Email', [
			'maxlength' => 64,
			'required' => true,
			'type' => 'email'
		]);

		$optionvals = Address::get_timezone_drop_array();
		$default_timezone = $settings->get_setting('default_timezone');
		$formwriter->dropinput('usr_timezone', 'Your timezone', [
			'options' => $optionvals,
			'value' => $default_timezone
		]);

		$formwriter->checkboxinput('privacy', 'I consent to the privacy policy.', [
			'required' => true,
			'checked' => true
		]);
	}

	if(!$member_of_list){
		$formwriter->hiddeninput('mlt_mailing_list_id', ['value' => $mailing_list->key]);
		$formwriter->checkboxinput('mlt_mailing_list_id_subscribe', 'Subscribe to this list.', [
			'checked' => true
		]);
	}
	else{
		$formwriter->checkboxinput('mlt_mailing_list_id_unsubscribe', 'Unsubscribe from this list.', [
			'checked' => true
		]);
	}

	if(!$session->get_user_id()){
		$formwriter->antispam_question_input();
		$formwriter->honeypot_hidden_input();
		$formwriter->captcha_hidden_input();
	}

	$formwriter->submitbutton('submit', 'Submit', [
		'class' => 'btn btn-primary'
	]);

	$formwriter->end_form();

	echo PublicPage::EndPanel();
	echo PublicPage::EndPage();
	$page->public_footer(array('track'=>TRUE));
?>
