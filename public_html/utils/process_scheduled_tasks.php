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
 * @version 1.1
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

$timestamp = date('Y-m-d H:i:s');
echo "[$timestamp] Scheduled tasks cron runner started\n";

// Update the heartbeat setting
$dbconnector = DbConnector::get_instance();
$dblink = $dbconnector->get_db_link();
try {
	$sql = "UPDATE stg_settings SET stg_value = :ts WHERE stg_name = 'scheduled_tasks_last_cron_run'";
	$q = $dblink->prepare($sql);
	$q->execute([':ts' => $timestamp]);
	if ($q->rowCount() === 0) {
		// Setting doesn't exist yet, insert it
		$sql = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) VALUES ('scheduled_tasks_last_cron_run', :ts, 1, now(), now(), 'system')";
		$q = $dblink->prepare($sql);
		$q->execute([':ts' => $timestamp]);
	}
} catch (PDOException $e) {
	echo "[$timestamp] Warning: Could not update heartbeat setting: " . $e->getMessage() . "\n";
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

	// Resolve the task class file
	$task_file = $task->resolve_task_file();
	if (!$task_file) {
		echo "[$timestamp]   ERROR: Could not resolve class file for $task_class\n";
		$task->set('sct_last_run_time', 'now()');
		$task->set('sct_last_run_status', 'error');
		$task->set('sct_last_run_message', 'Could not resolve class file');
		$task->save();
		$tasks_errored++;
		continue;
	}

	// Load and instantiate the task
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

		// Parse result (supports string or array with status+message)
		if (is_array($result)) {
			$status = $result['status'] ?? 'error';
			$message = $result['message'] ?? null;
		} else {
			$status = $result;
			$message = null;
		}

		// Update task record
		$task->set('sct_last_run_time', 'now()');
		$task->set('sct_last_run_status', $status);
		$task->set('sct_last_run_message', $message);
		$task->save();

		echo "[$timestamp]   Result: $status" . ($message ? " — $message" : "") . "\n";

		if ($status === 'success') {
			$tasks_run++;
		} elseif ($status === 'skipped') {
			$tasks_skipped++;
		} else {
			$tasks_errored++;
		}
	} catch (Exception $e) {
		echo "[$timestamp]   EXCEPTION: " . $e->getMessage() . "\n";
		$task->set('sct_last_run_time', 'now()');
		$task->set('sct_last_run_status', 'error');
		$task->set('sct_last_run_message', substr($e->getMessage(), 0, 500));
		$task->save();
		$tasks_errored++;
	}
}

echo "[$timestamp] Completed: $tasks_run run, $tasks_skipped skipped, $tasks_errored errors\n";
