<?php
/** @joinery-test
 * name: event_recurrence
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Recurring events — pattern matching, occurrence computation, and the
 * virtual/materialized split.
 *
 * A recurring series is stored as one parent row holding the pattern. Every
 * occurrence after that is either computed in memory (virtual, no database row)
 * or promoted to a real row by an admin (materialized). Two things have to hold
 * for that to work: the pattern must decide dates the same way everywhere, and
 * an occurrence must appear exactly once in a listing regardless of which of
 * the two forms it is currently in. A date that both computes as virtual and
 * exists as a row is the failure this design is most exposed to, so the
 * expansion path is tested for duplicates directly.
 *
 * Materialization is admin-initiated, and a materialized instance is
 * independent of its parent afterwards, so the tests also pin what an instance
 * must NOT carry over: the recurrence fields. An instance that kept them would
 * be a second parent, and the series would fork.
 *
 * Sections: pattern matching per type; occurrence computation; virtual instance
 * construction; range expansion and dedup; materialization; ending a series.
 *
 * Run: php plugins/event_manager/tests/event_recurrence_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/event_manager/data/events_class.php'));

$db = DbConnector::get_instance()->get_db_link();

/**
 * A recurring parent. $start is a local wall-clock time in $tz, which is how an
 * organizer thinks about it; it is stored as UTC like every other event time.
 */
function rc_make_parent($name, $start_local, array $recurrence, $tz = 'America/New_York') {
	$event = new Event(NULL);
	$event->set('evt_name', 'HarnessTest ' . $name . ' ' . bin2hex(random_bytes(3)));
	$event->set('evt_timezone', $tz);

	$start_utc = new DateTime($start_local, new DateTimeZone($tz));
	$start_utc->setTimezone(new DateTimeZone('UTC'));
	$end_utc = clone $start_utc;
	$end_utc->modify('+90 minutes');
	$event->set('evt_start_time', $start_utc->format('Y-m-d H:i:s'));
	$event->set('evt_end_time', $end_utc->format('Y-m-d H:i:s'));
	$event->set('evt_status', Event::STATUS_ACTIVE);

	foreach ($recurrence as $field => $value) {
		$event->set($field, $value);
	}

	$event->save();
	$event->load();
	harness_register_row('evt_events', 'evt_event_id', $event->key);
	return $event;
}

/** Register any instance rows a parent spawned so teardown reclaims them. */
function rc_track_instances($parent) {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare("SELECT evt_event_id FROM evt_events WHERE evt_parent_event_id = ?");
	$q->execute(array($parent->key));
	foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
		harness_register_row('evt_events', 'evt_event_id', $id);
	}
}

// ---------------------------------------------------------------------------
section('Pattern matching: daily');

$daily = rc_make_parent('Daily', '2026-03-02 09:00:00', array(
	'evt_recurrence_type' => 'daily',
	'evt_recurrence_interval' => 1,
));

check($daily->is_recurring_parent(), 'an event with a recurrence type is a parent');
check(!$daily->is_instance(), 'a parent is not itself an instance');
check($daily->date_matches_pattern('2026-03-02'), 'the start date itself matches');
check($daily->date_matches_pattern('2026-03-03'), 'the following day matches a daily pattern');
check(!$daily->date_matches_pattern('2026-03-01'),
	'a date before the series start does not match');

$every_third = rc_make_parent('DailyInterval', '2026-03-02 09:00:00', array(
	'evt_recurrence_type' => 'daily',
	'evt_recurrence_interval' => 3,
));
check($every_third->date_matches_pattern('2026-03-05'),
	'an interval of 3 matches three days out');
check(!$every_third->date_matches_pattern('2026-03-04'),
	'an interval of 3 skips the days between');

// The end date is a hard stop, not a hint.
$bounded = rc_make_parent('DailyBounded', '2026-03-02 09:00:00', array(
	'evt_recurrence_type' => 'daily',
	'evt_recurrence_interval' => 1,
	'evt_recurrence_end_date' => '2026-03-05',
));
check($bounded->date_matches_pattern('2026-03-05'),
	'the recurrence end date is itself an occurrence');
check(!$bounded->date_matches_pattern('2026-03-06'),
	'no occurrence falls after the recurrence end date');

// ---------------------------------------------------------------------------
section('Pattern matching: weekly');

// 2026-03-02 is a Monday.
$weekly = rc_make_parent('Weekly', '2026-03-02 18:00:00', array(
	'evt_recurrence_type' => 'weekly',
	'evt_recurrence_interval' => 1,
));
check($weekly->date_matches_pattern('2026-03-09'),
	'a weekly series matches the same weekday one week on');
check(!$weekly->date_matches_pattern('2026-03-10'),
	'a weekly series does not match a different weekday');

// Multiple days in one week: Monday(1) and Thursday(4).
$multi_day = rc_make_parent('WeeklyMultiDay', '2026-03-02 18:00:00', array(
	'evt_recurrence_type' => 'weekly',
	'evt_recurrence_interval' => 1,
	'evt_recurrence_days_of_week' => '1,4',
));
check($multi_day->date_matches_pattern('2026-03-05'),
	'a named weekday other than the start day matches');
check($multi_day->date_matches_pattern('2026-03-09'),
	'the start weekday still matches when days are named');
check(!$multi_day->date_matches_pattern('2026-03-04'),
	'a weekday not in the named set does not match');

// Fortnightly: the off weeks must be genuinely off.
$biweekly = rc_make_parent('Biweekly', '2026-03-02 18:00:00', array(
	'evt_recurrence_type' => 'weekly',
	'evt_recurrence_interval' => 2,
));
check($biweekly->date_matches_pattern('2026-03-16'),
	'a fortnightly series matches two weeks on');
check(!$biweekly->date_matches_pattern('2026-03-09'),
	'a fortnightly series skips the intervening week');
check($biweekly->date_matches_pattern('2026-03-30'),
	'a fortnightly series stays in phase across a month boundary');

// ---------------------------------------------------------------------------
section('Pattern matching: monthly');

// By date: the 15th of each month.
$monthly = rc_make_parent('Monthly', '2026-03-15 12:00:00', array(
	'evt_recurrence_type' => 'monthly',
	'evt_recurrence_interval' => 1,
));
check($monthly->date_matches_pattern('2026-04-15'),
	'a monthly series matches the same day of the next month');
check(!$monthly->date_matches_pattern('2026-04-16'),
	'a monthly series does not match a neighbouring day');

// A series on the 31st has to land somewhere in a 30-day month. The rule is to
// clamp to the last day, so the series never silently skips a month.
$month_end = rc_make_parent('MonthlyEnd', '2026-01-31 12:00:00', array(
	'evt_recurrence_type' => 'monthly',
	'evt_recurrence_interval' => 1,
));
check($month_end->date_matches_pattern('2026-04-30'),
	'a series on the 31st clamps to the last day of a 30-day month');
check($month_end->date_matches_pattern('2026-02-28'),
	'a series on the 31st clamps to the last day of February');
check(!$month_end->date_matches_pattern('2026-04-29'),
	'the clamp lands on the last day, not merely near it');

// By week-of-month: the first Monday.
$first_monday = rc_make_parent('FirstMonday', '2026-03-02 12:00:00', array(
	'evt_recurrence_type' => 'monthly',
	'evt_recurrence_interval' => 1,
	'evt_recurrence_week_of_month' => 1,
));
check($first_monday->date_matches_pattern('2026-04-06'),
	'a first-Monday series matches the first Monday of the next month');
check(!$first_monday->date_matches_pattern('2026-04-13'),
	'a first-Monday series does not match the second Monday');

// Last-of-month is expressed as -1 and must follow months with four or five
// occurrences of the weekday, which is the whole reason it is not just "the 4th".
$last_monday = rc_make_parent('LastMonday', '2026-03-30 12:00:00', array(
	'evt_recurrence_type' => 'monthly',
	'evt_recurrence_interval' => 1,
	'evt_recurrence_week_of_month' => -1,
));
check($last_monday->date_matches_pattern('2026-04-27'),
	'a last-Monday series matches the last Monday of a five-Monday month',
	'2026-04-27 is the final Monday of April 2026');
check(!$last_monday->date_matches_pattern('2026-04-20'),
	'a last-Monday series does not match the second-to-last Monday');

// ---------------------------------------------------------------------------
section('Pattern matching: yearly');

$yearly = rc_make_parent('Yearly', '2026-03-15 12:00:00', array(
	'evt_recurrence_type' => 'yearly',
	'evt_recurrence_interval' => 1,
));
check($yearly->date_matches_pattern('2027-03-15'),
	'a yearly series matches the same date next year');
check(!$yearly->date_matches_pattern('2027-03-16'),
	'a yearly series does not match a neighbouring date');
check(!$yearly->date_matches_pattern('2027-04-15'),
	'a yearly series does not match the same day of a different month');

// Feb 29 exists one year in four; the series has to survive the other three.
$leap = rc_make_parent('LeapDay', '2028-02-29 12:00:00', array(
	'evt_recurrence_type' => 'yearly',
	'evt_recurrence_interval' => 1,
));
check($leap->date_matches_pattern('2029-02-28'),
	'a Feb 29 series falls back to Feb 28 in a non-leap year');
check($leap->date_matches_pattern('2032-02-29'),
	'a Feb 29 series returns to Feb 29 in the next leap year');

// ---------------------------------------------------------------------------
section('Occurrence computation');

// compute_occurrence_dates is what the admin occurrence list and the ICS feed
// call. It must return consecutive real occurrences, in order, without gaps.
$dates = $daily->compute_occurrence_dates('2026-03-02', 4);
check($dates === array('2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05'),
	'a daily series computes four consecutive days',
	'got: ' . implode(', ', $dates));

$dates = $weekly->compute_occurrence_dates('2026-03-02', 3);
check($dates === array('2026-03-02', '2026-03-09', '2026-03-16'),
	'a weekly series computes three consecutive weeks',
	'got: ' . implode(', ', $dates));

$dates = $monthly->compute_occurrence_dates('2026-03-15', 3);
check($dates === array('2026-03-15', '2026-04-15', '2026-05-15'),
	'a monthly series computes three consecutive months',
	'got: ' . implode(', ', $dates));

// The yearly case is the one that has to jump a long way between occurrences,
// so it is the one where a wrong advance step goes unnoticed: the dates still
// look like plausible anniversaries, just the wrong ones.
$dates = $yearly->compute_occurrence_dates('2026-03-15', 4);
check($dates === array('2026-03-15', '2027-03-15', '2028-03-15', '2029-03-15'),
	'a yearly series computes consecutive years, not a longer stride',
	'got: ' . implode(', ', $dates));

// A Feb 29 series has to keep returning to Feb 29 rather than drifting to the
// 28th permanently once it has clamped once.
$dates = $leap->compute_occurrence_dates('2028-02-29', 5);
check($dates === array('2028-02-29', '2029-02-28', '2030-02-28', '2031-02-28', '2032-02-29'),
	'a Feb 29 series clamps in common years and returns in leap years',
	'got: ' . implode(', ', $dates));

// A wide stride must not quietly shorten the list. A quarterly series asked for
// twenty occurrences has to produce twenty, not however many fit an internal
// iteration budget — a short list is indistinguishable from a series that ended.
$quarterly = rc_make_parent('Quarterly', '2026-03-15 12:00:00', array(
	'evt_recurrence_type' => 'monthly',
	'evt_recurrence_interval' => 3,
));
$dates = $quarterly->compute_occurrence_dates('2026-03-15', 20);
check(count($dates) === 20,
	'a quarterly series returns every occurrence asked for',
	'got ' . count($dates));
check($dates[1] === '2026-06-15' && $dates[4] === '2027-03-15',
	'quarterly occurrences land three months apart',
	'got: ' . implode(', ', array_slice($dates, 0, 5)));

$dates = $every_third->compute_occurrence_dates('2026-03-02', 3);
check($dates === array('2026-03-02', '2026-03-05', '2026-03-08'),
	'an interval is honoured by the computed list, not only by the matcher',
	'got: ' . implode(', ', $dates));

// A bounded series runs out rather than inventing occurrences.
$dates = $bounded->compute_occurrence_dates('2026-03-02', 10);
check(count($dates) === 4,
	'a bounded series returns only the occurrences that exist',
	'got ' . count($dates) . ': ' . implode(', ', $dates));
check(end($dates) === '2026-03-05',
	'the last computed occurrence is the recurrence end date');

// Asking from partway through a series starts from there, not from the top.
$dates = $daily->compute_occurrence_dates('2026-03-10', 2);
check($dates === array('2026-03-10', '2026-03-11'),
	'computation starts from the requested date, not the series start',
	'got: ' . implode(', ', $dates));

$dates = $daily->compute_occurrence_dates('2026-03-02', 0);
check($dates === array(), 'asking for zero occurrences returns nothing');

$standalone = rc_make_parent('Standalone', '2026-03-02 09:00:00', array());
check($standalone->compute_occurrence_dates('2026-03-02', 5) === array(),
	'a non-recurring event computes no occurrences');
check(!$standalone->date_matches_pattern('2026-03-02'),
	'a non-recurring event matches no date, not even its own start');

// ---------------------------------------------------------------------------
section('Virtual instances');

$virtual = $weekly->create_virtual_instance('2026-03-09');

check(!empty($virtual->is_virtual), 'a virtual instance is marked virtual');
check($virtual->evt_event_id === null, 'a virtual instance has no row id');
check((int)$virtual->parent_event_id === (int)$weekly->key,
	'a virtual instance points at its parent');
check($virtual->evt_name === $weekly->get('evt_name'),
	'a virtual instance carries the parent display fields');

// The recurrence fields must be cleared, or the instance reads as a second
// parent and the series forks.
check($virtual->evt_recurrence_type === null,
	'a virtual instance carries no recurrence type of its own');
check($virtual->evt_recurrence_interval === null,
	'a virtual instance carries no recurrence interval of its own');

// The organizer set a wall-clock time; every occurrence must keep it. Stored
// UTC will differ across a DST boundary precisely because the local time does not.
$tz = new DateTimeZone('America/New_York');
$local = new DateTime($virtual->evt_start_time, new DateTimeZone('UTC'));
$local->setTimezone($tz);
check($local->format('Y-m-d') === '2026-03-09',
	'a virtual instance starts on its own date',
	'got: ' . $local->format('Y-m-d H:i:s'));
check($local->format('H:i:s') === '18:00:00',
	'a virtual instance keeps the local time of day',
	'got: ' . $local->format('H:i:s T'));

// 2026-03-08 is the US DST transition, so 03-02 and 03-09 sit on opposite
// sides of it. Same local time, different UTC time — that is the point.
$parent_local = new DateTime($weekly->get('evt_start_time'), new DateTimeZone('UTC'));
$parent_local->setTimezone($tz);
check($parent_local->format('H:i:s') === $local->format('H:i:s'),
	'local time of day is preserved across a DST boundary');
check(substr($weekly->get('evt_start_time'), 11) !== substr($virtual->evt_start_time, 11),
	'the stored UTC time shifts across a DST boundary, as it must',
	'parent UTC ' . $weekly->get('evt_start_time') . ' vs instance UTC ' . $virtual->evt_start_time);

// Duration is a property of the event, not of the occurrence.
$v_start = new DateTime($virtual->evt_start_time);
$v_end = new DateTime($virtual->evt_end_time);
check(($v_end->getTimestamp() - $v_start->getTimestamp()) === 5400,
	'a virtual instance keeps the parent duration',
	'seconds: ' . ($v_end->getTimestamp() - $v_start->getTimestamp()));

// ---------------------------------------------------------------------------
section('Range expansion and dedup');

$series = rc_make_parent('RangeSeries', '2026-03-02 10:00:00', array(
	'evt_recurrence_type' => 'weekly',
	'evt_recurrence_interval' => 1,
));
harness_defer(function () use ($series) { rc_track_instances($series); });

$instances = $series->get_instances_for_range('2026-03-01', '2026-03-31');
check(count($instances) === 5,
	'a weekly series expands to every occurrence in the range',
	'got ' . count($instances));

$dates = array();
foreach ($instances as $inst) {
	$dates[] = is_object($inst) && $inst instanceof Event
		? $inst->get('evt_materialized_instance_date')
		: $inst->instance_date;
}
check($dates === array('2026-03-02', '2026-03-09', '2026-03-16', '2026-03-23', '2026-03-30'),
	'expansion returns the occurrences in date order',
	'got: ' . implode(', ', $dates));
check(count($dates) === count(array_unique($dates)),
	'expansion returns each date once');

// Nothing is materialized yet, so everything in the range is virtual.
$virtual_count = 0;
foreach ($instances as $inst) {
	if (!($inst instanceof Event)) { $virtual_count++; }
}
check($virtual_count === 5, 'an untouched series expands entirely to virtual instances',
	'virtual: ' . $virtual_count);

// Now promote one occurrence. The same date must not appear twice — once as the
// row and once as a computed instance. This is the failure the hybrid design is
// most exposed to, and it is silent: the listing simply shows the event twice.
$materialized = $series->materialize_instance('2026-03-16');
harness_register_row('evt_events', 'evt_event_id', $materialized->key);

$instances = $series->get_instances_for_range('2026-03-01', '2026-03-31');
check(count($instances) === 5,
	'materializing an occurrence does not change how many the range holds',
	'got ' . count($instances));

$dates = array();
$real_for_date = array();
foreach ($instances as $inst) {
	if ($inst instanceof Event) {
		$dates[] = $inst->get('evt_materialized_instance_date');
		$real_for_date[$inst->get('evt_materialized_instance_date')] = true;
	} else {
		$dates[] = $inst->instance_date;
	}
}
check(count($dates) === count(array_unique($dates)),
	'a materialized occurrence does not also appear as a virtual one',
	'dates: ' . implode(', ', $dates));
check(isset($real_for_date['2026-03-16']),
	'the materialized date is served by the real row');
check(count($real_for_date) === 1,
	'only the materialized date is served by a row',
	'rows: ' . implode(', ', array_keys($real_for_date)));

// A range that predates the series produces nothing rather than back-filling.
$before = $series->get_instances_for_range('2026-01-01', '2026-02-01');
check(count($before) === 0,
	'a range entirely before the series start expands to nothing',
	'got ' . count($before));

check($standalone->get_instances_for_range('2026-03-01', '2026-03-31') === array(),
	'a non-recurring event expands to nothing');

// ---------------------------------------------------------------------------
section('Materialization');

check($materialized instanceof Event, 'materializing returns a real event');
check($materialized->key > 0, 'the materialized instance has a row id');
check($materialized->is_instance(), 'the materialized row reads as an instance');
check((int)$materialized->get('evt_parent_event_id') === (int)$series->key,
	'the materialized instance points at its parent');
check($materialized->get('evt_materialized_instance_date') === '2026-03-16',
	'the materialized instance records which occurrence it is',
	'got: ' . var_export($materialized->get('evt_materialized_instance_date'), true));

// The instance must not itself be a parent, or the series forks.
check(!$materialized->is_recurring_parent(),
	'a materialized instance is not itself a recurring parent');
foreach (Event::RECURRENCE_FIELDS as $rf) {
	if ($rf === 'evt_recurrence_interval') { continue; }
	check($materialized->get($rf) === null || $materialized->get($rf) === '',
		'the instance carries no ' . $rf,
		'got: ' . var_export($materialized->get($rf), true));
}

// The instance owns its own slug, or two occurrences would collide on one URL.
check($materialized->get('evt_link') !== $series->get('evt_link'),
	'the instance gets its own URL slug',
	'parent: ' . $series->get('evt_link') . ' instance: ' . $materialized->get('evt_link'));

// Local time of day survives promotion the same way it does for a virtual one.
$m_local = new DateTime($materialized->get('evt_start_time'), new DateTimeZone('UTC'));
$m_local->setTimezone($tz);
check($m_local->format('Y-m-d') === '2026-03-16',
	'the materialized instance starts on its own date',
	'got: ' . $m_local->format('Y-m-d H:i:s'));
check($m_local->format('H:i:s') === '10:00:00',
	'the materialized instance keeps the local time of day',
	'got: ' . $m_local->format('H:i:s'));

// Materializing twice must return the row that exists rather than making a
// second one. The admin page can be double-clicked, and both handlers land here.
$again = $series->materialize_instance('2026-03-16');
check((int)$again->key === (int)$materialized->key,
	'materializing an already-materialized date returns the existing row',
	'first ' . $materialized->key . ' second ' . $again->key);

$q = $db->prepare("SELECT COUNT(*) FROM evt_events
	WHERE evt_parent_event_id = ? AND evt_materialized_instance_date = ? AND evt_delete_time IS NULL");
$q->execute(array($series->key, '2026-03-16'));
check((int)$q->fetchColumn() === 1,
	'exactly one row exists for the materialized date',
	'rows: ' . $q->fetchColumn());

// A date the pattern does not produce is not materializable — otherwise an
// arbitrary date could be injected into a series from a crafted request.
$threw = false;
try {
	$series->materialize_instance('2026-03-17');
} catch (EventException $e) {
	$threw = true;
}
check($threw, 'a date outside the pattern cannot be materialized');

$threw = false;
try {
	$standalone->materialize_instance('2026-03-02');
} catch (EventException $e) {
	$threw = true;
}
check($threw, 'a non-recurring event cannot be materialized');

// ---------------------------------------------------------------------------
section('Ending a series');

$ending = rc_make_parent('EndingSeries', '2026-03-02 10:00:00', array(
	'evt_recurrence_type' => 'weekly',
	'evt_recurrence_interval' => 1,
));

$before_count = count($ending->get_instances_for_range('2026-03-01', '2026-03-31'));
check($before_count === 5, 'the series runs to five occurrences before being ended',
	'got ' . $before_count);

$ending->end_series('2026-03-16');
$ending->load();

check($ending->get('evt_recurrence_end_date') === '2026-03-16',
	'ending a series records the end date',
	'got: ' . var_export($ending->get('evt_recurrence_end_date'), true));

$after = $ending->get_instances_for_range('2026-03-01', '2026-03-31');
check(count($after) === 3,
	'occurrences after the end date stop being generated',
	'got ' . count($after));
check(!$ending->date_matches_pattern('2026-03-23'),
	'a date past the end date no longer matches');
check($ending->date_matches_pattern('2026-03-16'),
	'the end date itself remains an occurrence');

$standalone->end_series('2026-03-16');
check($standalone->get('evt_recurrence_end_date') === null
	|| $standalone->get('evt_recurrence_end_date') === '',
	'ending a non-recurring event does nothing',
	'got: ' . var_export($standalone->get('evt_recurrence_end_date'), true));

rc_track_instances($series);
harness_finish();
