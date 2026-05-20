<?php
/**
 * PurgeOldMailboxMessages - Scheduled Task
 *
 * Hard-deletes inbound mailbox messages older than a configurable number of
 * days. Drives the retention enforcement for locally-stored inbound email.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class PurgeOldMailboxMessages implements ScheduledTaskInterface {

	public function run(array $config) {
		$days_to_keep = isset($config['days_to_keep']) ? (int)$config['days_to_keep'] : 0;
		if ($days_to_keep <= 0) {
			$settings = Globalvars::get_instance();
			$days_to_keep = (int)$settings->get_setting('inbound_email_mailbox_retention_days');
		}
		if ($days_to_keep <= 0) {
			return array('status' => 'skipped', 'message' => 'inbound_email_mailbox_retention_days not configured');
		}

		$db = DbConnector::get_instance()->get_db_link();
		$sql = "DELETE FROM iem_inbound_email_messages
				WHERE iem_received_time < NOW() - (INTERVAL '1 day' * :days)";
		$stmt = $db->prepare($sql);
		$stmt->execute([':days' => $days_to_keep]);
		$deleted = $stmt->rowCount();

		if ($deleted === 0) {
			return array('status' => 'success', 'message' => 'No old inbound mailbox messages to purge');
		}

		return array('status' => 'success', 'message' => 'Purged ' . $deleted . ' inbound mailbox message(s) older than ' . $days_to_keep . ' days');
	}
}
?>
