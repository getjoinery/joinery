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
	
	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$session->set_return();
	
	$event_id = LibraryFunctions::fetch_variable('evt_event_id', '', TRUE);
	$show_all = LibraryFunctions::fetch_variable('show_all', 0, FALSE);
	if($show_all){
		$limit = NULL;
	}
	else{
		$limit = 5;
	}
	

	
	$user = new User($session->get_user_id(), TRUE);
	$event = new Event($event_id, TRUE);
	
	if($event->get('evt_session_display_type') == 2){
		//REDIRECT
		LibraryFunctions::redirect('/profile/event_sessions_course?event_id='.$event->key);						
		exit();
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
		
	if(!$event_registrations->count_all()){
		
		$error_message = '		<a class="back-link" href="/profile/profile">Back to My Profile</a>
		
		<p><strong>You are not registered for this event or your registration has expired, so you cannot access the event materials.</strong></p>
		
		<p><strong><a href="'.$event->get_url().'">Register for the event here</a>.</strong></p>';
		
	} 


	$next_session = $event->get_next_session();
	
	$psearches = array();
	$psearches['event_id'] = $event->key;
	$event_sessions = new MultiEventSessions($psearches,
		array('start_time'=>'DESC'), $limit,
	0);
	$num_sessions = $event_sessions->count_all();
	$event_sessions->load();	
		
?>
