<?php
/**
 * run_install_executor.php — drain the queued install_node jobs.
 *
 * A short-lived worker: take a single-instance lock, run every queued
 * install_node bootstrap job to completion one at a time (InstallJobExecutor),
 * then exit. Meant to be poked by the RunInstallJobs scheduled task each tick;
 * the lock makes an extra poke while one is already running a no-op, so the
 * task never has to track it.
 *
 * CLI only. Runs outside any web request on purpose — an install takes minutes,
 * and a deploy restarts PHP-FPM.
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(404);
	exit("This worker runs from the command line only.\n");
}

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/SecretBox.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/InstallJobExecutor.php'));

// Single instance. A second worker spawned while one is running exits quietly
// rather than racing it (the DB claim would keep them correct anyway; this just
// keeps one worker's log coherent).
$lock_path = sys_get_temp_dir() . '/joinery_install_executor.lock';
$lock = @fopen($lock_path, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
	fwrite(STDERR, "install executor already running; nothing to do\n");
	exit(0);
}

$ran = 0;
try {
	while (InstallJobExecutor::run_once()) {
		$ran++;
	}
} catch (Throwable $e) {
	error_log('run_install_executor: ' . $e->getMessage());
	fwrite(STDERR, 'run_install_executor error: ' . $e->getMessage() . "\n");
	flock($lock, LOCK_UN);
	exit(1);
}

flock($lock, LOCK_UN);
echo "install executor: ran {$ran} job(s)\n";
exit(0);
