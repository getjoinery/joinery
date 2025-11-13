<?php
	
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
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
	$formwriter = $page->getFormWriter('form1', [
		'action' => '/profile/event_withdraw'
	]);
	$formwriter->begin_form();

	echo '<h4>Confirm withdrawal from '.$event->get('evt_name').'</h4>';
	echo '<p>Withdrawing from the course/event will remove you from the attendee list and the mailing list. </p><p><strong>It will NOT refund any payments.  To refund a payment, contact us at <a href="mailto:'.$settings->get_setting('defaultemail').'">'.$settings->get_setting('defaultemail').'</a></p>';

	$formwriter->hiddeninput('confirm', '', ['value' => 1]);
	$formwriter->hiddeninput('evr_event_registrant_id', '', ['value' => $evr_event_registrant_id]);

	$formwriter->submitbutton('btn_submit', 'Confirm');
	echo ' <a href="/profile">Cancel, I changed my mind</a>';

	$formwriter->end_form();
		
	}
	else{
		echo 'You cannot withdraw from an event in the past.';
	}

	echo PublicPage::EndPage();
	$page->public_footer();

?>
