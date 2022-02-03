<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/PublicPageTW.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublicTW.php');
	require_once(LibraryFunctions::get_logic_file_path('account_edit_logic.php'));	
	
	$settings = Globalvars::get_instance();

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
	

	foreach($display_messages AS $display_message) {
		if($display_message->identifier == 'userbox') {			
			echo '<div class="'.$display_message->get_message_class().'">'.$display_message->message.'</div>';
		}
	}		

	echo PublicPageTW::tab_menu($tab_menus);
	
	
	$formwriter = new FormWriterPublicTW("form1");
	echo $formwriter->begin_form("", "post", "/logic/users_edit_logic");

	//$optionvals = array(""=>'', "Male"=>0, "Female"=>1);
	echo $formwriter->textinput("First Name", "usr_first_name", NULL, 20, $user->get('usr_first_name'), "",255, "");
	echo $formwriter->textinput("Last Name", "usr_last_name", NULL, 20, $user->get('usr_last_name'), "" , 255, "");
	
	$nickname_display = $settings->get_setting('nickname_display_as');
	if($nickname_display){
		echo $formwriter->textinput($nickname_display, "usr_nickname", NULL, 20, $user->get('usr_nickname'), "" , 255, "");
	}

	//echo $formwriter->dropinput("Gender (optional)", "usr_gender", NULL, $optionvals, $user->get('usr_gender'), '', FALSE);
	$optionvals = Address::get_timezone_drop_array();
	echo $formwriter->dropinput("Your Time Zone", "usr_timezone", NULL, $optionvals, $user->get('usr_timezone'), '', FALSE);
	//TODO ALLOW THE USER TO CHANGE EMAILS
	//echo $formwriter->textinput("Email", "usr_email_new", NULL, 20, $user->get('usr_email'), "" , 255, "");

	echo $formwriter->new_form_button('Submit');

	echo $formwriter->end_form();

	
		
	echo PublicPageTW::EndPage();	
	$page->public_footer($foptions=array('track'=>TRUE));
?>
