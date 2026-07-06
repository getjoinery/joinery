<?php
/**
 * PurgeOldInboundEmailLogs - Scheduled Task
 *
 * Deletes inbound email log entries older than a configurable number of days.
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class PurgeOldInboundEmailLogs implements ScheduledTaskInterface {

	public function run(array $config) {
		$days_to_keep = isset($config['days_to_keep']) ? (int)$config['days_to_keep'] : 0;
		if ($days_to_keep <= 0) {
			return array('status' => 'skipped', 'message' => 'days_to_keep not configured');
		}

		$db = DbConnector::get_instance()->get_db_link();
		$sql = "DELETE FROM iel_inbound_email_logs
				WHERE iel_create_time < NOW() - (INTERVAL '1 day' * :days)";
		$stmt = $db->prepare($sql);
		$stmt->execute([':days' => $days_to_keep]);
		$deleted = $stmt->rowCount();

		if ($deleted === 0) {
			return array('status' => 'success', 'message' => 'No old inbound email logs to purge');
		}

		return array('status' => 'success', 'message' => 'Purged ' . $deleted . ' inbound email log(s) older than ' . $days_to_keep . ' days');
	}
}
?>
