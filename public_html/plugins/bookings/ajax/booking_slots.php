<?php
/**
 * Public slots endpoint — open times for a booking type within a range.
 *
 *   GET /plugins/bookings/ajax/slots?slug=...&start=...&end=...
 *
 * Public and read-only: no session required. Availability is computed against
 * the busy projection at `busy` visibility (the provider uses getBusyBlocks),
 * so no calendar item titles ever reach a public caller. Returns {slots:[...]}.
 */
require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('plugins/bookings/data/booking_types_class.php'));
require_once(PathHelper::getIncludePath('plugins/bookings/includes/SchedulingProviderRegistry.php'));

header('Content-Type: application/json');

$settings = Globalvars::get_instance();
if (!$settings->get_setting('bookings_active')) {
	echo json_encode(['slots' => []]);
	exit;
}

$slug = isset($_GET['slug']) ? trim($_GET['slug']) : '';
$type = $slug ? BookingType::GetBySlug($slug) : false;
if (!$type || !$type->is_active()) {
	echo json_encode(['slots' => []]);
	exit;
}

$today = gmdate('Y-m-d');
$start = isset($_GET['start']) ? $_GET['start'] : gmdate('Y-m-d 00:00:00');
$end   = isset($_GET['end'])   ? $_GET['end']   : gmdate('Y-m-d 00:00:00', strtotime($today . ' +45 days'));
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) { $start .= ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   { $end   .= ' 00:00:00'; }
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $start)
	|| !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $end)) {
	echo json_encode(['slots' => [], 'error' => 'invalid range']);
	exit;
}

try {
	$provider = SchedulingProviderRegistry::get($type->get('bkt_provider'));
	$slots = $provider->getAvailableSlots($type, $start, $end);
} catch (Exception $e) {
	$slots = [];
}

echo json_encode(['slots' => $slots]);
