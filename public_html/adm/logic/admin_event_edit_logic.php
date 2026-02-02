<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_event_edit_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/FormWriterV2Base.php'));
	require_once(PathHelper::getIncludePath('data/events_class.php'));
	require_once(PathHelper::getIncludePath('data/products_class.php'));
	require_once(PathHelper::getIncludePath('data/files_class.php'));
	require_once(PathHelper::getIncludePath('data/event_types_class.php'));
	require_once(PathHelper::getIncludePath('data/surveys_class.php'));
	require_once(PathHelper::getIncludePath('data/locations_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	// Load or create event
	if (isset($get_vars['evt_event_id']) || isset($post_vars['evt_event_id'])) {
		$evt_event_id = isset($post_vars['evt_event_id']) ? $post_vars['evt_event_id'] : $get_vars['evt_event_id'];
		$event = new Event($evt_event_id, TRUE);
	} else {
		$event = new Event(NULL);
		$event->set('evt_timezone', 'America/New_York');
		$event->set('evt_visibility', 1); // Live
	}

	// Process POST actions
	if($post_vars){

		if($post_vars['evt_short_description']){
				$post_vars['evt_short_description'] = $post_vars['evt_short_description'];
		}

		if($post_vars['evt_description']){
				$post_vars['evt_description'] = $post_vars['evt_description'];
		}

		if($post_vars['evt_fil_file_id']){
			$event->set('evt_fil_file_id', (int)$post_vars['evt_fil_file_id']);
		}
		else if(empty($post_vars['evt_fil_file_id'])){
			$event->set('evt_fil_file_id', NULL);
		}

		if($post_vars['evt_usr_user_id_leader']){
			$event->set('evt_usr_user_id_leader', $post_vars['evt_usr_user_id_leader']);
		}
		else{
			$event->set('evt_usr_user_id_leader', NULL);
		}

		if($post_vars['evt_after_purchase_message']){
			$event->set('evt_after_purchase_message', $post_vars['evt_after_purchase_message']);
		}

		if($post_vars['evt_max_signups'] == '' || $post_vars['evt_max_signups'] == 0 || $post_vars['evt_max_signups'] == NULL){
			$event->set('evt_max_signups', NULL);
		}
		else{
			$event->set('evt_max_signups', (int)$post_vars['evt_max_signups']);
		}

		if($post_vars['evt_is_accepting_signups'] && !$post_vars['evt_external_register_link']){
			//CHECK THAT THERE IS AN ASSOCIATED PRODUCT
			$products = new MultiProduct(array('event_id'=> $event->key));
			$numproducts = $products->count_all();
			if(!$numproducts){
				$post_vars['evt_is_accepting_signups'] = 0;
			}
		}

		if($post_vars['evt_loc_location_id'] == ''){
			$post_vars['evt_loc_location_id'] = NULL;
		}

		// Handle start time using FormWriterV2Base helper
		$start_time = FormWriterV2Base::process_datetimeinput($post_vars, 'evt_start_time', true);
		if($start_time !== NULL){
			$event->set('evt_start_time', $start_time);
		}

		// Handle end time using FormWriterV2Base helper
		$end_time = FormWriterV2Base::process_datetimeinput($post_vars, 'evt_end_time', true);
		if($end_time !== NULL){
			$event->set('evt_end_time', $end_time);
		}

		$editable_fields = array('evt_name', 'evt_description', 'evt_private_info', 'evt_short_description', 'evt_location', 'evt_external_register_link', 'evt_is_accepting_signups', 'evt_visibility', 'evt_timezone', 'evt_picture_link', 'evt_status', 'evt_allow_waiting_list', 'evt_session_display_type', 'evt_collect_extra_info', 'evt_show_add_to_calendar_link', 'evt_ety_event_type_id', 'evt_svy_survey_id', 'evt_survey_required','evt_loc_location_id');
		$integer_fields = array('evt_ety_event_type_id', 'evt_svy_survey_id', 'evt_loc_location_id');

		foreach($editable_fields as $field) {
			$value = $post_vars[$field];
			// Convert empty strings to NULL for integer fields
			if(in_array($field, $integer_fields) && $value === '') {
				$value = NULL;
			}
			$event->set($field, $value);
		}

		if(!$event->get('evt_link') || $_SESSION['permission'] == 10){
			if($post_vars['evt_link']){
				$event->set('evt_link', $event->create_url($post_vars['evt_link']));
			}
			else{
				$event->set('evt_link', $event->create_url($event->get('evt_name')));
			}
		}

		$event->prepare();
		$event->save();
		$event->load();

		return LogicResult::redirect('/admin/admin_event?evt_event_id='.$event->key);
	}

	// Load data for display
	$breadcrumbs = array('Events'=>'/admin/admin_events');
	if ($event->key) {
		$breadcrumbs += array('Event '.$event->get('evt_name') => '/admin/admin_event?evt_event_id='.$event->key);
		$breadcrumbs += array('Event Edit'=>'');
	}
	else{
		$breadcrumbs += array('New Event' => '');
	}

	$title = $event->get('evt_name');
	$content = $event->get('evt_description');
	//LOAD THE ALTERNATE CONTENT VERSION IF NEEDED
	if($get_vars['cnv_content_version_id']){
		$content_version = new ContentVersion($get_vars['cnv_content_version_id'], TRUE);
		$content = $content_version->get('cnv_content');
		$title = $content_version->get('cnv_title');
	}

	// Load files for dropdown
	$files = new MultiFile(
		array('deleted'=>false, 'picture'=>true),
		array('file_id' => 'DESC'),
		NULL,
		NULL);
	$files->load();

	// Load locations for dropdown
	$locations = new MultiLocation(
		array('deleted'=>false, 'published'=>true),
		array('location_id' => 'ASC'),
		NULL,
		NULL);
	$locations->load();
	$numlocations = $locations->count_all();

	// Load users (leaders) for dropdown
	$users = new MultiGroupMember(
		array('group_id' => 27),
		NULL,
		NULL,
		NULL);
	$users->load();

	//HANDLE DEFAULT timezone
	if($event->get('evt_timezone')){
		$timezone = $event->get('evt_timezone');
	}
	else{
		$settings = Globalvars::get_instance();
		$default_timezone = $settings->get_setting('default_timezone');
		$timezone = $default_timezone;
	}

	// Load event types
	$event_types = new MultiEventType();
	$num_event_types = $event_types->count_all();
	if($num_event_types){
		$event_types->load();
	}

	// Load content versions for sidebar
	$content_versions = new MultiContentVersion(
		array('type'=>ContentVersion::TYPE_EVENT, 'foreign_key_id' => $event->key),
		array('create_time' => 'DESC'),
		NULL,
		NULL);
	$content_versions->load();

	// Return page variables for rendering
	return LogicResult::render(array(
		'event' => $event,
		'breadcrumbs' => $breadcrumbs,
		'title' => $title,
		'content' => $content,
		'files' => $files,
		'locations' => $locations,
		'numlocations' => $numlocations,
		'users' => $users,
		'timezone' => $timezone,
		'event_types' => $event_types,
		'num_event_types' => $num_event_types,
		'content_versions' => $content_versions,
		'session' => $session,
	));
}

?>
