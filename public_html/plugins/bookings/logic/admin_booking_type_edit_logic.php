<?php

/**
 * Admin booking-type create/edit. Most fields render through
 * FormWriter::fromDescriptor() off the descriptor below; the host picker and the
 * location fields (whose detail box is gated on the location mode via
 * visibility_rules) are hand-added in the view. Saving sets the full field set
 * explicitly so hand-added fields persist alongside the descriptor ones.
 */
function admin_booking_type_edit_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('plugins/bookings/data/booking_types_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/surveys_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);

	$pk = $input['edit_primary_key_value'] ?? ($input['bkt_booking_type_id'] ?? null);
	$type = $pk ? new BookingType($pk, TRUE) : new BookingType(NULL);

	if (LibraryFunctions::isFormSubmission()) {
		$strings = ['bkt_name','bkt_slug','bkt_description_plain','bkt_provider','bkt_location_mode',
		            'bkt_location_details','bkt_cancellation_policy_text','bkt_reminder_minutes_csv'];
		foreach ($strings as $f) {
			if (isset($input[$f])) { $type->set($f, trim($input[$f])); }
		}
		$ints = ['bkt_usr_user_id','bkt_svy_survey_id','bkt_pro_product_id','bkt_status','bkt_duration_minutes',
		         'bkt_slot_increment_minutes','bkt_buffer_before_minutes','bkt_buffer_after_minutes',
		         'bkt_min_notice_minutes','bkt_rolling_days','bkt_max_per_day','bkt_max_per_week','bkt_cancel_notice_minutes'];
		foreach ($ints as $f) {
			if (isset($input[$f]) && $input[$f] !== '') { $type->set($f, (int)$input[$f]); }
			elseif (isset($input[$f]) && $input[$f] === '') { $type->set($f, null); }
		}
		foreach (['bkt_window_start','bkt_window_end'] as $f) {
			$type->set($f, (isset($input[$f]) && $input[$f] !== '') ? $input[$f] : null);
		}
		$type->set('bkt_send_native_emails', !empty($input['bkt_send_native_emails']));
		if (!$type->get('bkt_provider')) { $type->set('bkt_provider', 'native'); }
		$type->set('bkt_update_time', gmdate('Y-m-d H:i:s'));

		try {
			$type->prepare();
			$type->save();
		} catch (Exception $e) {
			$page_vars = booking_type_edit_vars($session, $type);
			$page_vars['error'] = $e->getMessage();
			return LogicResult::render($page_vars);
		}
		return LogicResult::redirect('/plugins/bookings/admin/admin_booking_types');
	}

	// Pre-populate sensible defaults on the new-type form so confirmations and
	// reminders work out of the box (the field defaults only apply on INSERT and
	// would otherwise show blank/unchecked in the form).
	if (!$type->key) {
		$type->set('bkt_send_native_emails', true);
		$type->set('bkt_reminder_minutes_csv', '1440,60');
		$type->set('bkt_slot_increment_minutes', 30);
		$type->set('bkt_min_notice_minutes', 240);
		$type->set('bkt_rolling_days', 60);
		$type->set('bkt_status', BookingType::BOOKING_STATUS_ACTIVE);
		$type->set('bkt_provider', 'native');
	}

	return LogicResult::render(booking_type_edit_vars($session, $type));
}

/** Shared view vars: the model, host options, survey options. */
function booking_type_edit_vars($session, $type): array {
	$hosts = array('' => '— select host —');
	$staff = new MultiUser(['permission_range' => [5, 10], 'deleted' => false], ['last_name' => 'ASC']);
	$staff->load();
	foreach ($staff as $u) { $hosts[$u->key] = $u->display_name(); }
	// Ensure the current host is selectable even if not staff.
	if ($type->get('bkt_usr_user_id') && !isset($hosts[$type->get('bkt_usr_user_id')])) {
		$h = new User($type->get('bkt_usr_user_id'), TRUE);
		if ($h->key) { $hosts[$h->key] = $h->display_name(); }
	}

	$surveys = array('' => '— none —');
	$ms = new MultiSurvey(['deleted' => false]);
	$ms->load();
	foreach ($ms as $s) { $surveys[$s->key] = $s->get('svy_name'); }

	return array(
		'session' => $session,
		'type' => $type,
		'host_options' => $hosts,
		'survey_options' => $surveys,
	);
}

/** Descriptor for the straightforward fields (host + location are hand-added). */
function admin_booking_type_edit_logic_descriptor(): array {
	return array(
		'description' => 'Create or update a booking type (admin).',
		'requires_session' => true,
		'mutates' => true,
		'input' => array(
			'edit_primary_key_value' => array('type' => 'int', 'required' => false, 'label' => 'Booking Type ID (omit to create)'),
			'bkt_name' => array('type' => 'string', 'required' => true, 'label' => 'Name'),
			'bkt_slug' => array('type' => 'string', 'required' => true, 'label' => 'URL slug', 'help' => 'Public booking URL: /book/{slug}'),
			'bkt_description_plain' => array('type' => 'text', 'required' => false, 'label' => 'Description'),
			'bkt_status' => array('type' => 'select', 'required' => false, 'label' => 'Status', 'options' => array('1' => 'Active', '0' => 'Inactive')),
			'bkt_duration_minutes' => array('type' => 'int', 'required' => true, 'label' => 'Duration (minutes)'),
			'bkt_slot_increment_minutes' => array('type' => 'int', 'required' => false, 'label' => 'Slot increment (minutes)'),
			'bkt_buffer_before_minutes' => array('type' => 'int', 'required' => false, 'label' => 'Buffer before (minutes)'),
			'bkt_buffer_after_minutes' => array('type' => 'int', 'required' => false, 'label' => 'Buffer after (minutes)'),
			'bkt_min_notice_minutes' => array('type' => 'int', 'required' => false, 'label' => 'Minimum notice (minutes)'),
			'bkt_rolling_days' => array('type' => 'int', 'required' => false, 'label' => 'Rolling window (days ahead)'),
			'bkt_window_start' => array('type' => 'date', 'required' => false, 'label' => 'Fixed window start (optional)'),
			'bkt_window_end' => array('type' => 'date', 'required' => false, 'label' => 'Fixed window end (optional)'),
			'bkt_max_per_day' => array('type' => 'int', 'required' => false, 'label' => 'Max bookings per day (optional)'),
			'bkt_max_per_week' => array('type' => 'int', 'required' => false, 'label' => 'Max bookings per week (optional)'),
			'bkt_cancel_notice_minutes' => array('type' => 'int', 'required' => false, 'label' => 'Invitee cancel/reschedule notice (minutes)'),
			'bkt_cancellation_policy_text' => array('type' => 'text', 'required' => false, 'label' => 'Cancellation policy text'),
			'bkt_reminder_minutes_csv' => array('type' => 'string', 'required' => false, 'label' => 'Reminder offsets (minutes, CSV)', 'help' => 'e.g. 1440,60'),
			'bkt_send_native_emails' => array('type' => 'bool', 'required' => false, 'label' => 'Send native emails (confirmations, reminders)'),
		),
	);
}
