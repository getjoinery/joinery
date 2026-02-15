<?php
/**
 * PurgeOldErrors - Scheduled Task
 *
 * Deletes general error log entries older than a configurable number of days.
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class PurgeOldErrors implements ScheduledTaskInterface {

	public function run(array $config) {
		$days_to_keep = isset($config['days_to_keep']) ? (int)$config['days_to_keep'] : 0;
		if ($days_to_keep <= 0) {
			return array('status' => 'skipped', 'message' => 'days_to_keep not configured');
		}

		$dbconnector = DbConnector::get_instance();
		$dblink = $dbconnector->get_db_link();

		$sql = "DELETE FROM err_general_errors WHERE err_create_time < now() - (INTERVAL '1 day' * :days)";
		$q = $dblink->prepare($sql);
		$q->execute([':days' => $days_to_keep]);
		$deleted = $q->rowCount();

		if ($deleted === 0) {
			return array('status' => 'success', 'message' => 'No old errors to purge');
		}

		return array('status' => 'success', 'message' => 'Purged ' . $deleted . ' error(s) older than ' . $days_to_keep . ' days');
	}
}
