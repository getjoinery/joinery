<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/FormWriterPublic.php');
	require_once(LibraryFunctions::get_logic_file_path('contact_preferences_logic.php'));	


	$page = new PublicPage();
	$hoptions=array(
		'title'=>'Contact Preferences'
	);
	$page->public_header($hoptions);

	$options=array();
	$options['subtitle'] = '<a href="/profile/profile">Back to my profile</a>';
	echo PublicPage::BeginPage('Contact Preferences', $options);

	echo '<div class="section">
			<div class="container">';
	

	?>
	<ul class="nav nav-tabs margin-bottom-20">
	  <li class="nav-item">
		<a class="nav-link" href="/profile/account_edit">Edit Account</a>
	  </li>
	  <li class="nav-item">
		<a class="nav-link" href="/profile/password_edit">Change Password</a>
	  </li>
	  <li class="nav-item">
		<a class="nav-link " href="/profile/address_edit">Edit Address</a>
	  </li>
	  <li class="nav-item">
		<a class="nav-link " href="/profile/phone_numbers_edit">Edit Phone Number</a>
	  </li>
	  <li class="nav-item">
		<a class="nav-link active" href="/profile/contact_preferences">Change Contact Preferences</a>
	  </li>
	</ul>
	<?php
             
	echo '<p>You can opt-out of newsletter emails, but course emails cannot be disabled.  If you want to stop receiving course emails, <a href="/profile">withdraw from the course</a></p>';

    if($announce_message) {
		echo '<div class="status_announcement"><p>'.$announce_message.'</p></div>';
    }     

	if(!$_REQUEST['type'] == 'ocu'){		
		$formwriter = new FormWriterPublic("form1");
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
		echo $formwriter->dropinput("Newsletters and updates", "newsletter", "ctrlHolder", $optionvals, $newsletter, '', FALSE);
							
		echo '<a href="/profile/account_edit">Cancel</a> ';
		echo $formwriter->new_form_button('Submit','button button-lg button-dark');
		echo $formwriter->end_form();
	}
	echo '</div></div>';
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array());
?>
