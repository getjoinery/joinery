<?php
/** @joinery-test
 * name: schedule_model
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Phase 2.1 checkpoint: schedule data layer CRUD.
 *
 *   php tests/calendar/schedule_model_test.php
 *
 * Creates/loads a user subject's single schedule with windows and overrides,
 * and proves soft-delete removes windows/overrides from the active set.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/calendar/CalendarSubject.php'));
require_once(PathHelper::getIncludePath('data/schedule_class.php'));
require_once(PathHelper::getIncludePath('data/schedule_window_class.php'));
require_once(PathHelper::getIncludePath('data/schedule_override_class.php'));

$dblink = DbConnector::get_instance()->get_db_link();
$row = $dblink->query("SELECT usr_user_id FROM usr_users WHERE usr_delete_time IS NULL ORDER BY usr_user_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$row) { harness_skip('no users'); harness_finish(); }
$subject = CalendarSubject::user($row['usr_user_id']);

section('Schedule: one row per subject');
$schedule = Schedule::get_or_create_for_subject($subject);
ok('schedule created/loaded with an id', (bool)$schedule->key);
ok('subject type/id stored', $schedule->get('sch_subject_type') === 'user' && (int)$schedule->get('sch_subject_id') === (int)$row['usr_user_id']);
ok('timezone seeded from subject', strlen($schedule->get('sch_timezone')) > 0);
$again = Schedule::get_or_create_for_subject($subject);
ok('get_or_create returns the same single row', (int)$again->key === (int)$schedule->key);

section('Windows');
$before = (new MultiScheduleWindow(['schedule_id' => $schedule->key, 'deleted' => false]));
$before->load();
$before_count = count($before);

$win = new ScheduleWindow(NULL);
$win->set('scw_sch_schedule_id', $schedule->key);
$win->set('scw_day_of_week', 1);            // Monday
$win->set('scw_start_time', '09:00:00');
$win->set('scw_end_time', '17:00:00');
$win->save();
harness_register_row('scw_schedule_windows', 'scw_schedule_window_id', (int)$win->key);
ok('window saved with an id', (bool)$win->key);

$reload = new ScheduleWindow($win->key, true);
ok('time columns round-trip wall-clock', substr($reload->get('scw_start_time'),0,5) === '09:00' && substr($reload->get('scw_end_time'),0,5) === '17:00');

$active = new MultiScheduleWindow(['schedule_id' => $schedule->key, 'deleted' => false]);
$active->load();
ok('new window appears in active set', count($active) === $before_count + 1);

$by_day = new MultiScheduleWindow(['schedule_id' => $schedule->key, 'day_of_week' => 1, 'deleted' => false]);
$by_day->load();
ok('day_of_week filter works', count($by_day) >= 1);

section('Overrides');
$ov = new ScheduleOverride(NULL);
$ov->set('sco_sch_schedule_id', $schedule->key);
$ov->set('sco_date', '2026-12-25');
// null start/end = fully unavailable that date
$ov->save();
harness_register_row('sco_schedule_overrides', 'sco_schedule_override_id', (int)$ov->key);
ok('override saved with an id', (bool)$ov->key);
$ov_active = new MultiScheduleOverride(['schedule_id' => $schedule->key, 'deleted' => false]);
$ov_active->load();
ok('override appears in active set', count($ov_active) >= 1);

section('Soft delete removes from active set');
$win->soft_delete();
$ov->soft_delete();
$active2 = new MultiScheduleWindow(['schedule_id' => $schedule->key, 'deleted' => false]);
$active2->load();
ok('soft-deleted window gone from active set', count($active2) === $before_count);
$deleted = new MultiScheduleWindow(['schedule_id' => $schedule->key, 'deleted' => true]);
$deleted->load();
ok('soft-deleted window still present in deleted set', count($deleted) >= 1);

harness_finish();
