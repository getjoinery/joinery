<?php

function event_logic($get_vars, $post_vars, $static_routes_path){
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'].'/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/event_sessions_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/event_registrants_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/event_waiting_lists_class.php');

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;
	
	$event = Event::get_by_link($static_routes_path);
	$page_vars['event'] = $event;
	if(!$event || !$event->get('evt_visibility')){
		if($session->get_permission() < 5){
			require_once(LibraryFunctions::display_404_page());	
		}			
	}

		
	//FIGURE OUT WHETHER THE USER CAN REGISTER OR NOT, WHAT ARE THE OPTIONS
	$registrants = new MultiEventRegistrant(
		array('event_id'=>$event->key, 'expired' => false)
	);
	$numregistrants = $registrants->count_all();
	
	$registration_message = '';
	$view_course_link = '';
	$register_link = '';
	$waiting_list_link ='';
	$if_registered_message ='';
	$is_registered = 0;
	$register_urls = array();
	$on_waiting_list = WaitingList::CheckIfExists($session->get_user_id(), $event->key);
	if($session->get_user_id()){
		$is_registered = EventRegistrant::check_if_registrant_exists($session->get_user_id(), $event->key);
	}
					
	if($is_registered){
		$register_urls[] = array('label' => 'Make a payment', 'link' => $event->get_register_url());
		$register_urls[] = array('label' => 'View Course', 'link' => '/profile/event_sessions_course?event_id='.$event->key);
	}
	else{
		if($event->get('evt_status') == Event::STATUS_COMPLETED){
			$registration_message = 'This event is complete.';
		}	
		else if($event->get('evt_status') == Event::STATUS_CANCELED){
			$registration_message = 'This event has been cancelled.';
		}						
		else if($event->get('evt_is_accepting_signups') && $event->get_register_url()){	
			if($event->get('evt_allow_waiting_list') && ($event->get('evt_max_signups') && $numregistrants >= $event->get('evt_max_signups'))){
				if($session->get_user_id() && $on_waiting_list){
					$registration_message = 'You are on the waiting list.';			
				}
				else{
					$registration_message = 'Registration is full, but you may add yourself to the waiting list.';
					$register_urls[] = array('label' => 'Join Waiting List', 'link' => '/event_waiting_list?event_id='.$event->key);	
				}
			}
			else if($event->get('evt_max_signups') && $numregistrants >= $event->get('evt_max_signups')){
				$registration_message = 'This event is full.';
			}
			else{
				$register_urls[] = array('label' => 'Register Now', 'link' => $event->get_register_url());
			}
		}
		else if($event->get('evt_allow_waiting_list')){
				if($session->get_user_id() && $on_waiting_list){
					$registration_message = 'You are on the waiting list.';			
				}
				else{
					$registration_message = 'Registration is not open yet, but you may add yourself to the waiting list.';
					$register_urls[] = array('label' => 'Join Waiting List', 'link' => '/event_waiting_list?event_id='.$event->key);
				}
		}
		else{
			$registration_message = 'There is no registration for this event at this time.';
		}
	
		if($numregistrants){
			if($event->get('evt_session_display_type') == Event::DISPLAY_SEPARATE){
				$if_registered_message .= 'If you are registered, you can access the course link, info, videos, and materials <a href="/profile/event_sessions_course?event_id='.$event->key.'">in the my profile section of the website</a>.';
			}
			else{
				$if_registered_message .= 'If you are registered, you can access the course link, info, videos, and materials <a href="/profile/event_sessions?evt_event_id='. $event->key .'">in the my profile section of the website</a>.';							
			}
		}
	}
	
	$page_vars['registration_message'] = $registration_message;
	$page_vars['view_course_link'] = $view_course_link;
	$page_vars['register_link'] = $register_link;
	$page_vars['waiting_list_link'] = $waiting_list_link;
	$page_vars['if_registered_message'] = $if_registered_message;
	$page_vars['is_registered'] = $is_registered;
	$page_vars['register_urls'] = $register_urls;

	//CHECK FOR SESSIONS
	if($event->get('evt_session_display_type')== Event::DISPLAY_SEPARATE){
		$searches = array();
		$searches['event_id'] = $event->key;
		$event_sessions = new MultiEventSessions($searches,
			array('time_then_session_number'=>'ASC')); 
		$event_sessions->load();	
		$page_vars['event_sessions'] = $event_sessions;
		$numsessions = $event_sessions->count_all();
		$page_vars['numsessions'] = $numsessions;

	}
	else{

		$searches = array();
		$searches['event_id'] = $event->key;
		$searches['future_or_none'] = true;
		$future_event_sessions = new MultiEventSessions($searches,
			array('time_then_session_number'=>'ASC')); 
		$future_event_sessions->load();	
		$page_vars['future_event_sessions'] = $future_event_sessions;
		$future_numsessions = $future_event_sessions->count_all();
		$page_vars['future_numsessions'] = $future_numsessions;
	
		$searches = array();
		$searches['event_id'] = $event->key;
		$searches['past'] = 'now()';
		$past_event_sessions = new MultiEventSessions($searches,
			array('time_then_session_number'=>'DESC'));
		$past_numsessions = $past_event_sessions->count_all();
		$page_vars['past_numsessions'] = $past_numsessions;
		$past_event_sessions->load();
		$page_vars['past_event_sessions'] = $past_event_sessions;		
	}
	
	$page_vars['show_sessions_block'] = false;
	if(($event->get('evt_session_display_type') == Event::DISPLAY_SEPARATE && $numsessions > 0) || $future_numsessions || $past_numsessions){
		$page_vars['show_sessions_block'] = true;
	}
	

	return $page_vars;
}
?>

