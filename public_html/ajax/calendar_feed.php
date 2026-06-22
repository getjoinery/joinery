<?php
/**
 * Personal calendar feed — the owner's aggregated calendar items as JSON, for
 * the calendar_grid component's feed_url paging. Core, because the personal
 * calendar is a core surface; it aggregates every registered CalendarItemSource
 * (events now, native entries and bookings as their sources come online).
 *
 * Owner-only: items are returned at `details` visibility, so this endpoint must
 * never serve anyone but the logged-in owner of the calendar.
 *
 * GET: start, end (UTC 'Y-m-d H:i:s'). Returns { items: [ ... ] }.
 */
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarSubject.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarItemSourceRegistry.php'));

header('Content-Type: application/json');

$session = SessionControl::get_instance();
$user_id = $session->get_user_id();
if (!$user_id) {
    http_response_code(401);
    echo json_encode(['items' => []]);
    exit;
}

$subject = CalendarSubject::user($user_id);

$today = gmdate('Y-m-d');
$start = isset($_GET['start']) ? $_GET['start'] : gmdate('Y-m-d 00:00:00', strtotime($today . ' -7 days'));
$end   = isset($_GET['end'])   ? $_GET['end']   : gmdate('Y-m-d 00:00:00', strtotime($today . ' +45 days'));

// Accept date-only bounds too.
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) { $start .= ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   { $end   .= ' 00:00:00'; }
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $start)
    || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $end)) {
    http_response_code(400);
    echo json_encode(['items' => [], 'error' => 'invalid range']);
    exit;
}

$items = CalendarItemSourceRegistry::getItems($subject, $start, $end, CalendarItem::VIS_DETAILS);
$out = array();
foreach ($items as $item) {
    $out[] = $item->toArray();
}

echo json_encode(['items' => $out]);
