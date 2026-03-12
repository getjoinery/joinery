<?php
/**
 * PurgeOldRequestLogs - Scheduled Task
 *
 * Deletes request log entries older than a configurable number of days.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));

class PurgeOldRequestLogs implements ScheduledTaskInterface {

	public function run(array $config) {
		$days_to_keep = isset($config['days_to_keep']) ? (int)$config['days_to_keep'] : 0;
		if ($days_to_keep <= 0) {
			return array('status' => 'skipped', 'message' => 'days_to_keep not configured');
		}

		$deleted = RequestLogger::cleanup($days_to_keep);

		if ($deleted === 0) {
			return array('status' => 'success', 'message' => 'No old request logs to purge');
		}

		return array('status' => 'success', 'message' => 'Purged ' . $deleted . ' request log(s) older than ' . $days_to_keep . ' days');
	}
}
