<?php
/**
 * BackupRun — this site's scheduled self-backup (shim).
 *
 * The orchestration lives in BackupRunner; this is the scheduler entry point.
 * Inactive on install by design: a site with no backup target configured runs
 * nothing and warns about nothing, which is what zero-config means. Configuring
 * a target on the Backups page is what turns it on.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('includes/BackupRunner.php'));

class BackupRun implements ScheduledTaskInterface, ScheduledTaskDryRunnable {

	public function run(array $config) {
		return BackupRunner::run($config);
	}

	/**
	 * Report what a real run would do without producing or deleting anything.
	 * Worth having for a task whose failure mode is silent and whose successful
	 * operation is invisible until the day it matters.
	 */
	public function dryRun(array $config) {
		try {
			$plan = BackupRunner::plan($config);
		} catch (Exception $e) {
			return array('status' => 'skipped', 'message' => $e->getMessage());
		}

		$dir = $plan['output_dir'];

		if ($plan['mode'] === 'chain') {
			$shape = 'Would extend the current backup chain of ' . $plan['project']
				. ' (a fresh full every ' . $plan['full_days'] . ' days, or sooner if the snapshot is lost)';
			$kept = 'keeping the newest ' . $plan['keep_cloud'] . ' chains offsite, deleted whole';
		} else {
			$shape = 'Would take a full ' . $plan['type'] . ' backup of ' . $plan['project'];
			$kept = 'keeping the newest ' . $plan['keep_cloud'] . ' offsite';
		}

		$notes = array(
			$shape,
			'encrypted, sealed to the recovery key and this site',
			'uploaded to ' . $plan['target']->get('bkt_name')
				. ' under ' . rtrim((string)$plan['target']->get('bkt_path_prefix'), '/') . '/' . $plan['slug'] . '/',
			$kept,
			($plan['keep_local'] > 0
				? 'sweeping local files older than ' . $plan['keep_local'] . ' days'
				: 'never sweeping local files'),
		);

		$problems = array();
		if (!is_dir($dir)) {
			$problems[] = $dir . ' does not exist yet (it would be created)';
		} elseif (!is_writable($dir)) {
			$problems[] = $dir . ' is not writable, so a real run would fail';
		}

		return array(
			'status'  => $problems ? 'skipped' : 'success',
			'message' => implode('; ', $notes) . ($problems ? '. Problems: ' . implode('; ', $problems) : '.'),
		);
	}
}
