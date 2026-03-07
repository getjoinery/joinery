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
		$formwriter = $page->getFormWriter('form1', ['action' => '/lists', 'method' => 'post']);
		
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
			echo $formwriter->textinput('usr_email', 'Email', ['type' => 'email', 'maxlength' => 64, 'value' => strip_tags($_GET['email'] ?? '')]);
			
			$optionvals = Address::get_timezone_drop_array();
			$default_timezone = $settings->get_setting('default_timezone');
			echo $formwriter->dropinput('usr_timezone', 'Your Timezone', ['options' => $optionvals, 'value' => $default_timezone]);
			
			echo $formwriter->checkboxinput('privacy', 'I consent to the privacy policy.', ['value' => 1]);
		}

		$optionvals = $mailing_lists->get_dropdown_array();	
		$checkedvals = $user_subscribed_list;
		$readonlyvals = array(); //DEFAULT
		$disabledvals = array();

		echo $formwriter->checkboxList('new_list_subscribes', 'Check the box to subscribe:', ['options' => $optionvals, 'checked_values' => $checkedvals]);
		echo $formwriter->hiddeninput('form_submitted', 1);
		
		if(!$session->get_user_id()){
			echo $formwriter->antispam_question_input();
			echo $formwriter->honeypot_hidden_input();
			echo $formwriter->captcha_hidden_input();
		}

		echo '<div>';
		echo $formwriter->submitbutton('btn_submit', 'Submit', ['class' => 'btn btn-primary']);
		echo '</div>';
		echo $formwriter->end_form();
		
	}
	
	echo PublicPage::EndPanel();
	echo PublicPage::EndPage();
	$page->public_footer(array('track'=>TRUE));
?>