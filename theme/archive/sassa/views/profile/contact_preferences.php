<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageSassa.php', '/includes'));
	require_once(LibraryFunctions::get_logic_file_path('contact_preferences_logic.php'));	

	$page_vars = contact_preferences_logic($_GET, $_POST);

	$tab_menus = array(
		'My Profile' => '/profile',
		'Edit Account' => '/profile/account_edit',
		'Change Password' => '/profile/password_edit',
		'Edit Address' => '/profile/address_edit',
		'Edit Phone Number' => '/profile/phone_numbers_edit',
		'Change Contact Preferences' => '/profile/contact_preferences',
		'Change Subscription' => '/profile/subscription_edit',
	);
	
	$page = new PublicPageSassa();
	$hoptions=array(
		'title'=>'Contact Preferences',
		'breadcrumbs' => array(
			'My Profile' => '/profile/profile',
			'Contact Preferences' => '',
		),
	);
	$page->public_header($hoptions);
	echo PublicPageSassa::BeginPage('Contact Preferences', $hoptions);

	
	echo PublicPageSassa::tab_menu($tab_menus, 'Change Contact Preferences');
	
             
	echo '<p>You can opt-out of mailing lists, but transactional emails cannot be disabled.</p><br>';

	foreach($page_vars['display_messages'] AS $display_message) {
		if($display_message->identifier == 'contactbox') {	
			echo PublicPageSassa::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
		}
	}    

	if(!$_REQUEST['type'] == 'ocu'){		
		$formwriter = LibraryFunctions::get_formwriter_object();
		echo $formwriter->begin_form("", "post", "/profile/contact_preferences");
		
		if(empty($page_vars['optionvals'])){
			echo '<p>You are currently not subscribed to any newsletters.</p>';
		}
		else{

			echo $formwriter->checkboxList("Check the box to subscribe:", 'new_list_subscribes', "ctrlHolder", $page_vars['optionvals'], $page_vars['checkedvals'], $page_vars['disabledvals'], $page_vars['readonlyvals']);	
			

			echo $formwriter->hiddeninput('zone', 'optional');
			echo $formwriter->new_form_button('Submit', 'th-btn');
		}
		echo $formwriter->end_form();
	}

	echo PublicPageSassa::EndPage();
	$page->public_footer($foptions=array());
?>
