<?php
/**
 * PurgeOldMailboxMessages - Scheduled Task
 *
 * Hard-deletes inbound mailbox messages older than a configurable number of
 * days. Drives the retention enforcement for locally-stored inbound email.
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));

class PurgeOldMailboxMessages implements ScheduledTaskInterface {

	public function run(array $config) {
		$days_to_keep = isset($config['days_to_keep']) ? (int)$config['days_to_keep'] : 0;
		if ($days_to_keep <= 0) {
			$settings = Globalvars::get_instance();
			$days_to_keep = (int)$settings->get_setting('mailbox_retention_days');
		}
		if ($days_to_keep <= 0) {
			return array('status' => 'skipped', 'message' => 'mailbox_retention_days not configured');
		}

		// Select the expired ids, then delete each through the model so the
		// deletion strategy runs per row (child manifest rows cascade, and any
		// stored raw bytes are reclaimed by the message hard-delete hook). A bulk
		// SQL DELETE would skip all of that and orphan off-row data.
		$db = DbConnector::get_instance()->get_db_link();
		$sql = "SELECT iem_inbound_email_message_id
				FROM iem_inbound_email_messages
				WHERE iem_received_time < NOW() - (INTERVAL '1 day' * :days)";
		$stmt = $db->prepare($sql);
		$stmt->execute([':days' => $days_to_keep]);
		$ids = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

		if (!$ids) {
			return array('status' => 'success', 'message' => 'No old inbound mailbox messages to purge');
		}

		$deleted = 0;
		foreach ($ids as $id) {
			$message = new InboundEmailMessage($id, TRUE);
			$message->permanent_delete();
			$deleted++;
		}

		return array('status' => 'success', 'message' => 'Purged ' . $deleted . ' inbound mailbox message(s) older than ' . $days_to_keep . ' days');
	}
}
?>
