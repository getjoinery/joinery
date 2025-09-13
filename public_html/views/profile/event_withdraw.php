<?php
	require_once(__DIR__ . '/../../includes/PathHelper.php');
	PathHelper::requireOnce('includes/Globalvars.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	PathHelper::requireOnce('includes/AdminPage.php');
	require_once(PathHelper::getThemeFilePath('event_withdraw_logic.php', 'logic'));


	$page = new PublicPage();
	$hoptions=array(
		'title'=>'Withdraw from Event/Course'
		);
	$page->public_header($hoptions);


	echo PublicPage::BeginPage('Withdraw from Event/Course', $hoptions);

	if(!$event_registrant){
		echo 'You are not registered for this course, or you have already withdrawn.';
	}
	else if(!$event->get('evt_end_time') || $event->get('evt_end_time') > date('Y-m-d H:i:s')){
		$settings = Globalvars::get_instance();
		$formwriter = $page->getFormWriter('form1');
		echo $formwriter->begin_form("form", "post", "/profile/event_withdraw");

		echo '<h4>Confirm withdrawal from '.$event->get('evt_name').'</h4>';
			echo '<p>Withdrawing from the course/event will remove you from the attendee list and the mailing list. </p><p><strong>It will NOT refund any payments.  To refund a payment, contact us at <a href="mailto:'.$settings->get_setting('defaultemail').'">'.$settings->get_setting('defaultemail').'</a></p>';

		echo $formwriter->hiddeninput("confirm", 1);
		echo $formwriter->hiddeninput("evr_event_registrant_id", $evr_event_registrant_id);

		echo $formwriter->new_form_button('Confirm');
		echo ' <a href="/profile">Cancel, I changed my mind</a>';

			echo '</div>';
		echo $formwriter->end_form();
		
	}
	else{
		echo 'You cannot withdraw from an event in the past.';
	}

	echo PublicPage::EndPage();
	$page->public_footer();

?>
