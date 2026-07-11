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
 * @version 1.0.1
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

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	$range = LibraryFunctions::parse_utc_range($input);
	if ($range === NULL) {
		return LogicResult::error('Invalid date range.');
	}
	list($start, $end) = $range;

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

function calendar_feed_logic_descriptor(): array {
	return [
		'description' => 'Aggregated calendar items for the signed-in owner over a UTC range.',
		'mutates'     => false,
		'auth'        => [
			'capability'       => 'read',
			'requires_session' => true,
		],
		'input'       => [
			'start' => ['type' => 'string', 'required' => false, 'label' => 'Range start (UTC)'],
			'end'   => ['type' => 'string', 'required' => false, 'label' => 'Range end (UTC)'],
		],
	];
}

?>
