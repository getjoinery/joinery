<?php

function admin_event_edit_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/FormWriterV2Base.php'));
	require_once(PathHelper::getIncludePath('plugins/event_manager/data/events_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
	require_once(PathHelper::getIncludePath('data/files_class.php'));
	require_once(PathHelper::getIncludePath('plugins/event_manager/data/event_types_class.php'));
	require_once(PathHelper::getIncludePath('data/surveys_class.php'));
	require_once(PathHelper::getIncludePath('plugins/event_manager/data/locations_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	// Detect virtual instance editing (parent_event_id + instance_date)
	$parent_event_id = isset($input['parent_event_id']) ? $input['parent_event_id'] : (isset($input['parent_event_id']) ? $input['parent_event_id'] : null);
	$instance_date = isset($input['instance_date']) ? $input['instance_date'] : (isset($input['instance_date']) ? $input['instance_date'] : null);
	$is_virtual_edit = ($parent_event_id && $instance_date);

	// Load or create event
	// CRITICAL: Check edit_primary_key_value (form submission) first, fallback to GET
	if (isset($input['edit_primary_key_value']) && $input['edit_primary_key_value']) {
		$event = new Event($input['edit_primary_key_value'], TRUE);
	} elseif (isset($input['evt_event_id'])) {
		$event = new Event($input['evt_event_id'], TRUE);
	} elseif ($is_virtual_edit && !LibraryFunctions::isFormSubmission()) {
		// GET request for editing a virtual instance: pre-populate from parent
		$parent = new Event($parent_event_id, TRUE);
		$parent->assert_can_write($session);

		$event = new Event(NULL);
		foreach (Event::$field_specifications as $field => $spec) {
			if (in_array($field, Event::RECURRENCE_FIELDS)) continue;
			if ($field === 'evt_event_id' || $field === 'evt_create_time' || $field === 'evt_delete_time') continue;
			$event->set($field, $parent->get($field));
		}
		foreach (Event::RECURRENCE_FIELDS as $rf) {
			$event->set($rf, null);
		}

		// Adjust start/end times for the instance date
		if ($parent->get('evt_start_time')) {
			$tz = $parent->get('evt_timezone') ?: 'America/New_York';
			$event_tz = new DateTimeZone($tz);
			$utc_tz = new DateTimeZone('UTC');

			$parent_start = new DateTime($parent->get('evt_start_time'), $utc_tz);
			$parent_start->setTimezone($event_tz);
			$parent_local_date = $parent_start->format('Y-m-d');
			$parent_local_time = $parent_start->format('H:i:s');

			$inst_start = new DateTime($instance_date . ' ' . $parent_local_time, $event_tz);
			$inst_start->setTimezone($utc_tz);
			$event->set('evt_start_time', $inst_start->format('Y-m-d H:i:s'));

			if ($parent->get('evt_end_time')) {
				$parent_end = new DateTime($parent->get('evt_end_time'), $utc_tz);
				$parent_end->setTimezone($event_tz);
				$day_diff = (new DateTime($parent_local_date))->diff(new DateTime($parent_end->format('Y-m-d')))->days;
				$end_date = new DateTime($instance_date);
				if ($day_diff > 0) {
					$end_date->modify('+' . $day_diff . ' days');
				}
				$inst_end = new DateTime($end_date->format('Y-m-d') . ' ' . $parent_end->format('H:i:s'), $event_tz);
				$inst_end->setTimezone($utc_tz);
				$event->set('evt_end_time', $inst_end->format('Y-m-d H:i:s'));
			}
		}
	} else {
		$event = new Event(NULL);
		$event->set('evt_timezone', 'America/New_York');
		$event->set('evt_visibility', 1); // Live
		$event->set('evt_allow_waiting_list', 0); // Off by default
	}

	// Process POST actions
	if(LibraryFunctions::isFormSubmission()){

		// If editing a virtual instance, materialize it first
		if ($is_virtual_edit && !$input['edit_primary_key_value']) {
			$parent = new Event($parent_event_id, TRUE);
			$parent->assert_can_write($session);
			$event = $parent->materialize_instance($instance_date);
		}

		if($input['evt_short_description']){
				$input['evt_short_description'] = $input['evt_short_description'];
		}

		if($input['evt_description']){
				$input['evt_description'] = $input['evt_description'];
		}

		if($input['evt_fil_file_id']){
			$event->set('evt_fil_file_id', (int)$input['evt_fil_file_id']);
		}
		else if(empty($input['evt_fil_file_id'])){
			$event->set('evt_fil_file_id', NULL);
		}

		if($input['evt_usr_user_id_leader']){
			$event->set('evt_usr_user_id_leader', $input['evt_usr_user_id_leader']);
		}
		else{
			$event->set('evt_usr_user_id_leader', NULL);
		}

		if($input['evt_max_signups'] == '' || $input['evt_max_signups'] == 0 || $input['evt_max_signups'] == NULL){
			$event->set('evt_max_signups', NULL);
		}
		else{
			$event->set('evt_max_signups', (int)$input['evt_max_signups']);
		}

		if($input['evt_is_accepting_signups'] && !$input['evt_external_register_link']){
			//CHECK THAT THERE IS AN ASSOCIATED PRODUCT
			$products = new MultiProduct(array('fulfillment_provider' => 'event_registration', 'fulfillment_ref' => $event->key));
			$numproducts = $products->count_all();
			if(!$numproducts){
				$input['evt_is_accepting_signups'] = 0;
			}
		}

		if($input['evt_loc_location_id'] == ''){
			$input['evt_loc_location_id'] = NULL;
		}

		// Capture local time from the form (event timezone — label tells user this).
		// Event::save() derives UTC from local + evt_timezone; session TZ is not used.
		$start_time = FormWriterV2Base::process_datetimeinput($input, 'evt_start_time', false);
		if($start_time !== NULL){
			$event->set('evt_start_time_local', $start_time);
		}

		$end_time = FormWriterV2Base::process_datetimeinput($input, 'evt_end_time', false);
		if($end_time !== NULL){
			$event->set('evt_end_time_local', $end_time);
		}

		// Handle recurrence fields (only if not editing a materialized instance)
		if (!$event->is_instance()) {
			$rec_type = isset($input['evt_recurrence_type']) ? $input['evt_recurrence_type'] : '';
			if ($rec_type === '') {
				// "None" selected — clear all recurrence fields
				$event->set('evt_recurrence_type', NULL);
				$event->set('evt_recurrence_interval', 1);
				$event->set('evt_recurrence_days_of_week', NULL);
				$event->set('evt_recurrence_week_of_month', NULL);
				$event->set('evt_recurrence_end_date', NULL);
			} else {
				$event->set('evt_recurrence_type', $rec_type);
				$event->set('evt_recurrence_interval', max(1, (int)($input['evt_recurrence_interval'] ?? 1)));

				// Days of week (weekly only)
				if ($rec_type === 'weekly') {
					$selected_days = [];
					for ($i = 0; $i <= 6; $i++) {
						if (isset($input['recurrence_dow_' . $i])) {
							$selected_days[] = $i;
						}
					}
					$event->set('evt_recurrence_days_of_week', !empty($selected_days) ? implode(',', $selected_days) : NULL);
				} else {
					$event->set('evt_recurrence_days_of_week', NULL);
				}

				// Week of month (monthly only)
				if ($rec_type === 'monthly' && isset($input['monthly_type']) && $input['monthly_type'] === 'by_week') {
					$wom = isset($input['evt_recurrence_week_of_month']) ? (int)$input['evt_recurrence_week_of_month'] : NULL;
					$event->set('evt_recurrence_week_of_month', $wom);
				} else {
					$event->set('evt_recurrence_week_of_month', NULL);
				}

				// End date
				if (isset($input['recurrence_end_type']) && $input['recurrence_end_type'] === 'on_date' && !empty($input['evt_recurrence_end_date'])) {
					$event->set('evt_recurrence_end_date', $input['evt_recurrence_end_date']);
				} else {
					$event->set('evt_recurrence_end_date', NULL);
				}
			}
		}

		$editable_fields = array('evt_name', 'evt_description', 'evt_private_info', 'evt_short_description', 'evt_location', 'evt_external_register_link', 'evt_is_accepting_signups', 'evt_visibility', 'evt_timezone', 'evt_picture_link', 'evt_status', 'evt_allow_waiting_list', 'evt_session_display_type', 'evt_show_add_to_calendar_link', 'evt_ety_event_type_id', 'evt_svy_survey_id', 'evt_survey_required','evt_loc_location_id', 'evt_custom_registration_message');
		$integer_fields = array('evt_ety_event_type_id', 'evt_svy_survey_id', 'evt_loc_location_id');

		foreach($editable_fields as $field) {
			$value = $input[$field];
			// Convert empty strings to NULL for integer fields
			if(in_array($field, $integer_fields) && $value === '') {
				$value = NULL;
			}
			$event->set($field, $value);
		}

		if(!$event->get('evt_link') || $_SESSION['permission'] == 10){
			if($input['evt_link']){
				$event->set('evt_link', $event->create_url($input['evt_link']));
			}
			else{
				$event->set('evt_link', $event->create_url($event->get('evt_name')));
			}
		}

		$event->prepare();
		$event->save();
		$event->load();

		return LogicResult::redirect('/plugins/event_manager/admin/admin_event?evt_event_id='.$event->key);
	}

	// Load data for display
	$breadcrumbs = array('Events'=>'/plugins/event_manager/admin/admin_events');
	if ($event->key) {
		$breadcrumbs += array('Event '.$event->get('evt_name') => '/plugins/event_manager/admin/admin_event?evt_event_id='.$event->key);
		$breadcrumbs += array('Event Edit'=>'');
	}
	else{
		$breadcrumbs += array('New Event' => '');
	}

	$title = $event->get('evt_name');
	$content = $event->get('evt_description');
	//LOAD THE ALTERNATE CONTENT VERSION IF NEEDED
	if($input['cnv_content_version_id']){
		$content_version = new ContentVersion($input['cnv_content_version_id'], TRUE);
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
		'parent_event_id' => $parent_event_id,
		'instance_date' => $instance_date,
	));
}

?>
