<?php

function event_sessions_logic($get_vars, $post_vars){
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Pager.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/videos_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_registrants_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_sessions_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/files_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/locations_class.php');
	

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
	
	//ACCEPT EITHER VARIABLE
	if($get_vars['evt_event_id']){	
		$event_id = $get_vars['evt_event_id'];
	}
	else if ($get_vars['event_id']){
		$event_id = $get_vars['event_id'];
	}
	else{
		throw new SystemDisplayablePermanentError("This event does not exist.");
		exit;
	}
			
	$event = new Event($event_id, TRUE);
	$page_vars['event'] = $event;
	if($event->get('evt_session_display_type') == 2){
		//REDIRECT
		LibraryFunctions::redirect('/profile/event_sessions_course?event_id='.$event->key);						
		exit();
	}

	if ($event && $session->get_user_id() && $session->get_permission() > 4) {
		//SHOW IT EVEN IF UNPUBLISHED OR DELETED
	}
	else {
		if(!$event->get('evt_visibility') == Event::VISIBILITY_PRIVATE || $event->get('evt_delete_time')){
			require_once(LibraryFunctions::display_404_page());		
		}
	}
	

	
	$user = new User($session->get_user_id(), TRUE);
	$page_vars['user'] = $user;

	
	$event_registrant = EventRegistrant::check_if_registrant_exists($user->key, $event->key);
	
	if($_SESSION['permission'] < 5 && !$event_registrant){	
		$page_vars['error_message'] = '		<a class="back-link" href="/profile/profile">Back to My Profile</a>
		<p><strong>You are not registered for this event, so you cannot access the event materials.</strong></p>	
		<p><strong><a href="'.$event->get_url().'">Register for the event here</a>.</strong></p>';
	} 
	
	if($_SESSION['permission'] < 5 && $event_registrant){
		if($event_registrant->get('evr_expires_time') && $event_registrant->get('evr_expires_time') < date("Y-m-d H:i:s")){
		$page_vars['error_message'] = '		<a class="back-link" href="/profile/profile">Back to My Profile</a>
		<p><strong>Your registration has expired, so you cannot access the event materials.</strong></p>	
		<p><strong><a href="'.$event->get_url().'">Register for the event here</a>.</strong></p>';
		}
	}
	
	$page_vars['event_registrant'] = $event_registrant;

	$next_session = $event->get_next_session();
	$page_vars['next_session'] = $next_session;

	$searches = array();
	if($get_vars['offset']){
		$offset = $get_vars['offset'];
	}
	else{
		$offset = 0;
	}

	
	$searches['deleted'] = FALSE;
	$searches['visibility'] = 1;
	$searches['event_id'] = $event->key;
	$numperpage = 5;
	$sort = 'start_time';
	$sdirection = 'DESC';	
	
	$page_vars['numperpage'] = $numperpage;

	$event_sessions = new MultiEventSessions(
		$searches,
		array($sort=>$sdirection),
		$numperpage,
		$offset,
		'AND');


	$num_sessions = $event_sessions->count_all();
	$event_sessions->load();	
	$page_vars['event_sessions'] = $event_sessions;
	
	if($event->get('evt_loc_location_id')){
		$location = new Location($event->get('evt_loc_location_id'), true);
		$page_vars['location_object'] = $location;
		if($location->get('loc_fil_file_id')){
			$file = new File($location->get('loc_fil_file_id'), true);
			$page_vars['location_picture'] = $file->get_url('small','full');
		}
	}
	else{
		$page_vars['location_string'] = $event->get('evt_location');
	}
	
	$page_vars['pager'] = new Pager(array('numrecords'=>$num_sessions, 'numperpage'=> $numperpage));
		
	return $page_vars;
}
?>
