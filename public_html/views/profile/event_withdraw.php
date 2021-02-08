<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/FormWriterPublic.php');
	require_once(LibraryFunctions::get_logic_file_path('event_withdraw_logic.php'));


	$page = new PublicPage();
	$hoptions=array(
		'title'=>'Withdraw from Event/Course'
		);
	$page->public_header($hoptions);
	echo '<a class="back-link" href="/profile/profile">My Profile</a> > Withdraw from Event/Course<br />';
	echo PublicPage::BeginPage('Withdraw from Event/Course');

	if($event->get('evt_end_time') > date('Y-m-d H:i:s')){
		$formwriter = new FormWriterPublic("form1");
		echo $formwriter->begin_form("form", "post", "/profile/event_withdraw");

		echo '<fieldset><h4>Confirm withdrawal from '.$event->get('evt_name').'</h4>';
			echo '<div class="fields full">';
			echo '<p>Withdrawing from the course/event will remove you from the attendee list and the mailing list. </p><p><strong>It will NOT refund any payments.  To refund a payment, contact us at <a href="mailto:'.$settings->get_setting('defaultemail').'">'.$settings->get_setting('defaultemail').'</a></p>';

		echo $formwriter->hiddeninput("confirm", 1);
		echo $formwriter->hiddeninput("evr_event_registrant_id", $evr_event_registrant_id);

		echo $formwriter->start_buttons();
		echo $formwriter->new_form_button('Confirm','');
		echo $formwriter->end_buttons();
		echo '<a href="/profile">Cancel, I changed my mind</a>';

			echo '</div>';
		echo '</fieldset>';
		echo $formwriter->end_form();
		
	}
	else{
		echo 'You cannot withdraw from an event in the past.';
	}

	echo PublicPage::EndPage();
	$page->public_footer();

?>
