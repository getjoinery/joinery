<?php

function event_sessions_course_logic($get_vars, $post_vars){
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
	$page_vars['settings'] = $settings;
	if(!$settings->get_setting('events_active')){
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();
	}
	
	if($get_vars['event_id']){
		$event = new Event($get_vars['event_id'], TRUE);
		$event->remove_expired_registrants();
		$page_vars['event'] = $event;
		if($event->get('evt_session_display_type') != 2){
			//REDIRECT
			LibraryFunctions::redirect('/profile/event_sessions?evt_event_id='. $event->key);						
			exit();
		}
	}
	else{
		throw new SystemDisplayablePermanentError("This event does not exist.");
		exit;
	}

	if(isset($get_vars['session_number'])){
		$session_number = (int)$get_vars['session_number'];
	}
	else{
		$session_number = $event->get_lowest_session_number();
	}	

	$page_vars['session_number'] = $session_number;

	$event_session = EventSession::GetBySessionNumber($event->key, $session_number);
	$page_vars['event_session'] = $event_session;

	if($event_session->get('evs_vid_video_id')){
		$page_vars['video'] = new Video($event_session->get('evs_vid_video_id'), TRUE);
	}
	else{
		$page_vars['video'] = new Video(NULL);
	}


	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;
	$session->check_permission(0);
	$session->set_return();

		
	
	$user = new User($session->get_user_id(), TRUE);
	$page_vars['user'] = $user;
		
	
	//ALL SESSIONS FOR THIS EVENT
	$searches = array();
	$searches['event_id'] = $event->key;
	$searches['deleted'] = false;
	$event_sessions = new MultiEventSessions($searches,
		array('session_number'=>'ASC'));
	$event_sessions->load();
	$page_vars['event_sessions'] = $event_sessions;	
	$numsessions = $event_sessions->count_all();	
	$page_vars['numsessions'] = $numsessions;	
	


		
	if($_SESSION['permission'] < 5 && !$page_vars['event_registrant'] = EventRegistrant::check_if_registrant_exists($user->key, $event->key)){
		$error_message = '<p><strong>You are not registered for this event or your registration has expired, so you cannot access the event materials.</strong></p>	
		<p><strong><a href="'.$event->get_url().'">Register for the event here</a>.</strong></p>';
	} 
	else{
		$event_session->record_analytic($session->get_user_id());
	}

	return $page_vars;
}
?>
