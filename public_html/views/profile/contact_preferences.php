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
	
             
	echo '<p>You can opt-out of mailing lists, but course or event emails cannot be disabled.  If you want to stop receiving event or course emails, <a href="/profile">withdraw from the event</a></p><br>';

	if(!empty($messages)){
		foreach ($messages as $message){
			echo PublicPageTW::alert($message['message_title'], $message['message'], $message['message_type']);
		}
	}    

	if(!$_REQUEST['type'] == 'ocu'){		
		$formwriter = new FormWriterPublicTW("form1");
		echo $formwriter->begin_form("", "post", "/profile/contact_preferences");
		
		$optionvals = $mailing_lists->get_dropdown_array();	
		//REMOVE ALL OF THE PRIVATE AND UNLISTED LISTS THE USER IS NOT SUBSCRIBED TO
		foreach($optionvals as $key=>$value){
			$mailing_list = new MailingList($value, TRUE);
			if($mailing_list->get('mlt_visibility') == MailingList::VISIBILITY_PRIVATE || $mailing_list->get('mlt_visibility') == MailingList::VISIBILITY_PUBLIC_UNLISTED){
				if(!in_array($value, $user_subscribed_list)){
					unset($optionvals[$key]);
				}
			}
		}

		$checkedvals = $user_subscribed_list;
		$readonlyvals = array(); //DEFAULT
		$disabledvals = array();
		
		if(empty($optionvals)){
			echo '<p>You are currently not subscribed to any newsletters.</p>';
		}
		else{

			echo $formwriter->checkboxList("Check the box to subscribe:", 'new_list_subscribes', "ctrlHolder", $optionvals, $checkedvals, $disabledvals, $readonlyvals);	
			

			echo $formwriter->hiddeninput('zone', 'optional');
			echo '<a href="/profile/account_edit">Cancel</a> ';
			echo $formwriter->new_form_button('Submit');
		}
		echo $formwriter->end_form();
	}

	echo PublicPageTW::EndPage();
	$page->public_footer($foptions=array());
?>
