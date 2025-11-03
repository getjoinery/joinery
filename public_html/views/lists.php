<?php

	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('lists_logic.php', 'logic'));

	$page_vars = process_logic(lists_logic($_GET, $_POST, $params));
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
		$formwriter = $page->getFormWriter('form1');

		$formwriter->begin_form([
			'id' => '',
			'method' => 'POST',
			'action' => '/lists',
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
				'value' => strip_tags($_GET['email']),
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

		$optionvals = $mailing_lists->get_dropdown_array();
		// get_dropdown_array returns [id => label] format, ready for FormWriter V2
		$checkedvals = $user_subscribed_list;
		$readonlyvals = array(); //DEFAULT
		$disabledvals = array();

		$formwriter->checkboxList('new_list_subscribes', 'Check the box to subscribe:', [
			'options' => $optionvals,
			'checked' => $checkedvals,
			'disabled' => $disabledvals,
			'readonly' => $readonlyvals
		]);

		$formwriter->hiddeninput('form_submitted', ['value' => 1]);

		if(!$session->get_user_id()){
			$formwriter->antispam_question_input();
			$formwriter->honeypot_hidden_input();
			$formwriter->captcha_hidden_input();
		}

		echo '<div>';
		$formwriter->submitbutton('submit', 'Submit', [
			'class' => 'btn btn-primary'
		]);
		echo '</div>';
		$formwriter->end_form();

	}

	echo PublicPage::EndPanel();
	echo PublicPage::EndPage();
	$page->public_footer(array('track'=>TRUE));
?>
