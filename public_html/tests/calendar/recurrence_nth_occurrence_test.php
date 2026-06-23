<?php
/**
 * Pins CalendarEntry::nth_occurrence_date() — the server-side walk that turns an
 * "ends after N occurrences" choice into a stored end date. This math used to be
 * reimplemented in browser JavaScript (the recurrence form's computeEndDateFromCount
 * / matchesPattern); that copy was deleted when the recurrence form became a
 * declarative FormWriter form, leaving this method as the single source of truth.
 *
 * A failure here means a count-based recurrence would be stored with the wrong end
 * date — the series would be too short or too long. Cases cover each frequency with
 * intervals, weekly multi-day, monthly by-day-of-month (incl. the 31st skip) and
 * by-weekday (incl. "last"), and yearly across a Feb-29 leap gap.
 *
 * No DB writes: nth_occurrence_date() reads the in-memory recurrence fields, so
 * each case is an unsaved CalendarEntry.
 *
 * Run:  php tests/calendar/recurrence_nth_occurrence_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('data/calendar_entry_class.php'));

$tests = 0; $failures = 0;
function check($label, $cond) {
    global $tests, $failures; $tests++;
    echo ($cond ? "  PASS: " : "  FAIL: ") . "$label\n";
    if (!$cond) { $failures++; }
}

/**
 * Build an unsaved recurring entry and return the Nth occurrence date.
 * @param array $rec  cal_recurrence_* values (without the cal_ prefix)
 */
function nth($anchor, $count, array $rec) {
    $e = new CalendarEntry(NULL);
    $e->set('cal_start_local',              $anchor . ' 00:00:00');
    $e->set('cal_recurrence_type',          $rec['type']);
    $e->set('cal_recurrence_interval',      $rec['interval'] ?? 1);
    $e->set('cal_recurrence_days_of_week',  $rec['days'] ?? null);
    $e->set('cal_recurrence_week_of_month', $rec['week'] ?? null);
    $e->set('cal_recurrence_end_date',      null);
    return $e->nth_occurrence_date($anchor, $count);
}

// ── Daily ──────────────────────────────────────────────────────────────────
check('daily interval 1: 5th from Jul 1 = Jul 5',
      nth('2026-07-01', 5, ['type' => 'daily']) === '2026-07-05');
check('daily interval 3: 4th from Jul 1 = Jul 10',
      nth('2026-07-01', 4, ['type' => 'daily', 'interval' => 3]) === '2026-07-10');
check('count 1 returns the anchor itself',
      nth('2026-07-01', 1, ['type' => 'daily']) === '2026-07-01');

// ── Weekly ───────────────────────────────────────────────────────────────────
check('weekly Mon+Wed: 4th from Mon Jul 6 = Wed Jul 15',
      nth('2026-07-06', 4, ['type' => 'weekly', 'days' => '1,3']) === '2026-07-15');
check('weekly interval 2 (anchor DOW): 3rd from Mon Jul 6 = Mon Aug 3',
      nth('2026-07-06', 3, ['type' => 'weekly', 'interval' => 2]) === '2026-08-03');

// ── Monthly by day-of-month ──────────────────────────────────────────────────
check('monthly 15th: 3rd from Jan 15 = Mar 15',
      nth('2026-01-15', 3, ['type' => 'monthly']) === '2026-03-15');
check('monthly 31st skips short months: 3rd from Jan 31 = May 31',
      nth('2026-01-31', 3, ['type' => 'monthly']) === '2026-05-31');

// ── Monthly by weekday ───────────────────────────────────────────────────────
check('monthly 2nd Tuesday: 3rd from Jul 14 = Sep 8',
      nth('2026-07-14', 3, ['type' => 'monthly', 'week' => 2, 'days' => '2']) === '2026-09-08');
check('monthly last Sunday: 1st from May 31 = May 31',
      nth('2026-05-31', 1, ['type' => 'monthly', 'week' => -1, 'days' => '0']) === '2026-05-31');

// ── Yearly ───────────────────────────────────────────────────────────────────
check('yearly: 3rd from Mar 10 2026 = Mar 10 2028',
      nth('2026-03-10', 3, ['type' => 'yearly']) === '2028-03-10');
check('yearly Feb 29 lands only on leap years: 2nd from 2024-02-29 = 2028-02-29',
      nth('2024-02-29', 2, ['type' => 'yearly']) === '2028-02-29');

// ── Guards ───────────────────────────────────────────────────────────────────
check('count 0 returns null', nth('2026-07-01', 0, ['type' => 'daily']) === null);
$plain = new CalendarEntry(NULL);
$plain->set('cal_start_local', '2026-07-01 00:00:00');
check('non-recurring entry returns null', $plain->nth_occurrence_date('2026-07-01', 3) === null);

echo "\n" . ($tests - $failures) . "/" . $tests . " passed\n";
exit($failures ? 1 : 0);
