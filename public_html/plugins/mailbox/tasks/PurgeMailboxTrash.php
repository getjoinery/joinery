<?php
/**
 * PurgeMailboxTrash - Scheduled Task
 *
 * Permanently deletes mail that has sat in Trash longer than the retention
 * window (specs/mailbox_trash_folder.md). Trashing is column-driven
 * (iem_delete_time); this is the only thing that ever makes it final.
 *
 * Every row goes through InboundEmailMessage::permanent_delete(), which reclaims
 * the attachment Files and the stored raw object. A bulk DELETE would drop the
 * row in one statement and leak both, which is why the loop is row-by-row.
 *
 * Sealed mailboxes purge locked: permanent_delete() works on columns and storage
 * keys, never on plaintext, so a Fortress mailbox needs no vault window here.
 * Each id is queued for refold first so the owner's sealed search index drops
 * the entry at their next fold.
 *
 * The window comes from this task's own days_to_keep when set, otherwise the
 * mailbox_trash_retention_days setting — task config wins, so one deployment can
 * run a different window without touching the setting the reader shows.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class PurgeMailboxTrash implements ScheduledTaskInterface {

	/** Default backlog cap per run, so a long-neglected Trash drains over several runs. */
	const DEFAULT_MAX_PER_RUN = 500;

	public function run(array $config) {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxIndex.php'));
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxService.php'));
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));

		// Task config wins over the setting; 0 in either place means never purge.
		$days = (isset($config['days_to_keep']) && $config['days_to_keep'] !== '')
			? (int)$config['days_to_keep']
			: MailboxService::trashRetentionDays();
		if ($days <= 0) {
			return array('status' => 'skipped', 'message' => 'Trash retention is off (0 days) — nothing purges');
		}

		$cap = isset($config['max_per_run']) ? (int)$config['max_per_run'] : self::DEFAULT_MAX_PER_RUN;
		if ($cap <= 0) {
			$cap = self::DEFAULT_MAX_PER_RUN;
		}
		$report_only = !empty($config['report_only']) && $config['report_only'] !== '0';

		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"SELECT iem_inbound_email_message_id AS id, iem_iea_inbound_email_alias_id AS alias_id
			   FROM iem_inbound_email_messages
			  WHERE iem_delete_time IS NOT NULL
			    AND iem_delete_time < now() - (INTERVAL '1 day' * :days)
			  ORDER BY iem_delete_time ASC
			  LIMIT :cap");
		$stmt->bindValue(':days', $days, PDO::PARAM_INT);
		$stmt->bindValue(':cap', $cap, PDO::PARAM_INT);
		$stmt->execute();
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		if (!count($rows)) {
			return array('status' => 'success',
				'message' => 'No mail in Trash past the ' . $days . '-day window');
		}

		if ($report_only) {
			return array('status' => 'success',
				'message' => 'Report only: ' . count($rows) . ' message(s) are past the '
					. $days . '-day window and would be permanently deleted');
		}

		$purged = 0;
		$failed = 0;
		foreach ($rows as $row) {
			$id = (int)$row['id'];
			$alias_id = (int)$row['alias_id'];
			try {
				// Queue the refold BEFORE the row goes: the refold pass re-inserts only
				// if the message still exists, so a purged id drops out of the sealed
				// index at the owner's next fold. Needs no vault.
				if ($alias_id > 0) {
					MailboxIndex::enqueueRefold($alias_id, $id);
				}
				$message = new InboundEmailMessage($id, TRUE);
				if (!$message->key) {
					continue;
				}
				$message->permanent_delete();
				$purged++;
			} catch (Throwable $e) {
				// One unreclaimable message must not strand the rest of the backlog.
				$failed++;
				error_log('PurgeMailboxTrash: purge failed for message ' . $id . ': ' . $e->getMessage());
			}
		}

		$message = 'Purged ' . $purged . ' message(s) trashed over ' . $days . ' days ago';
		if (count($rows) >= $cap) {
			$message .= ' (hit the ' . $cap . '-per-run cap — the rest drains on the next run)';
		}
		if ($failed) {
			$message .= '; ' . $failed . ' failed (see the error log)';
		}
		return array('status' => 'success', 'message' => $message);
	}
}
?>
