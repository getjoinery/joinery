<?php
/**
 * bookings/booking_slots — public open times for a booking type within a range.
 *
 * Sessionless and read-only (requires_session => false): the public booking and
 * reschedule pages call it, possibly from cached HTML, with no credential.
 * Availability is computed at `busy` visibility (getBusyBlocks), so no calendar
 * item titles ever reach a public caller. Returns {slots: [...]}. Fail-soft:
 * an inactive booking system, an unknown/inactive slug, or a bad range all
 * return an empty slot list rather than an error.
 *
 * @version 1.0.1
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function booking_slots_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/bookings/data/booking_types_class.php'));
	require_once(PathHelper::getIncludePath('plugins/bookings/includes/SchedulingProviderRegistry.php'));

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('bookings_active')) {
		return LogicResult::render(['slots' => []]);
	}

	$slug = isset($input['slug']) ? trim((string) $input['slug']) : '';
	$type = $slug ? BookingType::GetBySlug($slug) : false;
	if (!$type || !$type->is_active()) {
		return LogicResult::render(['slots' => []]);
	}

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	$range = LibraryFunctions::parse_utc_range($input, '+0 days');
	if ($range === NULL) {
		return LogicResult::render(['slots' => [], 'error' => 'invalid range']);
	}
	list($start, $end) = $range;

	try {
		$provider = SchedulingProviderRegistry::get($type->get('bkt_provider'));
		$slots = $provider->getAvailableSlots($type, $start, $end);
	} catch (Exception $e) {
		$slots = [];
	}

	return LogicResult::render(['slots' => $slots]);
}

function booking_slots_logic_descriptor(): array {
	return [
		'description' => 'Public open booking slots for a booking type over a UTC range.',
		'mutates'     => false,
		'requires_session'        => false,
		'input'       => [
			'slug'  => ['type' => 'string', 'required' => false, 'label' => 'Booking type slug'],
			'start' => ['type' => 'string', 'required' => false, 'label' => 'Range start (UTC)'],
			'end'   => ['type' => 'string', 'required' => false, 'label' => 'Range end (UTC)'],
		],
	];
}
?>
