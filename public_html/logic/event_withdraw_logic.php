<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_registrants_class.php');
	
	
	$settings = Globalvars::get_instance();
	if(!$settings->get_setting('events_active')){
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();
	}
	
if ($_POST){

	$session = SessionControl::get_instance();
	$session->check_permission(0);

	$evr_event_registrant_id = LibraryFunctions::fetch_variable('evr_event_registrant_id', NULL, 1, 'You must provide a registrant.', 'int');
	$confirm = LibraryFunctions::fetch_variable('confirm', NULL, 1, 'You must confirm the action.');	
	
	if ($confirm) {

		$event_registrant = new EventRegistrant($evr_event_registrant_id, TRUE);
		$event = new Event($event_registrant->get('evr_evt_event_id'),true);
		$event_registrant->authenticate_write($session);
		$event_registrant->remove();
		
		$msgtxt = 'You have now withdrawn from '.$event->get('evt_name').'.';
		$message = new DisplayMessage($msgtxt, 'Success', '/\/profile\/account/', DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox', TRUE);
		$session->save_message($message);	

		header("Location: /profile");
		exit();		

	}

}
else{
	$evr_event_registrant_id = LibraryFunctions::fetch_variable('evr_event_registrant_id', NULL, 1, 'You must provide a registrant.');

	$event_registrant = new EventRegistrant($evr_event_registrant_id, true);
	$user = new User($event_registrant->get('evr_usr_user_id'), TRUE);
	$event = new Event($event_registrant->get('evr_evt_event_id'),true);
	
}
	
?>
