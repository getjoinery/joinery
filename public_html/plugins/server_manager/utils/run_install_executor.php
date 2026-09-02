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
 *
 * The lock lives under the site's own logs directory, not the system temp
 * dir: a lock file in /tmp belongs to whoever created it first, and a worker
 * run by hand as one user would then lock the cron's user out for good — and
 * silently, since 'cannot open the lock' would read as 'already running'.
 * Those two are told apart below: one is a quiet exit, the other an error.
 *
 * @version 1.2 - logs each claim, finish and empty-queue check with a timestamp, so a worker that
 *                holds the lock without working can be seen doing it
 * @version 1.1 - lock under the site's logs dir; an unopenable lock is an error, not 'already running'
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
$lock_path = PathHelper::getSiteRoot() . '/logs/install_executor.lock';
$lock = @fopen($lock_path, 'c');
if ($lock === false) {
	$owner = file_exists($lock_path) ? (posix_getpwuid((int)fileowner($lock_path))['name'] ?? (string)fileowner($lock_path)) : 'nobody';
	$me = posix_getpwuid(posix_geteuid())['name'] ?? (string)posix_geteuid();
	$msg = "install executor: cannot open the lock file {$lock_path} as {$me} (owned by {$owner}); "
		. "queued installs will not run until it is writable by the scheduled-task user";
	error_log($msg);
	fwrite(STDERR, $msg . "\n");
	exit(1);
}
// Whoever created it, the next user must be able to open it: an operator's
// hand run must never lock the scheduled-task user out.
@chmod($lock_path, 0666);
if (!flock($lock, LOCK_EX | LOCK_NB)) {
	fwrite(STDERR, "install executor already running; nothing to do\n");
	exit(0);
}

$ran = 0;
$say = function ($line) { echo gmdate('Y-m-d H:i:s'), ' ', $line, "\n"; flush(); };
$say('worker started (pid ' . getmypid() . ')');
try {
	while (true) {
		$job = InstallJobExecutor::claim_next();
		if ($job === null) {
			$say('queue empty; exiting after ' . $ran . ' job(s)');
			break;
		}
		$say('claimed job #' . $job->key . ' for node #' . (int)$job->get('mjb_mgn_node_id'));
		(new InstallJobExecutor())->execute($job);
		$job->load();
		$say('finished job #' . $job->key . ': ' . $job->get('mjb_status'));
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
