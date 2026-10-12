<?php
/** @joinery-test
 * name: recurrence_rule
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * RecurrenceRule: the one copy of the date arithmetic behind every repeating thing
 * (core_improvements_for_plugins.md C1). Checked three ways:
 *
 *  - the class directly, against dates worked out by hand;
 *  - the same table of rules through CalendarEntry and Event, which delegate to it,
 *    so a rule gives the same dates on a calendar entry and on an event series;
 *  - the two places the users differ ON PURPOSE (a day missing from a month, and the
 *    zone the start date is read in), so a change to either is a decision, not an accident.
 *
 * Run: php tests/unit/recurrence_rule_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

/** A rule's dates in [from, to] as a comma list. */
function rr_dates($rule, $from, $to) { return implode(',', $rule->datesInRange($from, $to)); }

section('fromArray');
check(RecurrenceRule::fromArray('', 1, null, null, null, '2026-01-05') === null, 'no type is not a rule');
check(RecurrenceRule::fromArray('daily', 1, null, null, null, '') === null, 'no start date is not a rule');
check(RecurrenceRule::fromArray('daily', 1, null, null, null, 'not a date') === null, 'a start that is not a date is not a rule');
check(RecurrenceRule::fromArray('daily', 1, null, null, null, '2026-01-05 21:00:00')->startDate() === '2026-01-05', 'a timestamp is cut to its date');

section('dates, worked out by hand');
$daily = RecurrenceRule::fromArray('daily', 3, null, null, null, '2026-01-05');
check(rr_dates($daily, '2026-01-01', '2026-01-16') === '2026-01-05,2026-01-08,2026-01-11,2026-01-14', 'every 3 days from Mon Jan 5, not before it', rr_dates($daily, '2026-01-01', '2026-01-16'));

$weekly = RecurrenceRule::fromArray('weekly', 1, null, null, null, '2026-01-07'); // a Wednesday
check(rr_dates($weekly, '2026-01-01', '2026-01-31') === '2026-01-07,2026-01-14,2026-01-21,2026-01-28', 'weekly lands on the start weekday');
$mwf = RecurrenceRule::fromArray('weekly', 1, '1,3,5', null, null, '2026-01-05');
check(rr_dates($mwf, '2026-01-05', '2026-01-11') === '2026-01-05,2026-01-07,2026-01-09', 'Mon, Wed, Fri', rr_dates($mwf, '2026-01-05', '2026-01-11'));
$biweekly = RecurrenceRule::fromArray('weekly', 2, '1', null, null, '2026-01-05');
check(rr_dates($biweekly, '2026-01-05', '2026-02-09') === '2026-01-05,2026-01-19,2026-02-02', 'every other Monday', rr_dates($biweekly, '2026-01-05', '2026-02-09'));
$sunday = RecurrenceRule::fromArray('weekly', 1, '0', null, null, '2026-01-07'); // starts on a Wednesday, repeats on Sundays
check(rr_dates($sunday, '2026-01-07', '2026-01-25') === '2026-01-11,2026-01-18,2026-01-25', 'a Sunday-only rule lands on Sundays (days_of_week 0 is a day, not nothing)', rr_dates($sunday, '2026-01-07', '2026-01-25'));

$second_tue = RecurrenceRule::fromArray('monthly', 1, null, 2, null, '2026-01-13');
check(rr_dates($second_tue, '2026-01-01', '2026-04-30') === '2026-01-13,2026-02-10,2026-03-10,2026-04-14', 'the 2nd Tuesday', rr_dates($second_tue, '2026-01-01', '2026-04-30'));
$last_fri = RecurrenceRule::fromArray('monthly', 1, null, -1, null, '2026-01-30');
check(rr_dates($last_fri, '2026-01-01', '2026-03-31') === '2026-01-30,2026-02-27,2026-03-27', 'the last Friday', rr_dates($last_fri, '2026-01-01', '2026-03-31'));
$third_thu = RecurrenceRule::fromArray('monthly', 1, '4', 3, null, '2026-01-01');
check(rr_dates($third_thu, '2026-01-01', '2026-02-28') === '2026-01-15,2026-02-19', 'the weekday can be given as one digit', rr_dates($third_thu, '2026-01-01', '2026-02-28'));
$bimonthly = RecurrenceRule::fromArray('monthly', 2, null, null, null, '2026-01-15');
check(rr_dates($bimonthly, '2026-01-01', '2026-07-31') === '2026-01-15,2026-03-15,2026-05-15,2026-07-15', 'every other month on the 15th');
$yearly = RecurrenceRule::fromArray('yearly', 1, null, null, null, '2026-03-09');
check(rr_dates($yearly, '2026-01-01', '2028-12-31') === '2026-03-09,2027-03-09,2028-03-09', 'yearly on the anniversary');
$ended = RecurrenceRule::fromArray('daily', 1, null, null, '2026-01-03', '2026-01-01');
check(rr_dates($ended, '2026-01-01', '2026-01-10') === '2026-01-01,2026-01-02,2026-01-03', 'nothing after the end date');
check($ended->matches('2026-01-04') === false && $ended->matches('2025-12-31') === false, 'matches() agrees: not after the end, not before the start');

section('nthOccurrence and nextDates');
check($daily->nthOccurrence(1) === '2026-01-05' && $daily->nthOccurrence(4) === '2026-01-14', 'the 1st and 4th of every 3 days', (string)$daily->nthOccurrence(4));
check($daily->nthOccurrence(0) === null, 'a count under 1 is nothing');
check($weekly->nthOccurrence(2, '2026-01-14') === '2026-01-21', 'counting can start from a later date');
$feb29 = RecurrenceRule::fromArray('yearly', 1, null, null, null, '2024-02-29', RecurrenceRule::MONTH_END_SKIP);
check($feb29->nthOccurrence(2) === '2028-02-29', 'a Feb 29 yearly rule that skips lands on the next leap year', (string)$feb29->nthOccurrence(2));
check(implode(',', $weekly->nextDates('2026-01-01', 3)) === '2026-01-07,2026-01-14,2026-01-21', 'the next 3 from a date before the start begin at the start');
check(implode(',', $weekly->nextDates('2026-02-01', 2)) === '2026-02-04,2026-02-11', 'the next 2 from a later date');
check($weekly->nextDates('2026-01-01', 0) === array(), 'none asked for, none given');
check(implode(',', $ended->nextDates('2026-01-01', 10)) === '2026-01-01,2026-01-02,2026-01-03', 'nextDates stops at the end date');

section('describe');
check($daily->describe() === 'Every 3 days', 'daily', $daily->describe());
check($mwf->describe() === 'Every week on Mon, Wed and Fri', 'weekly with days', $mwf->describe());
check($sunday->describe() === 'Every week on Sun', 'a Sunday-only rule says Sunday', $sunday->describe());
check($weekly->describe() === 'Every week on Wednesday', 'weekly on the start weekday', $weekly->describe());
check($second_tue->describe() === 'Every month on the 2nd Tuesday', 'monthly by weekday', $second_tue->describe());
check($last_fri->describe() === 'Every month on the last Friday', 'the last', $last_fri->describe());
check($bimonthly->describe() === 'Every 2 months on the 15th', 'monthly by day', $bimonthly->describe());
check($yearly->describe() === 'Every year on March 9th', 'yearly', $yearly->describe());
check($ended->describe() === 'Every day until Jan 3, 2026', 'with an end date', $ended->describe());

section('a day missing from a month: the one place the users differ on purpose');
$skip  = RecurrenceRule::fromArray('monthly', 1, null, null, null, '2026-01-31', RecurrenceRule::MONTH_END_SKIP);
$clamp = RecurrenceRule::fromArray('monthly', 1, null, null, null, '2026-01-31', RecurrenceRule::MONTH_END_CLAMP);
check(rr_dates($skip, '2026-01-01', '2026-05-31') === '2026-01-31,2026-03-31,2026-05-31', 'skip: no match in a month without a 31st', rr_dates($skip, '2026-01-01', '2026-05-31'));
check(rr_dates($clamp, '2026-01-01', '2026-05-31') === '2026-01-31,2026-02-28,2026-03-31,2026-04-30,2026-05-31', 'clamp: the last day of the short month', rr_dates($clamp, '2026-01-01', '2026-05-31'));
$leap_clamp = RecurrenceRule::fromArray('yearly', 1, null, null, null, '2024-02-29', RecurrenceRule::MONTH_END_CLAMP);
check(rr_dates($leap_clamp, '2024-01-01', '2026-12-31') === '2024-02-29,2025-02-28,2026-02-28', 'clamp: Feb 29 becomes Feb 28 in a common year', rr_dates($leap_clamp, '2024-01-01', '2026-12-31'));
check(rr_dates($feb29, '2024-01-01', '2026-12-31') === '2024-02-29', 'skip: Feb 29 appears only in leap years');

section('the same table through a calendar entry and an event series');
$table = array(
	array('daily', 2, null, null, null, '2026-01-05'),
	array('weekly', 1, null, null, null, '2026-01-07'),
	array('weekly', 2, '1,3,5', null, null, '2026-01-05'),
	array('weekly', 1, '0', null, null, '2026-01-07'),
	array('monthly', 1, null, null, null, '2026-01-15'),
	array('monthly', 1, null, 2, null, '2026-01-13'),
	array('monthly', 2, null, -1, null, '2026-01-30'),
	array('monthly', 1, null, null, '2026-09-30', '2026-01-15'),
	array('yearly', 1, null, null, null, '2026-03-09'),
);
$all_same = true;
$first_bad = '';
foreach ($table as $r) {
	list($type, $iv, $dow, $wom, $end, $start) = $r;
	$entry = new CalendarEntry(NULL);
	$entry->set('cal_start_local', $start . ' 21:00:00');
	$entry->set('cal_recurrence_type', $type);
	$entry->set('cal_recurrence_interval', $iv);
	$entry->set('cal_recurrence_days_of_week', $dow);
	$entry->set('cal_recurrence_week_of_month', $wom);
	$entry->set('cal_recurrence_end_date', $end);
	$event = new Event(NULL);
	$event->set('evt_timezone', 'UTC');
	$event->set('evt_start_time', $start . ' 21:00:00');
	$event->set('evt_recurrence_type', $type);
	$event->set('evt_recurrence_interval', $iv);
	$event->set('evt_recurrence_days_of_week', $dow);
	$event->set('evt_recurrence_week_of_month', $wom);
	$event->set('evt_recurrence_end_date', $end);
	$rule = RecurrenceRule::fromArray($type, $iv, $dow, $wom, $end, $start, RecurrenceRule::MONTH_END_SKIP);

	$want = $rule->datesInRange('2026-01-01', '2026-12-31');
	$got_entry = $entry->compute_dates_in_range('2026-01-01', '2026-12-31');
	$got_event = array();
	for ($d = new DateTime('2026-01-01'); $d <= new DateTime('2026-12-31'); $d->modify('+1 day')) {
		if ($event->date_matches_pattern($d->format('Y-m-d'))) { $got_event[] = $d->format('Y-m-d'); }
	}
	$next_event = $event->compute_occurrence_dates('2026-01-01', 6);
	$ok = ($got_entry === $want) && ($got_event === $want) && ($next_event === $rule->nextDates('2026-01-01', 6))
		&& ($entry->get_recurrence_description() === $rule->describe()) && ($event->get_recurrence_description() === $rule->describe())
		&& ($entry->nth_occurrence_date($start, 3) === $rule->nthOccurrence(3, $start));
	if (!$ok && $all_same) { $first_bad = json_encode($r); }
	$all_same = $all_same && $ok;
}
check($all_same, 'dates, next dates, nth occurrence and description agree across the class, CalendarEntry and Event for every rule', $first_bad);

section('the zone the start date is read in');
// An event that starts Tuesday 9pm in Los Angeles is Wednesday 04:00 UTC: it still repeats on Tuesdays.
$la = new Event(NULL);
$la->set('evt_timezone', 'America/Los_Angeles');
$la->set('evt_start_time', '2026-01-07 05:00:00');   // Tue Jan 6 21:00 PST
$la->set('evt_recurrence_type', 'weekly');
$la->set('evt_recurrence_interval', 1);
check($la->date_matches_pattern('2026-01-13') && !$la->date_matches_pattern('2026-01-14'), 'an event repeats on the weekday it starts in its own zone, not in UTC');
check($la->get_recurrence_description() === 'Every week on Tuesday', 'and says so', $la->get_recurrence_description());

section('the month-end rule each model chose');
$ev31 = new Event(NULL);
$ev31->set('evt_timezone', 'UTC'); $ev31->set('evt_start_time', '2026-01-31 12:00:00');
$ev31->set('evt_recurrence_type', 'monthly'); $ev31->set('evt_recurrence_interval', 1);
check($ev31->date_matches_pattern('2026-02-28'), 'an event series on the 31st lands on Feb 28 (clamp)');
$en31 = new CalendarEntry(NULL);
$en31->set('cal_start_local', '2026-01-31 12:00:00');
$en31->set('cal_recurrence_type', 'monthly'); $en31->set('cal_recurrence_interval', 1);
check(!$en31->date_matches_pattern('2026-02-28'), 'a calendar entry on the 31st has no Feb occurrence (skip)');

harness_finish();
