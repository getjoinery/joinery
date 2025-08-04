<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	// ErrorHandler.php no longer needed - using new ErrorManager system
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	
	PathHelper::requireOnce('data/users_class.php');
	PathHelper::requireOnce('data/events_class.php');
	PathHelper::requireOnce('data/event_registrants_class.php');
	
	
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

		if(EventRegistrant::check_if_exists($evr_event_registrant_id)){
			$event_registrant = new EventRegistrant($evr_event_registrant_id, TRUE);
			$event = new Event($event_registrant->get('evr_evt_event_id'),true);
			$event_registrant->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
			$event_registrant->remove();
			
			$msgtxt = 'You have now withdrawn from '.$event->get('evt_name').'.';
			$message = new DisplayMessage($msgtxt, 'Success', '/\/profile\/account/', DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox', TRUE);
			$session->save_message($message);	
		}
		else{
			$msgtxt = 'You are no longer registered for the event.';
			$message = new DisplayMessage($msgtxt, 'Success', '/\/profile\/account/', DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox', TRUE);
			$session->save_message($message);				
		}
		

		header("Location: /profile");
		exit();		

	}

}
else{
	$evr_event_registrant_id = LibraryFunctions::fetch_variable('evr_event_registrant_id', NULL, 1, 'You must provide a registrant.');

	if(EventRegistrant::check_if_exists($evr_event_registrant_id)){
		$event_registrant = new EventRegistrant($evr_event_registrant_id, true);
		$user = new User($event_registrant->get('evr_usr_user_id'), TRUE);
		$event = new Event($event_registrant->get('evr_evt_event_id'),true);
	}
	else{
		$event_registrant = NULL;
	}
	
}
	
?>
