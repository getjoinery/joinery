<?php
/** @joinery-test
 * name: scheduled_task_retirement
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Retirement — a task disappearing is a normal upgrade event
 * (specs/scheduled_task_consolidation.md § Part 4).
 *
 * Every future upgrade may remove or rename a task, and a production site must
 * absorb that without an error and without a crash. The rule:
 *
 *   Absent code means the task retires. It never means an error, and it never
 *   stops the run.
 *
 * What that costs, and what this test guards:
 *
 *   A. A first miss proves nothing. A file can be absent mid-deploy or during a
 *      plugin sync, so the first miss only stamps a timestamp.
 *   B. A file that comes back clears the stamp, with no trace and no operator
 *      action.
 *   C. Past the grace window the row retires: deactivated, status 'retired',
 *      and NOT soft-deleted — the schedule and config survive, so being wrong
 *      about a rename costs a click rather than a reconstruction.
 *   D. A deploy skips the grace, because the filesystem is authoritative the
 *      moment the deploy finishes.
 *   E. A 'replaces' entry names the successor, so the row reads "Superseded
 *      by ..." rather than a message implying something broke.
 *
 * Run: php tests/integration/scheduled_task_retirement_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/ScheduledTaskRegistry.php'));
require_once(PathHelper::getIncludePath('data/scheduled_tasks_class.php'));

$db = DbConnector::get_instance()->get_db_link();

// A row whose class has no file on disk — exactly what an upgrade leaves behind.
$ghost_class = 'RetirementTestGhostTask';
$db->prepare("DELETE FROM sct_scheduled_tasks WHERE sct_task_class = ?")->execute(array($ghost_class));

$make_ghost = function () use ($db, $ghost_class) {
	$db->prepare("DELETE FROM sct_scheduled_tasks WHERE sct_task_class = ?")->execute(array($ghost_class));
	$task = new ScheduledTask(null);
	$task->set('sct_name', 'Retirement Test Ghost');
	$task->set('sct_task_class', $ghost_class);
	$task->set('sct_is_active', true);
	$task->set('sct_frequency', 'daily');
	$task->set('sct_schedule_time', '05:30:00');
	$task->set('sct_task_config', json_encode(array('operator_setting' => 'do not lose me')));
	$task->save();
	return $task;
};
$reload = function () use ($db, $ghost_class) {
	$q = $db->prepare("SELECT * FROM sct_scheduled_tasks WHERE sct_task_class = ?");
	$q->execute(array($ghost_class));
	return $q->fetch(PDO::FETCH_ASSOC);
};

// ---------------------------------------------------------------------------
section('A. A first miss stamps, and changes nothing else');

$make_ghost();
$result = ScheduledTaskRegistry::reconcileMissing();
$row = $reload();

check($row['sct_missing_since'] !== null, 'the first miss stamps sct_missing_since');
check((bool)$row['sct_is_active'] === true, 'the task is still active after one miss');
check($row['sct_last_run_status'] === null, 'no status was written — a single miss is not news');
check(in_array('Retirement Test Ghost', $result['pending'], true),
	'the reconcile reports it as pending, not retired', json_encode($result));
check(empty($result['retired']), 'nothing retired on the first pass');

// ---------------------------------------------------------------------------
section('B. A file that comes back clears the stamp');

// Standing in for the file returning: point the row at a class that does exist.
$db->prepare("UPDATE sct_scheduled_tasks SET sct_task_class = 'RetentionSweep' WHERE sct_task_class = ?")
	->execute(array($ghost_class));
ScheduledTaskRegistry::reconcileMissing();
$q = $db->prepare("SELECT sct_missing_since, sct_last_run_status FROM sct_scheduled_tasks
	WHERE sct_name = 'Retirement Test Ghost'");
$q->execute();
$recovered = $q->fetch(PDO::FETCH_ASSOC);
check($recovered['sct_missing_since'] === null, 'the stamp is cleared — the transient self-healed');
check($recovered['sct_last_run_status'] === null, 'and it left no trace an operator has to read');

$db->prepare("DELETE FROM sct_scheduled_tasks WHERE sct_name = 'Retirement Test Ghost'")->execute();

// ---------------------------------------------------------------------------
section('C. Missing past the grace window retires, preserving the row');

$make_ghost();
ScheduledTaskRegistry::reconcileMissing();
// Backdate the stamp past the window rather than waiting an hour for it.
$db->prepare("UPDATE sct_scheduled_tasks
	SET sct_missing_since = now() - interval '2 hours' WHERE sct_task_class = ?")
	->execute(array($ghost_class));

$result = ScheduledTaskRegistry::reconcileMissing();
$row = $reload();

check(in_array('Retirement Test Ghost', $result['retired'], true),
	'the reconcile reports the retirement', json_encode($result));
check((bool)$row['sct_is_active'] === false, 'the task is deactivated');
check($row['sct_last_run_status'] === 'retired', 'the status is retired, not error');
check($row['sct_delete_time'] === null, 'the row is NOT soft-deleted');
check($row['sct_schedule_time'] !== null && substr((string)$row['sct_schedule_time'], 0, 5) === '05:30',
	'the operator schedule survived retirement', (string)$row['sct_schedule_time']);
check(strpos((string)$row['sct_task_config'], 'do not lose me') !== false,
	'the operator config survived retirement', (string)$row['sct_task_config']);
check($row['sct_missing_since'] === null, 'the stamp is cleared once the decision is made');

// ---------------------------------------------------------------------------
section('D. A deploy retires immediately, without waiting out the grace');

$make_ghost();
$result = ScheduledTaskRegistry::reconcileMissing(true);
$row = $reload();
check(in_array('Retirement Test Ghost', $result['retired'], true),
	'skip_grace retires on the first pass', json_encode($result));
check($row['sct_last_run_status'] === 'retired',
	'so the first cron tick after an upgrade is already clean');

// ---------------------------------------------------------------------------
section('E. A replaces entry names the successor');

// The twelve purge tasks this consolidation removed are all named in
// RetentionSweep.json, so a site upgrading sees supersession, not breakage.
$db->prepare("DELETE FROM sct_scheduled_tasks WHERE sct_task_class = 'PurgeOldErrors'")->execute();
$superseded = new ScheduledTask(null);
$superseded->set('sct_name', 'Purge old errors');
$superseded->set('sct_task_class', 'PurgeOldErrors');
$superseded->set('sct_is_active', true);
$superseded->set('sct_frequency', 'daily');
$superseded->save();

ScheduledTaskRegistry::reconcileMissing(true);
$q = $db->prepare("SELECT sct_last_run_status, sct_last_run_message FROM sct_scheduled_tasks
	WHERE sct_task_class = 'PurgeOldErrors'");
$q->execute();
$sup = $q->fetch(PDO::FETCH_ASSOC);

check($sup['sct_last_run_status'] === 'retired', 'a superseded task retires');
check(strpos((string)$sup['sct_last_run_message'], 'Superseded by Retention Sweep') === 0,
	'and says what replaced it rather than implying a fault',
	(string)$sup['sct_last_run_message']);

// ---------------------------------------------------------------------------
section('F. Retirement is not an error');

// The point of the whole design: a site that just upgraded reports zero errors.
// reconcileMissing returns only retired/pending — there is no error channel to
// leak into the cron summary, and the runner counts a missing file as neither
// run nor error.
check(!array_key_exists('errors', $result),
	'the reconcile has no error channel at all', json_encode(array_keys($result)));

$db->prepare("DELETE FROM sct_scheduled_tasks WHERE sct_task_class IN (?, 'PurgeOldErrors')")
	->execute(array($ghost_class));

harness_finish();
