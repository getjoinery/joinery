<?php
/**
 * API action: calendar_entry — one native calendar entry, shaped for an editor.
 *
 * POST /api/v1/action/calendar_entry (session key). Params: entry_id.
 * Returns the entry's wall-clock fields (date, start/end times, timezone),
 * flags, and recurrence settings. For a recurring parent the caller supplies
 * its own occurrence_date context (from the feed item) when editing a single
 * occurrence — the stored fields returned here are the series values.
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../includes/PathHelper.php');

function calendar_entry_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/calendar_entry_class.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$eid = intval($input['entry_id'] ?? 0);
	if (!$eid) {
		return LogicResult::error('entry_id is required.');
	}

	$entry = new CalendarEntry($eid, true);
	if (!$entry->key || $entry->get('cal_delete_time')) {
		return LogicResult::error('Entry not found.');
	}
	try {
		$entry->authenticate_read([
			'current_user_id'         => $session->get_user_id(),
			'current_user_permission' => $session->get_permission(),
		]);
	} catch (SystemAuthenticationError $e) {
		return LogicResult::error('Entry not found.');
	}

	// Wall-clock values in the entry's own timezone (what an editor pre-fills);
	// fall back to converting the UTC instants into the viewer's timezone for
	// rows predating local storage.
	$tz = $entry->get('cal_timezone') ?: $session->get_timezone();
	$ls = $entry->get('cal_start_local');
	$le = $entry->get('cal_end_local');
	if ($ls) {
		$date    = substr($ls, 0, 10);
		$start_t = substr($ls, 11, 8);
		$end_t   = $le ? substr($le, 11, 8) : '';
	} else {
		$date    = LibraryFunctions::convert_time($entry->get('cal_start_utc'), 'UTC', $tz, 'Y-m-d');
		$start_t = LibraryFunctions::convert_time($entry->get('cal_start_utc'), 'UTC', $tz, 'H:i:s');
		$end_t   = LibraryFunctions::convert_time($entry->get('cal_end_utc'),   'UTC', $tz, 'H:i:s');
	}

	$is_recurring = $entry->is_recurring_parent();

	return LogicResult::render(array(
		'entry' => array(
			'entry_id'               => (int)$entry->key,
			'title'                  => (string)($entry->get('cal_title') ?: ''),
			'date'                   => $date,
			'start_time'             => $start_t,
			'end_time'               => $end_t,
			'timezone'               => $tz,
			'all_day'                => (bool)$entry->get('cal_all_day'),
			'blocks_availability'    => (bool)$entry->get('cal_blocks_availability'),
			'is_recurring_parent'    => $is_recurring,
			'recurrence_description' => $is_recurring ? $entry->get_recurrence_description() : '',
			'recurrence' => array(
				'type'          => $entry->get('cal_recurrence_type'),
				'interval'      => (int)($entry->get('cal_recurrence_interval') ?: 1),
				'days_of_week'  => $entry->get('cal_recurrence_days_of_week'),
				'week_of_month' => $entry->get('cal_recurrence_week_of_month') !== null
					? (int)$entry->get('cal_recurrence_week_of_month') : null,
				'end_date'      => $entry->get('cal_recurrence_end_date'),
			),
		),
	));
}

function calendar_entry_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'Load one native calendar entry (owner-only) for editing',
	];
}

?>
