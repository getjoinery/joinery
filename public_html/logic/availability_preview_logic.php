<?php
/**
 * availability_preview — the availability editor's calendar preview feed.
 *
 * Returns the signed-in owner's OPEN availability (working hours minus busy) as
 * green blocks alongside their existing calendar commitments, so they can see
 * the shape of their availability against what's already booked. Owner-only,
 * read-only; data.items feeds the calendar_grid component.
 *
 * @version 1.0.1
 */

require_once(__DIR__ . '/../includes/PathHelper.php');

function availability_preview_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/calendar/CalendarSubject.php'));
	require_once(PathHelper::getIncludePath('includes/calendar/CalendarItemSourceRegistry.php'));
	require_once(PathHelper::getIncludePath('includes/scheduling/SlotGenerator.php'));
	require_once(PathHelper::getIncludePath('data/schedule_class.php'));
	require_once(PathHelper::getIncludePath('data/schedule_window_class.php'));
	require_once(PathHelper::getIncludePath('data/schedule_override_class.php'));

	$session = SessionControl::get_instance();
	$user_id = $session->get_user_id();
	if (!$user_id) {
		return LogicResult::error('Sign in required.');
	}
	$subject = CalendarSubject::user($user_id);

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	$range = LibraryFunctions::parse_utc_range($input);
	if ($range === NULL) {
		return LogicResult::error('Invalid date range.');
	}
	list($start, $end) = $range;

	$items = array();

	// Existing commitments (owner sees details).
	foreach (CalendarItemSourceRegistry::getItems($subject, $start, $end, CalendarItem::VIS_DETAILS) as $it) {
		$items[] = $it->toArray();
	}

	// Open availability as green blocks.
	$schedule = Schedule::for_subject($subject);
	if ($schedule) {
		$windows = array();
		$mw = new MultiScheduleWindow(['schedule_id' => $schedule->key, 'deleted' => false]);
		$mw->load();
		foreach ($mw as $w) {
			$windows[] = ['day_of_week' => (int) $w->get('scw_day_of_week'), 'start' => $w->get('scw_start_time'), 'end' => $w->get('scw_end_time')];
		}
		$overrides = array();
		$mo = new MultiScheduleOverride(['schedule_id' => $schedule->key, 'deleted' => false]);
		$mo->load();
		foreach ($mo as $o) {
			$overrides[] = ['date' => substr($o->get('sco_date'), 0, 10), 'start' => $o->get('sco_start_time'), 'end' => $o->get('sco_end_time')];
		}

		$busy = CalendarItemSourceRegistry::getBusyBlocks($subject, $start, $end);
		$free = SlotGenerator::availableIntervals([
			'timezone'        => $schedule->get('sch_timezone'),
			'windows'         => $windows,
			'overrides'       => $overrides,
			'range_start_utc' => $start,
			'range_end_utc'   => $end,
			'busy'            => $busy,
		]);
		foreach ($free as $iv) {
			$items[] = [
				'start'      => $iv['start'],
				'end'        => $iv['end'],
				'title'      => 'Available',
				'url'        => null,
				'color'      => '#16a34a',
				'type'       => 'available',
				'source_key' => 'availability:' . $iv['start'],
			];
		}
	}

	return LogicResult::render(['items' => $items]);
}

function availability_preview_logic_descriptor(): array {
	return [
		'description' => 'Owner-only availability preview: open availability blocks plus existing commitments over a UTC range.',
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
