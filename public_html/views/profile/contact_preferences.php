<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPage.php', '/includes'));
	require_once(LibraryFunctions::get_logic_file_path('contact_preferences_logic.php'));	

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
	echo PublicPage::BeginPage('Contact Preferences', $hoptions);

	
	echo PublicPage::tab_menu($page_vars['tab_menus']);
	
             
	echo '<p>You can opt-out of mailing lists, but course or event emails cannot be disabled.  If you want to stop receiving event or course emails, <a href="/profile">withdraw from the event</a></p><br>';

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
	  


	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'tailwind');
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
