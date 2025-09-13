<?php
	require_once(__DIR__ . '/../../includes/PathHelper.php');
	PathHelper::requireOnce('includes/Globalvars.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	PathHelper::requireOnce('includes/AdminPage.php');
	require_once(PathHelper::getThemeFilePath('contact_preferences_logic.php', 'logic'));	

	$page_vars = contact_preferences_logic($_GET, $_POST);
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
	$formwriter = $page->getFormWriter('form1');
	echo $formwriter->begin_form("", "post", "/profile/contact_preferences");
	
	if(empty($page_vars['optionvals'])){
		echo '<p>You are currently not subscribed to any newsletters.</p>';
	}
	else{

		echo $formwriter->checkboxList("Check the box to subscribe:", 'new_list_subscribes', "ctrlHolder", $page_vars['optionvals'], $page_vars['checkedvals'], $page_vars['disabledvals'], $page_vars['readonlyvals']);	
		

		echo $formwriter->hiddeninput('zone', 'optional');
		echo '<a href="/profile/account_edit">Cancel</a> ';
		echo $formwriter->new_form_button('Submit');
	}
	echo $formwriter->end_form();
	

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array());
?>
