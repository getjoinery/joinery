<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function event_sessions_course_logic($get_vars, $post_vars){
	require_once(PathHelper::getIncludePath('includes/Activation.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/SessionControl.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/videos_class.php'));
	require_once(PathHelper::getIncludePath('data/events_class.php'));
	require_once(PathHelper::getIncludePath('data/event_registrants_class.php'));
	require_once(PathHelper::getIncludePath('data/event_sessions_class.php'));
	require_once(PathHelper::getIncludePath('data/files_class.php'));
	require_once(PathHelper::getIncludePath('data/locations_class.php'));

	$settings = Globalvars::get_instance();
	$session = SessionControl::get_instance();
	$page_vars['settings'] = $settings;
	if(!$settings->get_setting('events_active')){
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();
	}

	//ACCEPT EITHER VARIABLE
	if($get_vars['evt_event_id']){
		$event_id = LibraryFunctions::fetch_variable_local($get_vars, 'evt_event_id', 0, 'required', 'Event id is required.', 'safemode', 'int');
	}
	else if ($get_vars['event_id']){
		$event_id = LibraryFunctions::fetch_variable_local($get_vars, 'event_id', 0, 'required', 'Event id is required.', 'safemode', 'int');
	}
	else{
		require_once(LibraryFunctions::display_404_page());
	}

	$event = new Event($event_id, TRUE);
	$page_vars['event'] = $event;
	if($event->get('evt_session_display_type') != 2){
		//REDIRECT
		return LogicResult::redirect('/profile/event_sessions?evt_event_id='. $event->key);
	}

	if ($event && $session->get_user_id() && $session->get_permission() > 4) {
		//SHOW IT EVEN IF UNPUBLISHED OR DELETED
	}
	else {
		if($event->get('evt_visibility') == Event::VISIBILITY_PRIVATE || $event->get('evt_delete_time')){
			require_once(LibraryFunctions::display_404_page());
		}
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

	$event_registrant = EventRegistrant::check_if_registrant_exists($user->key, $event->key);

	if($_SESSION['permission'] < 5 && !$event_registrant){
		$error_message = '<p><strong>You are not registered for this event or your registration has expired, so you cannot access the event materials.</strong></p>
		<p><strong><a href="'.$event->get_url().'">Register for the event here</a>.</strong></p>';
	}
	else if($_SESSION['permission'] < 5 && $event_registrant->get('evr_expires_time') && $event_registrant->get('evr_expires_time') < date("Y-m-d H:i:s")){
		$page_vars['error_message'] = '		<a class="back-link" href="/profile/profile">Back to My Profile</a>
		<p><strong>Your registration has expired, so you cannot access the event materials.</strong></p>
		<p><strong><a href="'.$event->get_url().'">Register for the event here</a>.</strong></p>';
	}
	else{
		$event_session->record_analytic($session->get_user_id());
	}

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
