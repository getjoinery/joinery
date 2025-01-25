<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_logic_file_path('account_edit_logic.php'));	
	
	$page_vars = account_edit_logic($_GET, $_POST);
	

	$page = new PublicPageTW();
	$hoptions=array(
		'title'=>'Account Edit', 
		'breadcrumbs' => array(
			'My Profile' => '/profile/profile',
			'Account Edit' => '',
		),
	);
	$page->public_header($hoptions); 

	echo PublicPageTW::BeginPage('Account Edit', $hoptions);
	

	foreach($page_vars['display_messages'] AS $display_message) {
		if($display_message->identifier == 'userbox') {	
			echo PublicPageTW::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
		}
	}		

	echo PublicPageTW::tab_menu($page_vars['tab_menus']);
	
	
	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'tailwind');
	echo $formwriter->begin_form("", "post", "/profile/account_edit");

	echo $formwriter->textinput("First Name", "usr_first_name", NULL, 20, $page_vars['user']->get('usr_first_name'), "",255, "");
	echo $formwriter->textinput("Last Name", "usr_last_name", NULL, 20, $page_vars['user']->get('usr_last_name'), "" , 255, "");
	
	$nickname_display = $page_vars['settings']->get_setting('nickname_display_as');
	if($nickname_display){
		echo $formwriter->textinput($nickname_display, "usr_nickname", NULL, 20, $page_vars['user']->get('usr_nickname'), "" , 255, "");
	}

	$optionvals = Address::get_timezone_drop_array();
	echo $formwriter->dropinput("Your Time Zone", "usr_timezone", NULL, $optionvals, $page_vars['user']->get('usr_timezone'), '', FALSE);

	echo $formwriter->new_form_button('Submit');

	echo $formwriter->end_form();

	
		
	echo PublicPageTW::EndPage();	
	$page->public_footer($foptions=array('track'=>TRUE));
?>
