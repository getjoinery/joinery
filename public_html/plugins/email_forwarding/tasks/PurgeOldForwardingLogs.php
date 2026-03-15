<?php
/**
 * PurgeOldForwardingLogs - Scheduled Task
 *
 * Deletes email forwarding log entries older than a configurable number of days.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class PurgeOldForwardingLogs implements ScheduledTaskInterface {

	public function run(array $config) {
		$days_to_keep = isset($config['days_to_keep']) ? (int)$config['days_to_keep'] : 0;
		if ($days_to_keep <= 0) {
			return array('status' => 'skipped', 'message' => 'days_to_keep not configured');
		}

		$db = DbConnector::get_instance()->get_db_link();
		$sql = "DELETE FROM efl_email_forwarding_logs
				WHERE efl_create_time < NOW() - (INTERVAL '1 day' * :days)";
		$stmt = $db->prepare($sql);
		$stmt->execute([':days' => $days_to_keep]);
		$deleted = $stmt->rowCount();

		if ($deleted === 0) {
			return array('status' => 'success', 'message' => 'No old forwarding logs to purge');
		}

		return array('status' => 'success', 'message' => 'Purged ' . $deleted . ' forwarding log(s) older than ' . $days_to_keep . ' days');
	}
}
?>
