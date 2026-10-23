<?php
/** @joinery-test
 * name: scheduled_task_crash_containment
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Crash containment — one task must never take down the cron pass
 * (specs/scheduled_task_consolidation.md § Part 4).
 *
 * This is the test that must not be skipped. Both failures it covers are silent
 * by nature: the evidence of them is tasks that quietly did not run, and a row
 * in admin still showing its last successful run. Nobody notices until whatever
 * that task was responsible for has been unattended for a week.
 *
 * The three ways a task can die, and what must happen to each:
 *
 *   A. A task throws an Error, not an Exception — a TypeError, a call to a
 *      method an upgrade removed. Caught as Throwable; the tasks ordered after
 *      it still run.
 *   B. A task file has a parse error. The require sits inside the guard, so
 *      ParseError is contained to that task.
 *   C. A task calls exit(), or the process is killed mid-task. The shutdown
 *      handler writes 'error' to the in-progress row, so it can never keep a
 *      stale last-run-success.
 *
 * The test drives the real runner as a subprocess, because the containment
 * lives in process-level control flow that an in-process call cannot exercise.
 * Other tasks are paused for the duration and restored afterwards.
 *
 * Run: php tests/integration/scheduled_task_crash_containment_test.php
 *
 * @version 1.2 - E: a daily task another pass ran after this pass began is not run again
 * @version 1.1 - D: a 'retry' result is recorded but not counted as a run
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/scheduled_tasks_class.php'));

$db = DbConnector::get_instance()->get_db_link();
$tasks_dir = PathHelper::getIncludePath('tasks');
$runner = PathHelper::getIncludePath('utils/process_scheduled_tasks.php');

$fixtures = array('CrashTestFatal', 'CrashTestCanary', 'CrashTestParseError', 'CrashTestExit', 'CrashTestRetry', 'CrashTestOtherPass');

// ---- fixture lifecycle ----------------------------------------------------
$write_task = function ($class, $body) use ($tasks_dir) {
	file_put_contents("$tasks_dir/$class.php", $body);
	file_put_contents("$tasks_dir/$class.json", json_encode(array(
		'name' => $class,
		'description' => 'crash containment fixture',
		'default_frequency' => 'every_run',
	)) . "\n");
	@chmod("$tasks_dir/$class.php", 0666);
	@chmod("$tasks_dir/$class.json", 0666);
};
$remove_fixtures = function () use ($tasks_dir, $fixtures, $db) {
	foreach ($fixtures as $class) {
		@unlink("$tasks_dir/$class.php");
		@unlink("$tasks_dir/$class.json");
	}
	$db->exec("DELETE FROM sct_scheduled_tasks WHERE sct_task_class LIKE 'CrashTest%'");
};
$activate = function ($class) use ($db) {
	$task = new ScheduledTask(null);
	$task->set('sct_name', $class);
	$task->set('sct_task_class', $class);
	$task->set('sct_is_active', true);
	$task->set('sct_frequency', 'every_run');
	$task->set('sct_last_run_status', 'success');
	$task->set('sct_last_run_message', 'STALE - should be overwritten');
	$task->set('sct_last_run_time', 'now()');
	$task->save();
	return (int)$task->key;
};
$status_of = function ($class) use ($db) {
	$q = $db->prepare("SELECT sct_last_run_status, sct_last_run_message
		FROM sct_scheduled_tasks WHERE sct_task_class = ?");
	$q->execute(array($class));
	return $q->fetch(PDO::FETCH_ASSOC) ?: array();
};

$remove_fixtures();

// Pause every other active task so the subprocess runs only the fixtures.
// Record exactly which ids were paused, so the restore puts back those and only
// those — a blanket "reactivate everything inactive" would switch on tasks the
// operator had deliberately turned off.
$paused_ids = array_map('intval', $db->query(
	"SELECT sct_scheduled_task_id FROM sct_scheduled_tasks
	  WHERE sct_is_active = true AND sct_delete_time IS NULL")->fetchAll(PDO::FETCH_COLUMN));
if ($paused_ids) {
	$db->exec("UPDATE sct_scheduled_tasks SET sct_is_active = false
		WHERE sct_scheduled_task_id IN (" . implode(',', $paused_ids) . ")");
}

try {
	// -----------------------------------------------------------------------
	section('A. An Error (not an Exception) is contained, and the next task runs');

	// A TypeError is the realistic shape: an upgrade changes a signature a task
	// depends on. Before the fix this was an uncaught fatal — the process died
	// mid-loop, every later task silently never ran, and this row kept saying
	// "success" from its previous run.
	$write_task('CrashTestFatal', <<<'PHP'
<?php
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
class CrashTestFatal implements ScheduledTaskInterface {
	public function run(array $config) {
		$notAnObject = null;
		return $notAnObject->methodThatCannotExist();
	}
}
PHP
	);
	$write_task('CrashTestCanary', <<<'PHP'
<?php
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
class CrashTestCanary implements ScheduledTaskInterface {
	public function run(array $config) {
		return array('status' => 'success', 'message' => 'canary ran');
	}
}
PHP
	);

	// Creation order sets sct_scheduled_task_id, which is the runner's order —
	// the canary must be AFTER the crasher for this to prove anything.
	$activate('CrashTestFatal');
	$activate('CrashTestCanary');

	$output = array();
	$exit_code = 0;
	exec('php ' . escapeshellarg($runner) . ' 2>&1', $output, $exit_code);
	$log = implode("\n", $output);

	$fatal = $status_of('CrashTestFatal');
	$canary = $status_of('CrashTestCanary');

	check($fatal['sct_last_run_status'] === 'error',
		'the crashing task is recorded as error', json_encode($fatal));
	check(strpos((string)$fatal['sct_last_run_message'], 'STALE') === false,
		'its stale last-run-success was overwritten', (string)$fatal['sct_last_run_message']);
	check($canary['sct_last_run_status'] === 'success' && $canary['sct_last_run_message'] === 'canary ran',
		'THE NEXT TASK STILL RAN', json_encode($canary));
	check(strpos($log, 'Completed:') !== false,
		'the pass printed its summary line rather than dying mid-loop', $log);

	// -----------------------------------------------------------------------
	section('B. A parse error in a task file is contained the same way');

	$db->exec("DELETE FROM sct_scheduled_tasks WHERE sct_task_class LIKE 'CrashTest%'");
	@unlink("$tasks_dir/CrashTestFatal.php");
	@unlink("$tasks_dir/CrashTestFatal.json");

	// Deliberately unparseable. This is what a half-written or truncated file
	// looks like after a bad deploy.
	$write_task('CrashTestParseError', "<?php\nclass CrashTestParseError { public function run(array \$c) { \n");
	$activate('CrashTestParseError');
	$activate('CrashTestCanary');

	$output = array();
	exec('php ' . escapeshellarg($runner) . ' 2>&1', $output, $exit_code);
	$log = implode("\n", $output);

	$parse = $status_of('CrashTestParseError');
	$canary = $status_of('CrashTestCanary');

	check($parse['sct_last_run_status'] === 'error',
		'the unparseable task is recorded as error', json_encode($parse));
	check($canary['sct_last_run_status'] === 'success',
		'the next task still ran', json_encode($canary));
	check(strpos($log, 'Completed:') !== false, 'the pass completed', $log);

	// -----------------------------------------------------------------------
	section('C. A task that ends the process leaves an honest row, not a stale one');

	$db->exec("DELETE FROM sct_scheduled_tasks WHERE sct_task_class LIKE 'CrashTest%'");
	@unlink("$tasks_dir/CrashTestParseError.php");
	@unlink("$tasks_dir/CrashTestParseError.json");

	// exit() is uncatchable. Only the shutdown handler can record it, and
	// without that the row would still read "success" from last time — the
	// worst outcome, because admin would show the task as healthy.
	$write_task('CrashTestExit', <<<'PHP'
<?php
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
class CrashTestExit implements ScheduledTaskInterface {
	public function run(array $config) {
		exit(0);
	}
}
PHP
	);
	$activate('CrashTestExit');

	$output = array();
	exec('php ' . escapeshellarg($runner) . ' 2>&1', $output, $exit_code);

	$exited = $status_of('CrashTestExit');
	check($exited['sct_last_run_status'] === 'error',
		'the row is marked error by the shutdown handler', json_encode($exited));
	check(strpos((string)$exited['sct_last_run_message'], 'STALE') === false,
		'it does NOT still read as the previous successful run',
		(string)$exited['sct_last_run_message']);

	// -----------------------------------------------------------------------
	section('D. A task that cannot run yet says retry, and is due again next tick');

	$db->exec("DELETE FROM sct_scheduled_tasks WHERE sct_task_class LIKE 'CrashTest%'");
	$write_task('CrashTestRetry', <<<'PHP'
<?php
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
class CrashTestRetry implements ScheduledTaskInterface {
	public function run(array $config) {
		return array('status' => 'retry', 'message' => 'not ready yet');
	}
}
PHP
	);
	$retry_id = $activate('CrashTestRetry');
	$db->prepare("UPDATE sct_scheduled_tasks SET sct_frequency = 'daily', sct_schedule_time = '00:00:00',
		sct_last_run_time = '2026-01-01 00:00:00' WHERE sct_scheduled_task_id = ?")->execute(array($retry_id));

	$output = array();
	exec('php ' . escapeshellarg($runner) . ' 2>&1', $output, $exit_code);

	$retried = $status_of('CrashTestRetry');
	$q = $db->prepare("SELECT sct_last_run_time FROM sct_scheduled_tasks WHERE sct_scheduled_task_id = ?");
	$q->execute(array($retry_id));
	$last_run = (string)$q->fetchColumn();
	check($retried['sct_last_run_status'] === 'retry' && $retried['sct_last_run_message'] === 'not ready yet',
		'the row says retry, with the task\'s message', json_encode($retried));
	check(strpos($last_run, '2026-01-01') === 0, 'its last run time is not moved: it did not run', $last_run);
	check((new ScheduledTask($retry_id, TRUE))->is_due(), 'so a daily task is still due, and the next tick runs it');

	// -----------------------------------------------------------------------
	section('E. A daily task another pass ran after this pass began is not run again');

	// The first fixture records the daily one as run, as a second, overlapping
	// pass would while this one is held up; the daily one is due when the pass
	// loads its rows and must not run once its turn comes.
	$db->exec("DELETE FROM sct_scheduled_tasks WHERE sct_task_class LIKE 'CrashTest%'");
	@unlink("$tasks_dir/CrashTestRetry.php");
	@unlink("$tasks_dir/CrashTestRetry.json");
	$write_task('CrashTestOtherPass', <<<'PHP'
<?php
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
class CrashTestOtherPass implements ScheduledTaskInterface {
	public function run(array $config) {
		DbConnector::get_instance()->get_db_link()->exec("UPDATE sct_scheduled_tasks
			SET sct_last_run_time = now(), sct_last_run_message = 'run by the other pass'
			WHERE sct_task_class = 'CrashTestCanary'");
		return array('status' => 'success', 'message' => 'other pass done');
	}
}
PHP
	);
	$activate('CrashTestOtherPass');
	$daily_id = $activate('CrashTestCanary');
	$db->prepare("UPDATE sct_scheduled_tasks SET sct_frequency = 'daily', sct_schedule_time = '00:00:00',
		sct_last_run_time = '2026-01-01 00:00:00' WHERE sct_scheduled_task_id = ?")->execute(array($daily_id));

	$output = array();
	exec('php ' . escapeshellarg($runner) . ' 2>&1', $output, $exit_code);
	$log = implode("\n", $output);

	$daily = $status_of('CrashTestCanary');
	check($daily['sct_last_run_message'] === 'run by the other pass',
		'the daily task did not run a second time', json_encode($daily));
	check(strpos($log, 'skipped: another pass ran it since this one began') !== false,
		'the log says why it was skipped', $log);

} finally {
	$remove_fixtures();
	// Restore exactly the tasks this test paused, by id.
	if ($paused_ids) {
		$db->exec("UPDATE sct_scheduled_tasks SET sct_is_active = true
			WHERE sct_scheduled_task_id IN (" . implode(',', $paused_ids) . ")");
	}
}

harness_finish();
