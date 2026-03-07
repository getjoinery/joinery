<?php
	
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('event_waiting_list_logic.php', 'logic'));
	
	$event_id = LibraryFunctions::fetch_variable('event_id', 0, 1, 'You must pass an event.', TRUE, 'int');
	$page_vars = process_logic(event_waiting_list_logic($_GET, $_POST, $event_id));
	$event = $page_vars['event'];
	
	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Waiting List',
		'description' => ''
	);
	$page->public_header($hoptions);
	
	$options = array();
	$options['subtitle'] = 'Add yourself to the waiting list, and we will notify you as soon as registration is available.';
	echo PublicPage::BeginPage('Waiting list for '.$event->get('evt_name'), $options);
	echo PublicPage::BeginPanel();

	if($page_vars['display_message']){
		echo PublicPage::alert('Success', $page_vars['display_message'], $page_vars['message_type']);
	}
	else{

		$settings = Globalvars::get_instance();
		$formwriter = $page->getFormWriter('form1', ['action' => '/event_waiting_list', 'method' => 'post']);
		$formwriter->antispam_question_validate([]);

		echo $formwriter->begin_form();
		echo $formwriter->hiddeninput("event_id", $event->key);
		if($page_vars['session']->get_user_id()){
			echo '<p>Click the button below to be added to this waiting list.</p>';
		}
		else{
			echo $formwriter->textinput('usr_first_name', 'First Name', ['maxlength' => 32]);
			echo $formwriter->textinput('usr_last_name', 'Last Name', ['maxlength' => 32]);
			
			$nickname_display = $page_vars['settings']->get_setting('nickname_display_as');
			if($nickname_display){
				echo $formwriter->textinput('usr_nickname', $nickname_display, ['maxlength' => 32]);
			}
			echo $formwriter->textinput('usr_email', 'Email', ['type' => 'email', 'maxlength' => 64]);
			
			$optionvals = Address::get_timezone_drop_array();
			$default_timezone = $page_vars['settings']->get_setting('default_timezone');
			echo $formwriter->dropinput('usr_timezone', 'Your Timezone', ['options' => $optionvals, 'value' => $default_timezone]);
			
			echo $formwriter->antispam_question_input();
			echo $formwriter->honeypot_hidden_input();

			echo $formwriter->checkboxinput('privacy', 'I consent to the privacy policy.', ['value' => 1]);
			echo $formwriter->checkboxinput('newsletter', 'Add me to the newsletter', ['value' => 1]);
			if(!$page_vars['session']->get_user_id()){
				echo $formwriter->captcha_hidden_input();
			}
		}

		echo $formwriter->submitbutton('btn_submit', 'Add me to the waiting list', ['class' => 'btn btn-primary']);
		echo $formwriter->end_form();
	}

	echo PublicPage::EndPanel();
	echo PublicPage::EndPage();	
	$page->public_footer(array('track'=>TRUE));
?>