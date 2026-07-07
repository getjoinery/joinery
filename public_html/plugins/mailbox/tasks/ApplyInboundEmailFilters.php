<?php
/**
 * ApplyInboundEmailFilters - Scheduled Task
 *
 * Drains the "Also apply to matching existing mail" backlog (Gmail's "Also apply
 * filter to N matching conversations"). When a filter is saved with that box
 * ticked it sets fil_apply_existing_pending; this task pages through that
 * mailbox's locally-received, non-deleted stored mail in bounded batches and
 * applies the SAME matcher and actions the ingest hook uses — minus forwarding
 * (Gmail does not re-forward historical mail).
 *
 * A per-filter cursor (highest processed message id) lets a large mailbox span
 * runs without blocking a single run. When a mailbox is exhausted the pending
 * flag clears and the cursor resets.
 *
 * @see specs/implemented/inbound_email_filters.md
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php')); // declares VaultLockedException
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_filter_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));

class ApplyInboundEmailFilters implements ScheduledTaskInterface {

	public function run(array $config) {
		$batch = isset($config['batch_size']) ? (int)$config['batch_size'] : 200;
		if ($batch <= 0) { $batch = 200; }

		$pending = new MultiInboundEmailFilter(
			array('pending_backfill' => true, 'deleted' => false),
			array('fil_inbound_email_filter_id' => 'ASC')
		);
		$pending->load();
		if (!count($pending)) {
			return array('status' => 'success', 'message' => 'No filters pending backfill');
		}

		$db = DbConnector::get_instance()->get_db_link();
		$total_matched = 0;
		$total_scanned = 0;
		$filters_done = 0;

		foreach ($pending as $stub) {
			$filter = new InboundEmailFilter($stub->key, TRUE);
			if (!$filter->key) { continue; }

			$cursor = intval($filter->get('fil_apply_existing_cursor'));
			$aliasId = $filter->get('fil_iea_inbound_email_alias_id');
			$domainId = intval($filter->get('fil_ied_inbound_email_domain_id'));

			// Locally-received only: reference-backed (IMAP-sourced) rows have a
			// non-null account id and are out of scope. Scope to the alias, or to the
			// whole domain for a domain-wide filter.
			$where = array(
				'iem_iia_inbound_imap_account_id IS NULL',
				'iem_delete_time IS NULL',
				'iem_inbound_email_message_id > ?',
			);
			$params = array($cursor);
			if ($aliasId !== null) {
				$where[] = 'iem_iea_inbound_email_alias_id = ?';
				$params[] = intval($aliasId);
			} else {
				$where[] = 'iem_ied_inbound_email_domain_id = ?';
				$params[] = $domainId;
			}
			$params[] = $batch;

			$sql = "SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
					WHERE " . implode(' AND ', $where) . "
					ORDER BY iem_inbound_email_message_id ASC LIMIT ?";
			$stmt = $db->prepare($sql);
			$stmt->execute($params);
			$ids = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

			$scanned = 0;
			$matched = 0;
			$last_id = $cursor;
			foreach ($ids as $id) {
				$id = intval($id);
				$last_id = $id;
				$scanned++;
				$msg = new InboundEmailMessage($id, TRUE);
				if (!$msg->key) { continue; }
				try {
					if ($filter->matches($msg)) {
						// No re-forward on historical mail (allow_forward = false).
						$filter->applyActions($msg, array(), false);
						$matched++;
					}
				} catch (VaultLockedException $e) {
					// A cron task has no unlock window — a sealed message simply
					// can't be evaluated here. The cursor still advances past it
					// (see below); "also apply to existing mail" for sealed mail is
					// an in-window admin action, not this backlog drain.
					continue;
				}
			}

			$total_scanned += $scanned;
			$total_matched += $matched;

			if (count($ids) < $batch) {
				// Mailbox exhausted: clear the flag and reset the cursor.
				$filter->set('fil_apply_existing_pending', false);
				$filter->set('fil_apply_existing_cursor', 0);
				$filters_done++;
			} else {
				// More to do next run: advance the cursor.
				$filter->set('fil_apply_existing_cursor', $last_id);
			}
			$filter->save();
		}

		return array('status' => 'success', 'message' => sprintf(
			'Backfill: scanned %d message(s), applied to %d, completed %d of %d pending filter(s)',
			$total_scanned, $total_matched, $filters_done, count($pending)
		));
	}
}
?>
