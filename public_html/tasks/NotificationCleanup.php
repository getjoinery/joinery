<?php
/**
 * NotificationCleanup - Scheduled Task
 *
 * Permanently deletes read notifications older than a configurable number of days.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class NotificationCleanup implements ScheduledTaskInterface {

	public function run(array $config) {
		$retention_days = isset($config['retention_days']) ? (int)$config['retention_days'] : 0;
		if ($retention_days <= 0) {
			return array('status' => 'skipped', 'message' => 'retention_days not configured');
		}

		$dbconnector = DbConnector::get_instance();
		$dblink = $dbconnector->get_db_link();

		$sql = "DELETE FROM ntf_notifications
				WHERE ntf_is_read = true
				AND ntf_read_time < now() - (INTERVAL '1 day' * :days)";
		$q = $dblink->prepare($sql);
		$q->execute([':days' => $retention_days]);
		$deleted = $q->rowCount();

		if ($deleted === 0) {
			return array('status' => 'success', 'message' => 'No old notifications to purge');
		}

		return array('status' => 'success', 'message' => 'Purged ' . $deleted . ' read notification(s) older than ' . $retention_days . ' days');
	}
}
