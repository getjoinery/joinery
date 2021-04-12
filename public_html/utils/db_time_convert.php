<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');


	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_sessions_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_registrants_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/address_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/log_form_errors_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/emails_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/email_recipients_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_logs_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/orders_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/messages_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();
	
	

	$event_sessions = new MultiEventSessions(array('event_id', $event->key));
	$event_sessions->load();
	foreach ($event_sessions as $event_session){
		$event = new Event($event_session->get('evs_evt_event_id'), TRUE);
		echo $event_session->key. ' - ';
		echo '<br>';
		if($event_session->get('evs_start_time')){
			$event_session->set('evs_start_time_local', LibraryFunctions::convert_time($event_session->get('evs_start_time'), 'UTC', $event->get('evt_timezone')));
		}
		if($event_session->get('evs_end_time')){
			$event_session->set('evs_end_time_local', LibraryFunctions::convert_time($event_session->get('evs_end_time'), 'UTC', $event->get('evt_timezone')));
		}
		$event_session->save();
	}

	echo 'done';





?>
