<?php
require_once(PathHelper::getIncludePath('includes/calendar/CalendarItemSource.php'));

/**
 * Puts a person's due cards, and the checklist items assigned to them, on their
 * calendar.
 *
 * A card shows when the subject is a member of it, it is not done, and it has a
 * due date. A checklist item shows when it is assigned to the subject, not done,
 * and has a due date. Cards in a template, in an archived or deleted state, or
 * on a board the person can no longer open are left out.
 *
 * A due time at local midnight is an all-day item; any other time is a timed one.
 * Neither occupies the person's time, so neither blocks availability. The
 * calendar's own summaries and the native apps pick these up without further
 * work: they read the same registry.
 */
class BoardsCalendarItemSource implements CalendarItemSource {

	public static function getKey(): string {
		return 'boards';
	}

	public function getItems(
		CalendarSubject $subject,
		string $start_utc,
		string $end_utc,
		string $visibility
	): array {
		$user_id = $subject->getUserId();
		if (!Globalvars::get_instance()->get_setting('boards_active', true, true)) {
			return [];
		}
		if (!$user_id || $visibility !== CalendarItem::VIS_DETAILS) {
			// Due dates are a person's own to-do list: the busy projection
			// (what a stranger sees of the calendar) gets nothing.
			return [];
		}

		$timezone = 'UTC';
		$owner = $subject->resolveOwner();
		if ($owner && $owner->get('usr_timezone')) {
			$timezone = $owner->get('usr_timezone');
		}

		$items = [];
		$boards = [];   // board id => may this person open it?

		// Cards the person is on.
		$q = DbConnector::GetPreparedStatement(
			'SELECT bcd.bcd_card_id, bcd.bcd_bbd_board_id, bcd.bcd_title, bcd.bcd_due_time
			 FROM bcd_cards bcd
			 JOIN bcm_card_members bcm ON bcm.bcm_bcd_card_id = bcd.bcd_card_id
			 JOIN bls_lists bls ON bls.bls_list_id = bcd.bcd_bls_list_id AND bls.bls_archived_time IS NULL
			 WHERE bcm.bcm_usr_user_id = ? AND bcd.bcd_is_done = false AND bcd.bcd_is_template = false
			   AND bcd.bcd_delete_time IS NULL AND bcd.bcd_archived_time IS NULL
			   AND bcd.bcd_due_time >= ? AND bcd.bcd_due_time < ?');
		// A card ending inside the window starts at most a day before it.
		$q->execute([$user_id, gmdate('Y-m-d H:i:s', strtotime($start_utc . ' UTC') - 86400), $end_utc]);
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			if (!$this->mayOpen($boards, (int)$row['bcd_bbd_board_id'], $user_id)) {
				continue;
			}
			$item = $this->item($row['bcd_due_time'], $timezone, $start_utc, $end_utc, $row['bcd_title'],
				'/profile/boards/board?id=' . (int)$row['bcd_bbd_board_id'] . '&card=' . (int)$row['bcd_card_id'],
				'boards:card-' . (int)$row['bcd_card_id']);
			if ($item) {
				$items[] = $item;
			}
		}

		// Checklist items assigned to the person.
		$q = DbConnector::GetPreparedStatement(
			'SELECT bci.bci_checklist_item_id, bci.bci_text, bci.bci_due_time, bcd.bcd_card_id, bcd.bcd_bbd_board_id, bcd.bcd_title
			 FROM bci_checklist_items bci
			 JOIN bck_checklists bck ON bck.bck_checklist_id = bci.bci_bck_checklist_id
			 JOIN bcd_cards bcd ON bcd.bcd_card_id = bck.bck_bcd_card_id
			 JOIN bls_lists bls ON bls.bls_list_id = bcd.bcd_bls_list_id AND bls.bls_archived_time IS NULL
			 WHERE bci.bci_usr_user_id = ? AND bci.bci_is_done = false AND bcd.bcd_is_template = false
			   AND bcd.bcd_delete_time IS NULL AND bcd.bcd_archived_time IS NULL
			   AND bci.bci_due_time >= ? AND bci.bci_due_time < ?');
		$q->execute([$user_id, gmdate('Y-m-d H:i:s', strtotime($start_utc . ' UTC') - 86400), $end_utc]);
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			if (!$this->mayOpen($boards, (int)$row['bcd_bbd_board_id'], $user_id)) {
				continue;
			}
			$item = $this->item($row['bci_due_time'], $timezone, $start_utc, $end_utc,
				$row['bci_text'] . ' (' . $row['bcd_title'] . ')',
				'/profile/boards/board?id=' . (int)$row['bcd_bbd_board_id'] . '&card=' . (int)$row['bcd_card_id'],
				'boards:item-' . (int)$row['bci_checklist_item_id']);
			if ($item) {
				$items[] = $item;
			}
		}

		return $items;
	}

	/** Can this person open the board — asked once per board per call, and a template board never counts. */
	private function mayOpen(array &$cache, int $board_id, int $user_id): bool {
		if (!isset($cache[$board_id])) {
			$cache[$board_id] = false;
			if (Board::check_if_exists($board_id)) {
				$board = new Board($board_id, TRUE);
				$cache[$board_id] = !$board->get('bbd_is_template') && !$board->get('bbd_archived_time')
					&& Boards::roleFor($board, $user_id) !== null;
			}
		}
		return $cache[$board_id];
	}

	private function item(string $due_utc, string $timezone, string $start_utc, string $end_utc, string $title, string $url, string $source_key): ?CalendarItem {
		$all_day = LibraryFunctions::convert_time($due_utc, 'UTC', $timezone, 'H:i') === '00:00';
		$start = $due_utc;
		// An all-day item spans its local day; a timed one is a short chip.
		$end = gmdate('Y-m-d H:i:s', strtotime($due_utc . ' UTC') + ($all_day ? 86400 : 900));
		if (!($start < $end_utc && $end > $start_utc)) {
			return null;
		}
		return new CalendarItem([
			'start_utc'           => $start,
			'end_utc'             => $end,
			'all_day'             => $all_day,
			'type'                => CalendarItem::TYPE_PERSONAL,
			'title'               => $title,
			'url'                 => $url,
			'blocks_availability' => false,
			'visibility'          => CalendarItem::VIS_DETAILS,
			'source'              => self::getKey(),
			'source_key'          => $source_key,
			'timezone'            => $timezone,
			'color'               => '#0d9488',
		]);
	}
}
