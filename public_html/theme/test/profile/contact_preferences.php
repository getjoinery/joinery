<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	$logic_path = LibraryFunctions::get_logic_file_path('contact_preferences_logic.php');
	require_once ($logic_path);	
	
	$settings = Globalvars::get_instance();
	$site_template = $settings->get_setting('site_template');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/includes/PublicPage.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/includes/FormWriterPublic.php');


	$page = new PublicPage();
	$hoptions=array(
		'title'=>'Contact Preferences', 
		'currentmain'=>'Contact Preferences');
	$page->public_header($hoptions);
	echo '<a class="back-link" href="/profile/profile">My Profile</a> > Contact Preferences<br />';
	echo PublicPage::BeginPage('Contact Preferences');
		
             
	echo '<p>You can opt-out of newsletter emails, but course emails cannot be disabled.  If you want to stop receiving course emails, <a href="/profile">withdraw from the course</a></p>';

    if($announce_message) {
		echo '<div class="status_announcement"><p>'.$announce_message.'</p></div>';
    }     

	if(!$_REQUEST['type'] == 'ocu'){		
		$formwriter = new FormWriterPublic("form1");
		echo $formwriter->begin_form("uniForm", "post", "/profile/contact_preferences");
		echo '<fieldset class="inlineLabels">';
		$contact_prefs = $user->get('usr_contact_preferences');
		if ($contact_prefs === NULL) {
			list($newsletter, $offers, $updates, $user_feedback) = array(TRUE, TRUE, TRUE, TRUE);
		} 
		else {
			$newsletter = ($contact_prefs & User::NEWSLETTER) ? 1 : 0;
		}

		echo $formwriter->hiddeninput('zone', 'optional');
		$optionvals = array("Subscribed"=>1, "Unsubscribed"=>0);
		echo $formwriter->dropinput("Newsletters and updates", "newsletter", "ctrlHolder", $optionvals, $newsletter, '', FALSE);
							
		echo $formwriter->start_buttons();
		echo $formwriter->new_form_button('Submit','');
		echo $formwriter->end_buttons();
		echo '</fieldset>';
		echo $formwriter->end_form();
	}

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'fbconnect'=>TRUE));
?>
