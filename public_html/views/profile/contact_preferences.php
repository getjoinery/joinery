<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php', '/includes'));
	require_once(LibraryFunctions::get_theme_file_path('FormWriterPublicTW.php', '/includes'));
	require_once(LibraryFunctions::get_logic_file_path('contact_preferences_logic.php'));	


	$page = new PublicPageTW();
	$hoptions=array(
		'title'=>'Contact Preferences',
		'breadcrumbs' => array(
			'My Profile' => '/profile/profile',
			'Contact Preferences' => '',
		),
	);
	$page->public_header($hoptions);
	echo PublicPageTW::BeginPage('Contact Preferences', $hoptions);

	
	echo PublicPageTW::tab_menu($tab_menus);
	
             
	echo '<p>You can opt-out of newsletter emails, but course emails cannot be disabled.  If you want to stop receiving event or course emails, <a href="/profile">withdraw from the event</a></p><br>';

    if($announce_message) {
		echo '<div class="status_announcement"><p>'.$announce_message.'</p></div>';
    }     

	if(!$_REQUEST['type'] == 'ocu'){		
		$formwriter = new FormWriterPublicTW("form1");
		echo $formwriter->begin_form("", "post", "/profile/contact_preferences");
		
		$searches = array('deleted' => false);
		$sort = LibraryFunctions::fetch_variable('sort', 'contact_type_id', 0, '');
		$sdirection = LibraryFunctions::fetch_variable('sdirection', 'ASC', 0, '');
		$contact_types = new MultiContactType(
			$searches,
			array($sort=>$sdirection));
		$contact_types->load();
		$optionvals = $contact_types->get_dropdown_array();	
	

		$checkedvals = $user->get_contact_type_unsubscribes();
		$readonlyvals = array(2); //DEFAULT
		$disabledvals = array();
	
		echo $formwriter->checkboxList("Check the box to unsubscribe:", 'unsubscribes_list', "ctrlHolder", $optionvals, $checkedvals, $disabledvals, $readonlyvals);
		

		echo $formwriter->hiddeninput('zone', 'optional');
		echo '<a href="/profile/account_edit">Cancel</a> ';
		echo $formwriter->new_form_button('Submit');
		echo $formwriter->end_form();
	}

	echo PublicPageTW::EndPage();
	$page->public_footer($foptions=array());
?>
