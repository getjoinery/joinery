<?php
/**
 * API action: calendar_feed — the owner's aggregated calendar items as JSON.
 *
 * POST /api/v1/action/calendar_feed (session key). Params: start, end
 * (UTC 'Y-m-d H:i:s' or bare 'Y-m-d'; defaults to -7d…+45d). Returns
 * { items: [...], timezone: <profile tz> } — the same aggregation the web
 * grid consumes (every registered CalendarItemSource, details visibility,
 * owner-only), with native items carrying entry_id / occurrence_date so a
 * client can open the right editor without parsing urls.
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../includes/PathHelper.php');

function calendar_feed_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/calendar/CalendarSubject.php'));
	require_once(PathHelper::getIncludePath('includes/calendar/CalendarItemSourceRegistry.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$subject = CalendarSubject::user($session->get_user_id());

	$today = gmdate('Y-m-d');
	$start = isset($input['start']) ? (string)$input['start'] : gmdate('Y-m-d 00:00:00', strtotime($today . ' -7 days'));
	$end   = isset($input['end'])   ? (string)$input['end']   : gmdate('Y-m-d 00:00:00', strtotime($today . ' +45 days'));

	if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) { $start .= ' 00:00:00'; }
	if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   { $end   .= ' 00:00:00'; }
	if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $start)
		|| !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $end)
		|| $end <= $start) {
		return LogicResult::error('Invalid date range.');
	}

	$items = CalendarItemSourceRegistry::getItems($subject, $start, $end, CalendarItem::VIS_DETAILS);
	$out = array();
	foreach ($items as $item) {
		$out[] = $item->toArray();
	}

	return LogicResult::render(array(
		'items'    => $out,
		'timezone' => $session->get_timezone(),
	));
}

function calendar_feed_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'Aggregated calendar items for the signed-in owner over a UTC range',
	];
}

?>
