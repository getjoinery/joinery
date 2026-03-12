<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');

	require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/events_class.php'));
	require_once(PathHelper::getIncludePath('data/event_registrants_class.php'));

	$settings = Globalvars::get_instance();
	if(!$settings->get_setting('events_active')){
		return LogicResult::error('This feature is turned off');
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

		return LogicResult::redirect('/profile');

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
