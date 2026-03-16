<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function event_logic($get_vars, $post_vars, $event, $instance_date = null){
	require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/events_class.php'));
	require_once(PathHelper::getIncludePath('data/event_sessions_class.php'));
	require_once(PathHelper::getIncludePath('data/event_registrants_class.php'));
	require_once(PathHelper::getIncludePath('data/event_waiting_lists_class.php'));
	require_once(PathHelper::getIncludePath('data/locations_class.php'));

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;
	$page_vars['is_virtual'] = false;

	if(!$event){
		require_once(LibraryFunctions::display_404_page());
	}

	// Handle recurring event instance resolution
	if ($instance_date && $event->is_recurring_parent()) {
		// Check if a materialized instance exists for this date
		$materialized = $event->_get_materialized_instance_for_date($instance_date);
		if ($materialized) {
			// Use the materialized instance as the event
			$event = $materialized;
		} else if ($event->date_matches_pattern($instance_date)) {
			// Create a virtual instance for display
			$virtual = $event->create_virtual_instance($instance_date);
			$page_vars['event'] = $virtual;
			$page_vars['is_virtual'] = true;
			$page_vars['registration_message'] = 'Registration is not yet open for this date.';
			$page_vars['view_course_link'] = '';
			$page_vars['register_link'] = '';
			$page_vars['waiting_list_link'] = '';
			$page_vars['if_registered_message'] = '';
			$page_vars['is_registered'] = 0;
			$page_vars['register_urls'] = array();
			$page_vars['show_sessions_block'] = false;
			$page_vars['location_string'] = $virtual->evt_location;

			if ($virtual->evt_loc_location_id) {
				$location = new Location($virtual->evt_loc_location_id, true);
				$page_vars['location_object'] = $location;
				if ($location->get('loc_fil_file_id')) {
					$file = new File($location->get('loc_fil_file_id'), true);
					$page_vars['location_picture'] = $file->get_url('content','full');
				}
			} else {
				$page_vars['location_object'] = null;
				$page_vars['location_picture'] = null;
			}

			return LogicResult::render($page_vars);
		} else {
			// Date doesn't match pattern — 404
			require_once(LibraryFunctions::display_404_page());
		}
	} else if ($instance_date && !$event->is_recurring_parent()) {
		// Slug belongs to a non-recurring event but date was provided — 404
		require_once(LibraryFunctions::display_404_page());
	} else if (!$instance_date && $event->is_recurring_parent()) {
		// Bare parent URL — redirect to next upcoming instance
		$next_dates = $event->compute_occurrence_dates(date('Y-m-d'), 1);
		if (!empty($next_dates)) {
			return LogicResult::redirect('/event/' . $event->get('evt_link') . '/' . $next_dates[0]);
		}
		// No upcoming instances — 404
		require_once(LibraryFunctions::display_404_page());
	}

	$page_vars['event'] = $event;
	if(!$event->get('evt_visibility') || $event->get('evt_delete_time')){
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
	
	$register_url = $event->get_register_url();
	if($is_registered){
		if($register_url){
			$register_urls[] = array('label' => 'Make a payment', 'link' => $register_url);
		}
		$register_urls[] = array('label' => 'View Course', 'link' => '/profile/event_sessions_course?event_id='.$event->key);
	}
	else{
		if($event->get('evt_status') == Event::STATUS_COMPLETED){
			$registration_message = 'This event is complete.';
		}	
		else if($event->get('evt_status') == Event::STATUS_CANCELED){
			$registration_message = 'This event has been cancelled.';
		}						
		else if($event->get('evt_is_accepting_signups') && $register_url){	
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
				$register_urls[] = array('label' => 'Register Now', 'link' => $register_url);
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
			array('evs_start_time'=>'ASC', 'evs_session_number'=>'ASC')); 
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
			array('evs_start_time'=>'ASC', 'evs_session_number'=>'ASC')); 
		$future_event_sessions->load();	
		$page_vars['future_event_sessions'] = $future_event_sessions;
		$future_numsessions = $future_event_sessions->count_all();
		$page_vars['future_numsessions'] = $future_numsessions;
	
		$searches = array();
		$searches['event_id'] = $event->key;
		$searches['past'] = 'now()';
		$past_event_sessions = new MultiEventSessions($searches,
			array('evs_start_time'=>'DESC', 'evs_session_number'=>'DESC'));
		$past_numsessions = $past_event_sessions->count_all();
		$page_vars['past_numsessions'] = $past_numsessions;
		$past_event_sessions->load();
		$page_vars['past_event_sessions'] = $past_event_sessions;		
	}
	
	$page_vars['show_sessions_block'] = false;
	if(($event->get('evt_session_display_type') == Event::DISPLAY_SEPARATE && $numsessions > 0) || $future_numsessions || $past_numsessions){
		$page_vars['show_sessions_block'] = true;
	}
	
	$page_vars['location_object'] = null;
	$page_vars['location_picture'] = null;
	if($event->get('evt_loc_location_id')){
		$location = new Location($event->get('evt_loc_location_id'), true);
		$page_vars['location_object'] = $location;
		if($location->get('loc_fil_file_id')){
			$file = new File($location->get('loc_fil_file_id'), true);
			$page_vars['location_picture'] = $file->get_url('content','full');
		}
	}
	else{
		$page_vars['location_string'] = $event->get('evt_location');
	}
	

	return LogicResult::render($page_vars);
}
?>

