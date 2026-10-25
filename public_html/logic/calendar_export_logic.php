<?php
/**
 * API action: calendar_export — download the signed-in member's own calendar as one .ics file.
 *
 * POST /api/v1/action/calendar_export (session credential). No params. Returns
 * {filename, content_type, content, count}: an iCalendar file of every native entry on
 * the member's calendar (IcsExporter), which the calendar page hands to the browser as
 * a download. It reads back through the calendar's .ics import as the same entries.
 * A one-time file, not a feed.
 *
 * A Private calendar's entries are read in the owner's window; with it closed the
 * download is refused with `locked: true` rather than written with placeholders.
 *
 * @version 1.0
 */

function calendar_export_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();
	if (!$user_id) {
		return LogicResult::error('Sign in required.');
	}

	$built = IcsExporter::build(CalendarSubject::user($user_id));
	if ($built['locked']) {
		return LogicResult::error('Your calendar is sealed to your vault. Unlock it and try again.', array('locked' => true));
	}

	$today = LibraryFunctions::convert_time(gmdate('Y-m-d H:i:s'), 'UTC', $session->get_timezone(), 'Y-m-d');
	return LogicResult::render(array(
		'filename'     => 'calendar-' . $today . '.ics',
		'content_type' => 'text/calendar;charset=utf-8',
		'content'      => $built['ics'],
		'count'        => $built['count'],
	));
}

function calendar_export_logic_descriptor() {
	return array(
		'auth' => array('min_user_permission' => 0),
		'requires_session' => true,
		'mutates' => false,
		'description' => 'Download the signed-in member\'s own calendar as an iCalendar (.ics) file',
		'input' => array(),
	);
}
?>
