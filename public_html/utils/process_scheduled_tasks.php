<?php
/**
 * Scheduled Tasks Cron Runner
 *
 * A single cron entry hits this file. It is the sole timing source
 * for all scheduled tasks. The file itself decides what's due and runs it.
 *
 * Crontab (one line per site):
 * STAR/15 * * * * php /var/www/html/{sitename}/public_html/utils/process_scheduled_tasks.php >> /var/www/html/{sitename}/logs/cron_scheduled_tasks.log 2>&1
 *
 * FAILURE CONTAINMENT: one task must never take down the pass. Every task is
 * guarded against Throwable (not just Exception — a TypeError or a parse error
 * in a task file is an Error), the file load happens inside that guard, and a
 * shutdown handler catches the fatals PHP cannot. A task can fail; it cannot
 * stop the tasks ordered after it, and it cannot leave its row showing a stale
 * last-run-success.
 *
 * @version 2.0
 */

// Reject non-CLI access
if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	echo 'CLI access only.';
	exit(1);
}

// Bootstrap the application
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/scheduled_tasks_class.php'));
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('includes/ScheduledTaskRegistry.php'));

$timestamp = date('Y-m-d H:i:s');
echo "[$timestamp] Scheduled tasks cron runner started\n";

// The id of the task currently executing, read by the shutdown handler below.
// Null whenever the runner is between tasks.
$current_task_id = null;

/**
 * Last-resort status recorder.
 *
 * Registered so that a failure PHP cannot catch — an out-of-memory kill, a
 * fatal in a task's file scope, a task calling exit() — still lands on the
 * responsible row. Without it, the one class of failure that can still end the
 * run is also the one that leaves no trace: the row would keep its previous
 * successful status and admin would show the task as healthy.
 */
register_shutdown_function(function () use (&$current_task_id) {
	if ($current_task_id === null) {
		return;
	}
	$error = error_get_last();
	if (!$error || !in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
		// A task that called exit() leaves no error; it still died mid-run.
		$message = 'Task ended the process without returning a result';
	} else {
		$message = $error['message'] . ' in ' . $error['file'] . ':' . $error['line'];
	}
	try {
		$dying = new ScheduledTask($current_task_id, true);
		if ($dying->key) {
			$dying->set('sct_last_run_time', 'now()');
			$dying->set('sct_last_run_status', 'error');
			$dying->set('sct_last_run_message', mb_substr($message, 0, 500));
			$dying->save();
		}
	} catch (Throwable $e) {
		// Nothing further can be done from a shutdown handler.
	}
	echo "[" . date('Y-m-d H:i:s') . "]   FATAL: $message\n";
});

// Update the heartbeat setting
$dbconnector = DbConnector::get_instance();
$dblink = $dbconnector->get_db_link();
try {
	require_once(PathHelper::getIncludePath('data/settings_class.php'));
	Setting::put('scheduled_tasks_last_cron_run', $timestamp);
} catch (Throwable $e) {
	echo "[$timestamp] Warning: Could not update heartbeat setting: " . $e->getMessage() . "\n";
}

// Reconcile rows against the filesystem before anything tries to run them.
// A task whose code file was removed by an upgrade retires quietly here rather
// than erroring on every tick — see ScheduledTaskRegistry::reconcileMissing().
try {
	$reconciled = ScheduledTaskRegistry::reconcileMissing();
	foreach ($reconciled['retired'] as $retired_name) {
		echo "[$timestamp] Retired: $retired_name\n";
	}
} catch (Throwable $e) {
	echo "[$timestamp] Warning: reconcile failed: " . $e->getMessage() . "\n";
}

// Load all active, non-deleted tasks
$tasks = new MultiScheduledTask(
	array('active' => true, 'deleted' => false),
	array('sct_scheduled_task_id' => 'ASC')
);
$tasks->load();

$tasks_run = 0;
$tasks_skipped = 0;
$tasks_errored = 0;

foreach ($tasks as $task) {
	$task_name = $task->get('sct_name');
	$task_class = $task->get('sct_task_class');

	// Check if task is due
	if (!$task->is_due()) {
		continue;
	}

	echo "[$timestamp] Running task: $task_name ($task_class)\n";

	// Acquire a per-task advisory lock so a long-running task can't be
	// re-entered by the next cron tick. hashtext() is deterministic, so
	// the same task name always maps to the same lock. The lock auto-
	// releases when the connection closes, so a crashed PHP process
	// self-recovers on the next tick.
	$lock_acquired = false;
	try {
		$lock_q = $dblink->prepare("SELECT pg_try_advisory_lock(hashtext(:n)) AS got");
		$lock_q->execute([':n' => $task_name]);
		$lock_row = $lock_q->fetch(PDO::FETCH_ASSOC);
		$lock_acquired = !empty($lock_row['got']);
	} catch (PDOException $e) {
		echo "[$timestamp]   WARNING: Could not acquire advisory lock: " . $e->getMessage() . "\n";
	}
	if (!$lock_acquired) {
		echo "[$timestamp]   skipped: already running\n";
		$tasks_skipped++;
		continue;
	}

	try {
		// Resolve the task class file
		$task_file = $task->resolve_task_file();
		if (!$task_file) {
			// The reconcile at the top of this pass has already stamped or
			// retired this row. Absent code is a normal upgrade event, not a
			// task that ran and failed, so it is counted as neither.
			continue;
		}

		// Load and instantiate the task. The require sits inside the guard so
		// that a parse error in a task file is contained to that task.
		$current_task_id = $task->key;
		try {
			require_once($task_file);

			if (!class_exists($task_class)) {
				throw new Exception("Class $task_class not found in $task_file");
			}

			$task_instance = new $task_class();

			if (!($task_instance instanceof ScheduledTaskInterface)) {
				throw new Exception("Class $task_class does not implement ScheduledTaskInterface");
			}

			// Run the task with its config
			$config = $task->get_task_config();
			$result = $task_instance->run($config);

			// Parse result (supports string or array with status+message,
			// plus an optional 'deactivate' flag for self-deactivating tasks)
			$deactivate = false;
			if (is_array($result)) {
				$status = $result['status'] ?? 'error';
				$message = $result['message'] ?? null;
				$deactivate = !empty($result['deactivate']);
			} else {
				$status = $result;
				$message = null;
			}

			// Update task record. The message column is 500 chars — a long task
			// message must truncate, never abort the record update (an aborted
			// update reads as a task failure and eats the real message).
			$task->set('sct_last_run_time', 'now()');
			$task->set('sct_last_run_status', mb_substr((string)$status, 0, 50));
			$task->set('sct_last_run_message', $message !== null ? mb_substr((string)$message, 0, 500) : null);
			if ($deactivate) {
				$task->set('sct_is_active', false);
			}
			$task->save();

			echo "[$timestamp]   Result: $status" . ($message ? " — $message" : "") . "\n";

			if ($status === 'success') {
				$tasks_run++;
			} elseif ($status === 'skipped') {
				$tasks_skipped++;
			} else {
				$tasks_errored++;
			}
		} catch (Throwable $e) {
			// Throwable, not Exception: a TypeError, a call to a method that
			// an upgrade removed, or a ParseError in the task file is an Error
			// and would otherwise be an uncaught fatal — killing the process
			// mid-loop and silently skipping every task ordered after this one.
			echo "[$timestamp]   " . get_class($e) . ": " . $e->getMessage() . "\n";
			$task->set('sct_last_run_time', 'now()');
			$task->set('sct_last_run_status', 'error');
			$task->set('sct_last_run_message', mb_substr($e->getMessage(), 0, 500));
			$task->save();
			$tasks_errored++;
		} finally {
			$current_task_id = null;
		}
	} finally {
		try {
			$unlock_q = $dblink->prepare("SELECT pg_advisory_unlock(hashtext(:n))");
			$unlock_q->execute([':n' => $task_name]);
		} catch (PDOException $e) {
			// Lock auto-releases on connection close; non-fatal.
		}
	}
}

echo "[$timestamp] Completed: $tasks_run run, $tasks_skipped skipped, $tasks_errored errors\n";
