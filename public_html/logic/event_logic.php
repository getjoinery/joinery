<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/PublicPage.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublic.php');

	require_once($_SERVER['DOCUMENT_ROOT'].'/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/event_sessions_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/event_registrants_class.php');

	$session = SessionControl::get_instance();
	
	$event = Event::get_by_link($static_routes_path);
	if(!$event || !$event->get('evt_visibility')){
		require_once(LibraryFunctions::display_404_page());				
	}
	
	$time = NULL;
	$tz = $event->get('evt_timezone');
	if($event->get_event_start_time($tz)){
		$time = $event->get_event_start_time($tz);
	}
	if($event->get_event_end_time($tz)){
		$time .= ' - ' . $event->get_event_end_time($tz);
	}
		
	$time_user = NULL;
	if($event->get('evt_timezone') != $session->get_timezone()){
		if($event->get_event_start_time($session->get_timezone())){
			$time_user = $event->get_event_start_time($session->get_timezone());
		}
		if($event->get_event_end_time($session->get_timezone())){
			$time_user .= ' - ' . $event->get_event_end_time($session->get_timezone());
		}
	}			
		
	//FIGURE OUT WHETHER THE USER CAN REGISTER OR NOT, WHAT ARE THE OPTIONS
	$registrants = new MultiEventRegistrant(
		array('event_id'=>$event->key)
	);
	$numregistrants = $registrants->count_all();
	
	$registration_message = '';
	$view_course_link = '';
	$register_link = '';
	$waiting_list_link ='';
	$if_registered_message ='';
	$is_registered = 0;
	if($session->get_user_id()){
		$is_registered = EventRegistrant::check_if_registrant_exists($session->get_user_id(), $event->key);
	}
					
	if($is_registered){
		$view_course_link = '/profile/event_sessions_course?event_id='.$event->key;
	}
	else{
		if($event->get('evt_status') == Event::STATUS_COMPLETED){
			$registration_message = 'This event is complete.';
		}	
		else if($event->get('evt_status') == Event::STATUS_CANCELLED){
			$registration_message = 'This event has been cancelled.';
		}						
		else if($event->get('evt_is_accepting_signups') && $event->get_register_url()){		
			if($event->get('evt_allow_waiting_list') && ($event->get('evt_max_signups') && $numregistrants >= $event->get('evt_max_signups'))){
				$registration_message = 'Registration is full, but you may add yourself to the waiting list.';
				$waiting_list_link = '/event_waiting_list?event_id='.$event->key;
			}
			else if($event->get('evt_max_signups') && $numregistrants >= $event->get('evt_max_signups')){
				$registration_message = 'This event is full.';
			}
			else{
				$register_link = $event->get_register_url();		
			}
		}
		else if($event->get('evt_allow_waiting_list')){
				$registration_message = 'Registration is not open yet, but you may add yourself to the waiting list.';
				$waiting_list_link = '/event_waiting_list?event_id='.$event->key;
		}
		else{
			$registration_message = 'There is no registration for this event at this time.';
		}
	
		if($numregistrants){
			if($event->get('evt_session_display_type') == Event::DISPLAY_SEPARATE){
				$if_registered_message = 'If you are registered, you can access the course link, info, videos, and materials <a href="/profile/event_sessions_course?event_id='.$event->key.'">in the my profile section of the website</a>.';
			}
			else{
				$if_registered_message = 'If you are registered, you can access the course link, info, videos, and materials <a href="/profile/event_sessions?evt_event_id='.$event->key.'">in the my profile section of the website</a>.';							
			}
		}
	}


	//CHECK FOR SESSIONS
	if($event->get('evt_session_display_type')== Event::DISPLAY_SEPARATE){
		$searches = array();
		$searches['event_id'] = $event->key;
		$event_sessions = new MultiEventSessions($searches,
			array('time_then_session_number'=>'ASC')); 
		$event_sessions->load();	
		$numsessions = $event_sessions->count_all();

	}
	else{
		$searches = array();
		$searches['event_id'] = $event->key;
		$searches['future'] = 'now()';
		$future_event_sessions = new MultiEventSessions($searches,
			array('time_then_session_number'=>'DESC')); 
		$future_event_sessions->load();	
		$future_numsessions = $future_event_sessions->count_all();
	
		$searches = array();
		$searches['event_id'] = $event->key;
		$searches['past'] = 'now()';
		$past_event_sessions = new MultiEventSessions($searches,
			array('time_then_session_number'=>'DESC'));
		$past_numsessions = $past_event_sessions->count_all();
		$past_event_sessions->load();	
		
	}

?>

