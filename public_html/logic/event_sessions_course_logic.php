<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/videos_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_registrants_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_sessions_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/files_class.php');

	$settings = Globalvars::get_instance();
	if(!$settings->get_setting('events_active')){
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();
	}
	
	$event_id = LibraryFunctions::fetch_variable('event_id', '', TRUE);
	$session_number = (int)LibraryFunctions::fetch_variable('session_number', 0, False, '');
	
	$event = new Event($event_id, TRUE);

	if($event->get('evt_session_display_type') != 2){
		//REDIRECT
		LibraryFunctions::redirect('/profile/event_sessions?evt_event_id='.$event->key);						
		exit();
	}

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$session->set_return();

		
	
	$user = new User($session->get_user_id(), TRUE);
	
	//GET THE SESSION ID
	$searches = array();
	$searches['event_id'] = $event_id;
	$searches['session_number'] = $session_number;
	$event_sessions = new MultiEventSessions(
		$searches,
		NULL,
		NULL,
		NULL,
		'AND'
	);
		
	if(!$event_sessions->count_all()){
		//GET THE LOWEST SESSION NUMBER
		$searches = array();
		$searches['event_id'] = $event_id;
		$event_sessions = new MultiEventSessions(
			$searches,
			array('session_number_then_title'=>'ASC'),
			NULL,
			NULL,
			'AND'
		);
	}
	
	if(!$event_sessions->count_all()){	
		echo 'There are no sessions.';
		exit();
	}
	
	$event_sessions->load();
	$event_session = $event_sessions->get(0);	

	$event = new Event($event_session->get('evs_evt_event_id'), TRUE);	
	
	//ALL SESSIONS FOR THIS EVENT
	$searches = array();
	$searches['event_id'] = $event->key;
	
	$event_sessions = new MultiEventSessions($searches,
		array('session_number'=>'ASC'));
	$event_sessions->load();	
	$numsessions = $event_sessions->count_all();	
	

	
	if($event_session->get('evs_vid_video_id')){
		$video = new Video($event_session->get('evs_vid_video_id'), TRUE);
	}
	else{
		$video = new Video(NULL);
	}	
	
	//CHECK THAT THE USER IS A REGISTRANT
	$searches['user_id'] = $user->key;
	$searches['event_id'] = $event_id;
	$event_registrations = new MultiEventRegistrant(
		$searches,
		NULL, //array('event_id'=>'DESC'),
		NULL,
		NULL);	
	
	$event_registrations->load();
	
	foreach($event_registrations as $event_registrant){
		if($event_registrant->get('evr_expires_time') && $event_registrant->get('evr_expires_time') < date("Y-m-d H:i:s")){
			$event_registrant->remove();
			//REFRESH THE PAGE
			LibraryFunctions::Redirect($_SERVER['REQUEST_URI']); 
		}
	}
		
	if($_SESSION['permission'] < 5 && !$event_registrations->count_all()){
		$error_message = '<p><strong>You are not registered for this event or your registration has expired, so you cannot access the event materials.</strong></p>	
		<p><strong><a href="'.$event->get_url().'">Register for the event here</a>.</strong></p>';
	} 
	else{
		$event_session->record_analytic($session->get_user_id());
	}

	
?>
