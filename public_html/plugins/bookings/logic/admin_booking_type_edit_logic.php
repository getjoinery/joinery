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

	$pk = $input['edit_primary_key_value'] ?? ($input['bty_booking_type_id'] ?? null);
	$type = $pk ? new BookingType($pk, TRUE) : new BookingType(NULL);

	if (LibraryFunctions::isFormSubmission()) {
		$strings = ['bty_name','bty_slug','bty_description_plain','bty_provider','bty_location_mode',
		            'bty_location_details','bty_cancellation_policy_text','bty_reminder_minutes_csv'];
		foreach ($strings as $f) {
			if (isset($input[$f])) { $type->set($f, trim($input[$f])); }
		}
		$ints = ['bty_usr_user_id','bty_svy_survey_id','bty_pro_product_id','bty_status','bty_duration_minutes',
		         'bty_slot_increment_minutes','bty_buffer_before_minutes','bty_buffer_after_minutes',
		         'bty_min_notice_minutes','bty_rolling_days','bty_max_per_day','bty_max_per_week','bty_cancel_notice_minutes'];
		foreach ($ints as $f) {
			if (isset($input[$f]) && $input[$f] !== '') { $type->set($f, (int)$input[$f]); }
			elseif (isset($input[$f]) && $input[$f] === '') { $type->set($f, null); }
		}
		foreach (['bty_window_start','bty_window_end'] as $f) {
			$type->set($f, (isset($input[$f]) && $input[$f] !== '') ? $input[$f] : null);
		}
		$type->set('bty_send_native_emails', !empty($input['bty_send_native_emails']));
		if (!$type->get('bty_provider')) { $type->set('bty_provider', 'native'); }
		$type->set('bty_update_time', gmdate('Y-m-d H:i:s'));

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
		$type->set('bty_send_native_emails', true);
		$type->set('bty_reminder_minutes_csv', '1440,60');
		$type->set('bty_slot_increment_minutes', 30);
		$type->set('bty_min_notice_minutes', 240);
		$type->set('bty_rolling_days', 60);
		$type->set('bty_status', BookingType::BOOKING_STATUS_ACTIVE);
		$type->set('bty_provider', 'native');
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
	if ($type->get('bty_usr_user_id') && !isset($hosts[$type->get('bty_usr_user_id')])) {
		$h = new User($type->get('bty_usr_user_id'), TRUE);
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
			'bty_name' => array('type' => 'string', 'required' => true, 'label' => 'Name'),
			'bty_slug' => array('type' => 'string', 'required' => true, 'label' => 'URL slug', 'help' => 'Public booking URL: /book/{slug}'),
			'bty_description_plain' => array('type' => 'text', 'required' => false, 'label' => 'Description'),
			'bty_status' => array('type' => 'select', 'required' => false, 'label' => 'Status', 'options' => array('1' => 'Active', '0' => 'Inactive')),
			'bty_duration_minutes' => array('type' => 'int', 'required' => true, 'label' => 'Duration (minutes)'),
			'bty_slot_increment_minutes' => array('type' => 'int', 'required' => false, 'label' => 'Slot increment (minutes)'),
			'bty_buffer_before_minutes' => array('type' => 'int', 'required' => false, 'label' => 'Buffer before (minutes)'),
			'bty_buffer_after_minutes' => array('type' => 'int', 'required' => false, 'label' => 'Buffer after (minutes)'),
			'bty_min_notice_minutes' => array('type' => 'int', 'required' => false, 'label' => 'Minimum notice (minutes)'),
			'bty_rolling_days' => array('type' => 'int', 'required' => false, 'label' => 'Rolling window (days ahead)'),
			'bty_window_start' => array('type' => 'date', 'required' => false, 'label' => 'Fixed window start (optional)'),
			'bty_window_end' => array('type' => 'date', 'required' => false, 'label' => 'Fixed window end (optional)'),
			'bty_max_per_day' => array('type' => 'int', 'required' => false, 'label' => 'Max bookings per day (optional)'),
			'bty_max_per_week' => array('type' => 'int', 'required' => false, 'label' => 'Max bookings per week (optional)'),
			'bty_cancel_notice_minutes' => array('type' => 'int', 'required' => false, 'label' => 'Invitee cancel/reschedule notice (minutes)'),
			'bty_cancellation_policy_text' => array('type' => 'text', 'required' => false, 'label' => 'Cancellation policy text'),
			'bty_reminder_minutes_csv' => array('type' => 'string', 'required' => false, 'label' => 'Reminder offsets (minutes, CSV)', 'help' => 'e.g. 1440,60'),
			'bty_send_native_emails' => array('type' => 'bool', 'required' => false, 'label' => 'Send native emails (confirmations, reminders)'),
		),
	);
}
