<?php
/**
 * RunInstallJobs - Poke the plane-side install executor each tick.
 *
 * A machine we create has no agent for its first install, so that job
 * (install_node), and the retire_install_password job that closes the
 * bootstrap, are run from the plane over the provision's sealed root password
 * by InstallJobExecutor (specs/keyless_provisioning.md). Those jobs
 * are created in status 'queued', which the node-agent local queue never
 * claims.
 *
 * An install runs for minutes, longer than a scheduled-task tick should hold
 * the shared cron process, so this task does not run the install itself. It
 * spawns the short-lived worker (utils/run_install_executor.php) detached and
 * returns at once. The worker takes a single-instance lock, so a spawn while
 * one is already running is harmless.
 *
 * @version 1.1 - Counts every BOOTSTRAP_JOB_TYPES job, not just install_node: a queued
 *                retire_install_password job with no install alongside it never woke the
 *                worker, so no password was ever retired.
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class RunInstallJobs implements ScheduledTaskInterface {

	public function run(array $config) {
		$db = DbConnector::get_instance()->get_db_link();
		$types = "'" . implode("','", ManagementJob::BOOTSTRAP_JOB_TYPES) . "'";
		$queued = (int)$db->query(
			"SELECT COUNT(*) FROM mjb_management_jobs " .
			"WHERE mjb_status = 'queued' AND mjb_job_type IN ({$types}) AND mjb_delete_time IS NULL"
		)->fetchColumn();

		if ($queued === 0) {
			return array('status' => 'success', 'message' => 'No queued bootstrap jobs.');
		}

		$worker = PathHelper::getIncludePath('plugins/server_manager/utils/run_install_executor.php');
		if (!is_file($worker)) {
			return array('status' => 'error', 'message' => 'Install executor worker not found at ' . $worker);
		}

		// Detached: the worker outlives this tick and is unaffected by any
		// per-task timeout. Its own lock keeps a second spawn from racing it.
		$log = PathHelper::getSiteRoot() . '/logs/install_executor.log';
		$cmd = 'nohup ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker)
			. ' >> ' . escapeshellarg($log) . ' 2>&1 &';
		exec($cmd);

		return array('status' => 'success',
			'message' => "Spawned the install executor for {$queued} queued job(s).");
	}
}
