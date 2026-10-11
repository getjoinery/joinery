<?php
/**
 * BoardsDueReminders - Scheduled Task
 *
 * Finds cards and checklist items that are not done, whose due time falls inside
 * the reminder window (boards_due_reminder_hours, default 24) and which have not
 * been reminded about that due time, and dispatches boards.due_soon to the people
 * concerned: a card's members, or the person an item is assigned to. It then
 * records the due time it reminded about, so:
 *
 *  - a reminder goes out once, however often this task runs;
 *  - it goes out again only if the due date moves.
 *
 * Done, archived, deleted and template cards are never reminded about, nor cards
 * on a board that is archived, deleted or a template. A person who can no longer
 * open the board is not told (the notifier checks).
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class BoardsDueReminders implements ScheduledTaskInterface {

	const DEFAULT_BATCH_SIZE = 500;

	public function run(array $config) {
		if (!Globalvars::get_instance()->get_setting('boards_active', true, true)) {
			return array('status' => 'skipped', 'message' => 'Boards is turned off.');
		}
		$batch = (int)($config['batch_size'] ?? 0);
		if ($batch <= 0) {
			$batch = self::DEFAULT_BATCH_SIZE;
		}
		$hours = Boards::settingInt('boards_due_reminder_hours', 24);

		$cards = $this->remindCards($hours, $batch);
		$items = $this->remindItems($hours, $batch);

		return array('status' => 'success', 'message' => $cards . ' card reminder(s), ' . $items . ' checklist item reminder(s) sent.');
	}

	/** Reminders for cards; returns how many were sent. */
	private function remindCards(int $hours, int $batch): int {
		$q = DbConnector::GetPreparedStatement(
			"SELECT bcd.bcd_card_id, bcd.bcd_due_time FROM bcd_cards bcd
			 JOIN bbd_boards bbd ON bbd.bbd_board_id = bcd.bcd_bbd_board_id
			 JOIN bls_lists bls ON bls.bls_list_id = bcd.bcd_bls_list_id AND bls.bls_archived_time IS NULL
			 WHERE bcd.bcd_is_done = false AND bcd.bcd_is_template = false
			   AND bcd.bcd_delete_time IS NULL AND bcd.bcd_archived_time IS NULL
			   AND bbd.bbd_delete_time IS NULL AND bbd.bbd_archived_time IS NULL AND bbd.bbd_is_template = false
			   AND bcd.bcd_due_time IS NOT NULL
			   AND bcd.bcd_due_time > now() AND bcd.bcd_due_time <= now() + (? || ' hours')::interval
			   AND bcd.bcd_reminder_sent_for_due_time IS DISTINCT FROM bcd.bcd_due_time
			 ORDER BY bcd.bcd_due_time LIMIT " . (int)$batch);
		$q->execute(array($hours));

		$sent = 0;
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$card = new BoardCard((int)$row['bcd_card_id'], TRUE);
			$board = new Board((int)$card->get('bcd_bbd_board_id'), TRUE);

			$m = DbConnector::GetPreparedStatement('SELECT bcm_usr_user_id FROM bcm_card_members WHERE bcm_bcd_card_id = ?');
			$m->execute(array((int)$card->key));
			$recipients = $this->withAccess($board, array_map('intval', $m->fetchAll(PDO::FETCH_COLUMN)));

			if ($recipients) {
				BoardsNotifier::dueSoon($board, $card, $recipients, 'this card', $this->when($row['bcd_due_time']));
				$sent++;
			}
			// Recorded even when nobody was told: with no one to remind, asking
			// again every minute would only repeat the answer.
			$u = DbConnector::GetPreparedStatement('UPDATE bcd_cards SET bcd_reminder_sent_for_due_time = ? WHERE bcd_card_id = ?');
			$u->execute(array($row['bcd_due_time'], (int)$card->key));
		}
		return $sent;
	}

	/** Reminders for checklist items; returns how many were sent. */
	private function remindItems(int $hours, int $batch): int {
		$q = DbConnector::GetPreparedStatement(
			"SELECT bci.bci_checklist_item_id, bci.bci_text, bci.bci_due_time, bci.bci_usr_user_id, bcd.bcd_card_id
			 FROM bci_checklist_items bci
			 JOIN bck_checklists bck ON bck.bck_checklist_id = bci.bci_bck_checklist_id
			 JOIN bcd_cards bcd ON bcd.bcd_card_id = bck.bck_bcd_card_id
			 JOIN bbd_boards bbd ON bbd.bbd_board_id = bcd.bcd_bbd_board_id
			 JOIN bls_lists bls ON bls.bls_list_id = bcd.bcd_bls_list_id AND bls.bls_archived_time IS NULL
			 WHERE bci.bci_is_done = false AND bci.bci_usr_user_id IS NOT NULL AND bci.bci_due_time IS NOT NULL
			   AND bcd.bcd_is_template = false AND bcd.bcd_delete_time IS NULL AND bcd.bcd_archived_time IS NULL
			   AND bbd.bbd_delete_time IS NULL AND bbd.bbd_archived_time IS NULL AND bbd.bbd_is_template = false
			   AND bci.bci_due_time > now() AND bci.bci_due_time <= now() + (? || ' hours')::interval
			   AND bci.bci_reminder_sent_for_due_time IS DISTINCT FROM bci.bci_due_time
			 ORDER BY bci.bci_due_time LIMIT " . (int)$batch);
		$q->execute(array($hours));

		$sent = 0;
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$card = new BoardCard((int)$row['bcd_card_id'], TRUE);
			$board = new Board((int)$card->get('bcd_bbd_board_id'), TRUE);
			$recipients = $this->withAccess($board, array((int)$row['bci_usr_user_id']));
			if ($recipients) {
				BoardsNotifier::dueSoon($board, $card, $recipients, 'the checklist item "' . $row['bci_text'] . '"', $this->when($row['bci_due_time']));
				$sent++;
			}
			$u = DbConnector::GetPreparedStatement('UPDATE bci_checklist_items SET bci_reminder_sent_for_due_time = ? WHERE bci_checklist_item_id = ?');
			$u->execute(array($row['bci_due_time'], (int)$row['bci_checklist_item_id']));
		}
		return $sent;
	}

	/** Only people who can still open the board are reminded. */
	private function withAccess(Board $board, array $user_ids): array {
		$out = array();
		foreach (array_unique($user_ids) as $uid) {
			if ($uid > 0 && !User::is_placeholder($uid) && Boards::roleFor($board, $uid) !== null) {
				$out[] = $uid;
			}
		}
		return $out;
	}

	/** "in about 5 hours (Oct 12, 2026 17:00 UTC)" — in UTC because the message goes to many time zones. */
	private function when(string $due_utc): string {
		$ts = strtotime($due_utc . ' UTC');
		$minutes = max(1, (int)round(($ts - time()) / 60));
		$relative = $minutes < 90 ? 'in about ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's')
			: 'in about ' . (int)round($minutes / 60) . ' hours';
		return $relative . ' (' . gmdate('M j, Y H:i', $ts) . ' UTC)';
	}
}
