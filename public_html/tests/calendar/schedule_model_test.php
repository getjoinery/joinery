<?php
/**
 * Phase 2.1 checkpoint: schedule data layer CRUD.
 *
 *   php tests/calendar/schedule_model_test.php
 *
 * Creates/loads a user subject's single schedule with windows and overrides,
 * and proves soft-delete removes windows/overrides from the active set.
 */

require_once(__DIR__ . '/../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarSubject.php'));
require_once(PathHelper::getIncludePath('data/schedule_class.php'));
require_once(PathHelper::getIncludePath('data/schedule_window_class.php'));
require_once(PathHelper::getIncludePath('data/schedule_override_class.php'));

$tests = 0; $failures = 0;
function check($label, $cond) {
    global $tests, $failures; $tests++;
    echo ($cond ? "  PASS: " : "  FAIL: ") . "$label\n";
    if (!$cond) { $failures++; }
}

$dblink = DbConnector::get_instance()->get_db_link();
$row = $dblink->query("SELECT usr_user_id FROM usr_users WHERE usr_delete_time IS NULL ORDER BY usr_user_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$row) { echo "SKIP: no users\n"; exit(0); }
$subject = CalendarSubject::user($row['usr_user_id']);

echo "Schedule: one row per subject\n";
$schedule = Schedule::get_or_create_for_subject($subject);
check('schedule created/loaded with an id', (bool)$schedule->key);
check('subject type/id stored', $schedule->get('sch_subject_type') === 'user' && (int)$schedule->get('sch_subject_id') === (int)$row['usr_user_id']);
check('timezone seeded from subject', strlen($schedule->get('sch_timezone')) > 0);
$again = Schedule::get_or_create_for_subject($subject);
check('get_or_create returns the same single row', (int)$again->key === (int)$schedule->key);

echo "\nWindows\n";
$before = (new MultiScheduleWindow(['schedule_id' => $schedule->key, 'deleted' => false]));
$before->load();
$before_count = count($before);

$win = new ScheduleWindow(NULL);
$win->set('scw_sch_schedule_id', $schedule->key);
$win->set('scw_day_of_week', 1);            // Monday
$win->set('scw_start_time', '09:00:00');
$win->set('scw_end_time', '17:00:00');
$win->save();
check('window saved with an id', (bool)$win->key);

$reload = new ScheduleWindow($win->key, true);
check('time columns round-trip wall-clock', substr($reload->get('scw_start_time'),0,5) === '09:00' && substr($reload->get('scw_end_time'),0,5) === '17:00');

$active = new MultiScheduleWindow(['schedule_id' => $schedule->key, 'deleted' => false]);
$active->load();
check('new window appears in active set', count($active) === $before_count + 1);

$by_day = new MultiScheduleWindow(['schedule_id' => $schedule->key, 'day_of_week' => 1, 'deleted' => false]);
$by_day->load();
check('day_of_week filter works', count($by_day) >= 1);

echo "\nOverrides\n";
$ov = new ScheduleOverride(NULL);
$ov->set('sco_sch_schedule_id', $schedule->key);
$ov->set('sco_date', '2026-12-25');
// null start/end = fully unavailable that date
$ov->save();
check('override saved with an id', (bool)$ov->key);
$ov_active = new MultiScheduleOverride(['schedule_id' => $schedule->key, 'deleted' => false]);
$ov_active->load();
check('override appears in active set', count($ov_active) >= 1);

echo "\nSoft delete removes from active set\n";
$win->soft_delete();
$ov->soft_delete();
$active2 = new MultiScheduleWindow(['schedule_id' => $schedule->key, 'deleted' => false]);
$active2->load();
check('soft-deleted window gone from active set', count($active2) === $before_count);
$deleted = new MultiScheduleWindow(['schedule_id' => $schedule->key, 'deleted' => true]);
$deleted->load();
check('soft-deleted window still present in deleted set', count($deleted) >= 1);

echo "\n--------------------------------------\n";
echo "Total: $tests  Failures: $failures\n";
exit($failures ? 1 : 0);
