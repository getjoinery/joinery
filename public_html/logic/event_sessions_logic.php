<?php

function event_sessions_logic($get_vars, $post_vars){
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
	
	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;
	$session->check_permission(0);
	$session->set_return();
	
	if($get_vars['event_id']){
		$event = new Event($get_vars['event_id'], TRUE);
		$event->remove_expired_registrants();
		$page_vars['event'] = $event;
		if($event->get('evt_session_display_type') == 2){
			//REDIRECT
			LibraryFunctions::redirect('/profile/event_sessions_course?event_id='.$event->key);						
			exit();
		}
	}
	else{
		throw new SystemDisplayablePermanentError("This event does not exist.");
		exit;
	}
	

	
	$user = new User($session->get_user_id(), TRUE);
	$page_vars['user'] = $user;

	

	
	if(!$page_vars['event_registrant'] = EventRegistrant::check_if_registrant_exists($user->key, $event->key)){	
		$page_vars['error_message'] = '		<a class="back-link" href="/profile/profile">Back to My Profile</a>
		<p><strong>You are not registered for this event or your registration has expired, so you cannot access the event materials.</strong></p>	
		<p><strong><a href="'.$event->get_url().'">Register for the event here</a>.</strong></p>';
	} 


	$next_session = $event->get_next_session();
	$page_vars['next_session'] = $next_session;
	
	$psearches = array();
	$psearches['event_id'] = $event->key;
	if($get_vars['show_all']){
		$limit = NULL;
	}
	else{
		$limit = 5;
	}
	$event_sessions = new MultiEventSessions($psearches,
		array('start_time'=>'DESC'), $limit,
	0);
	$num_sessions = $event_sessions->count_all();
	$event_sessions->load();	
	$page_vars['num_sessions'] = $num_sessions;
	$page_vars['event_sessions'] = $event_sessions;
		
	return $page_vars;
}
?>
