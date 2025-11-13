<?php
	
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('contact_preferences_logic.php', 'logic'));	

	$page_vars = process_logic(contact_preferences_logic($_GET, $_POST));
	$messages = $page_vars['messages'];

	$page = new PublicPage();
	$hoptions=array(
		'title'=>'Contact Preferences',
		'breadcrumbs' => array(
			'My Profile' => '/profile/profile',
			'Contact Preferences' => '',
		),
	);
	$page->public_header($hoptions);
	echo PublicPage::BeginPage('Change Contact Preferences', $hoptions);

	echo PublicPage::tab_menu($page_vars['tab_menus'], 'Change Contact Preferences');

	echo '<p>If you want to stop receiving event or course emails, <a href="/profile">withdraw from the event</a></p><br>';

	/*
	foreach($page_vars['display_messages'] AS $display_message) {
		if($display_message->identifier == 'contactbox') {	
			echo PublicPage::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
		}
	}
	*/

	foreach ($messages as $message){
		echo PublicPage::alert($message['message_title'], $message['message'], $message['message_type']);
	}

	$settings = Globalvars::get_instance();
	$formwriter = $page->getFormWriter('form1', [
		'action' => '/profile/contact_preferences'
	]);
	$formwriter->begin_form();

	if(empty($page_vars['optionvals'])){
		echo '<p>You are currently not subscribed to any newsletters.</p>';
	}
	else{

		$formwriter->checkboxList('new_list_subscribes', 'Check the box to subscribe:', [
			'options' => $page_vars['optionvals'],
			'checked' => $page_vars['checkedvals'],
			'disabled' => $page_vars['disabledvals'],
			'readonly' => $page_vars['readonlyvals']
		]);

		$formwriter->hiddeninput('zone', '', ['value' => 'optional']);
		echo '<a href="/profile/account_edit">Cancel</a> ';
		$formwriter->submitbutton('btn_submit', 'Submit');
	}
	$formwriter->end_form();

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array());
?>
