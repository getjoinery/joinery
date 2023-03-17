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
	
             
	echo '<p>You can opt-out of newsletter emails, but course emails cannot be disabled.  If you want to stop receiving event or course emails, <a href="/profile">withdraw from the event</a></p>';

    if($announce_message) {
		echo '<div class="status_announcement"><p>'.$announce_message.'</p></div>';
    }     

	if(!$_REQUEST['type'] == 'ocu'){		
		$formwriter = new FormWriterPublicTW("form1");
		echo $formwriter->begin_form("", "post", "/profile/contact_preferences");
		$contact_prefs = $user->get('usr_contact_preferences');
		if ($contact_prefs === NULL) {
			list($newsletter, $offers, $updates, $user_feedback) = array(TRUE, TRUE, TRUE, TRUE);
		} 
		else {
			$newsletter = ($contact_prefs & User::NEWSLETTER) ? 1 : 0;
		}

		echo $formwriter->hiddeninput('zone', 'optional');
		$optionvals = array("Subscribed"=>1, "Unsubscribed"=>0);
		echo $formwriter->dropinput("Newsletters and updates", "newsletter", NULL, $optionvals, $newsletter, '', FALSE);
							
		echo '<a href="/profile/account_edit">Cancel</a> ';
		echo $formwriter->new_form_button('Submit');
		echo $formwriter->end_form();
	}

	echo PublicPageTW::EndPage();
	$page->public_footer($foptions=array());
?>
