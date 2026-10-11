<?php
/**
 * BoardsNotifier — who hears about what, and the signals that tell them.
 *
 * Watching follows the model people already know, but quieter by default:
 * notifications go only to the people on a card. A card's watchers are its
 * members, anyone who commented on it and anyone who pressed Watch — minus
 * anyone who pressed Unwatch. A board's watchers are the members who chose "Tell
 * me about every new card"; the default is off.
 *
 * Nobody is told about their own action (Notify drops source_user_id), and when
 * one action would reach the same person twice — an @mention inside a comment on
 * a card they watch — only the mention is sent. This class works out both
 * recipient lists and removes the mentioned people from the activity list before
 * it dispatches; Notify itself is unchanged.
 *
 * Every signal is dispatched with `recipients` (targeted delivery), so each
 * person's own preferences apply: they can turn a signal's email off or mute it
 * on the notification preferences page. `default_email` is declared on each
 * signal, so a person with no saved preference still gets the email.
 *
 * @version 1.0.0
 */

class BoardsNotifier {

	/** The longest summary sent: a notification is a nudge, not the card. */
	const SUMMARY_LIMIT = 280;

	// ------------------------------------------------------------------
	// Who
	// ------------------------------------------------------------------

	/**
	 * The people who hear about activity on a card: members, commenters and those
	 * who pressed Watch, minus those who pressed Unwatch, and only people who can
	 * still open the board.
	 *
	 * @return int[]
	 */
	public static function cardWatcherIds(BoardCard $card): array {
		$card_id = (int)$card->key;
		$ids = array();

		$q = DbConnector::GetPreparedStatement('SELECT bcm_usr_user_id FROM bcm_card_members WHERE bcm_bcd_card_id = ?');
		$q->execute(array($card_id));
		foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $uid) {
			$ids[(int)$uid] = true;
		}

		$q = DbConnector::GetPreparedStatement(
			'SELECT DISTINCT bco_usr_user_id FROM bco_comments WHERE bco_bcd_card_id = ? AND bco_delete_time IS NULL AND bco_usr_user_id IS NOT NULL');
		$q->execute(array($card_id));
		foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $uid) {
			$ids[(int)$uid] = true;
		}

		$q = DbConnector::GetPreparedStatement('SELECT bcw_usr_user_id, bcw_muted FROM bcw_card_watchers WHERE bcw_bcd_card_id = ?');
		$q->execute(array($card_id));
		foreach ($q->fetchAll(PDO::FETCH_NUM) as $row) {
			if (self::truthy($row[1])) {
				unset($ids[(int)$row[0]]);
			} else {
				$ids[(int)$row[0]] = true;
			}
		}

		return self::withAccess($card->get('bcd_bbd_board_id'), array_keys($ids));
	}

	/**
	 * The people who hear about every new card on a board — only those who can
	 * still open it (a person who left a group keeps the row but stops being told).
	 *
	 * @return int[]
	 */
	public static function boardWatcherIds(Board $board): array {
		$q = DbConnector::GetPreparedStatement('SELECT bbw_usr_user_id FROM bbw_board_watchers WHERE bbw_bbd_board_id = ?');
		$q->execute(array((int)$board->key));
		return self::withAccess((int)$board->key, array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)), $board);
	}

	/** Keep the real people who can still open the board. */
	private static function withAccess($board_id, array $user_ids, ?Board $board = null): array {
		if (!$user_ids) {
			return array();
		}
		if ($board === null) {
			if (!Board::check_if_exists((int)$board_id)) {
				return array();
			}
			$board = new Board((int)$board_id, TRUE);
		}
		$out = array();
		foreach (array_unique($user_ids) as $uid) {
			$uid = (int)$uid;
			if ($uid > 0 && !User::is_placeholder($uid) && Boards::roleFor($board, $uid) !== null) {
				$out[] = $uid;
			}
		}
		return $out;
	}

	/** Mentioned user ids, narrowed to real people who can open the board (and not the author). */
	public static function mentionable(Board $board, array $user_ids, int $author_id): array {
		$out = array();
		foreach ($user_ids as $uid) {
			$uid = (int)$uid;
			if ($uid > 0 && $uid !== $author_id && !User::is_placeholder($uid) && Boards::roleFor($board, $uid) !== null) {
				$out[$uid] = $uid;
			}
		}
		return array_values($out);
	}

	// ------------------------------------------------------------------
	// Tell
	// ------------------------------------------------------------------

	/** The link a notification opens: the card, on its board. */
	public static function cardUrl(int $board_id, int $card_id): string {
		return '/profile/boards/board?id=' . $board_id . '&card=' . $card_id;
	}

	/**
	 * Dispatch one signal about a card to a set of people.
	 *
	 * @param int[] $recipients
	 */
	public static function send(string $signal, Board $board, BoardCard $card, string $summary, array $recipients, int $actor_id): void {
		$recipients = array_values(array_unique(array_map('intval', $recipients)));
		if (!$recipients) {
			return;
		}
		SignalBus::dispatch($signal, array(
			// The notification title is "[board] card" and a column of 255; cut the
			// parts so the whole always fits, and the subject line stays readable.
			'board_name'     => mb_substr(self::oneLine((string)$board->get('bbd_name')), 0, 80),
			'card_title'     => mb_substr(self::oneLine((string)$card->get('bcd_title')), 0, 120),
			'card_url'       => self::cardUrl((int)$board->key, (int)$card->key),
			'summary'        => mb_substr(self::oneLine($summary), 0, self::SUMMARY_LIMIT),
			'recipients'     => $recipients,
			'source_user_id' => $actor_id ?: null,
		));
	}

	/** A card or board name on one line: it becomes a subject line. */
	private static function oneLine(string $text): string {
		return trim(preg_replace('/\s+/', ' ', $text));
	}

	/** Someone was added to a card, or to one of its checklist items. */
	public static function assigned(Board $board, BoardCard $card, int $assignee_id, int $actor_id, string $what = 'this card'): void {
		if ($assignee_id === $actor_id || Boards::roleFor($board, $assignee_id) === null) {
			return;
		}
		self::send('boards.assigned', $board, $card,
			Boards::person($actor_id)['name'] . ' added you to ' . $what . '.', array($assignee_id), $actor_id);
	}

	/** A new card on the board: tell the board's watchers (but not people already told they were added to it). */
	public static function cardCreated(Board $board, BoardCard $card, int $actor_id, string $list_name, array $exclude = array()): void {
		self::send('boards.card_created', $board, $card,
			Boards::person($actor_id)['name'] . ' added this card to ' . $list_name . '.',
			array_diff(self::boardWatcherIds($board), array($actor_id), array_map('intval', $exclude)), $actor_id);
	}

	/**
	 * Something happened on a card its watchers care about: a comment, a move to
	 * another list, a new due date, done, an attachment.
	 *
	 * @param int[] $exclude people already told another way (a mention, an assignment)
	 */
	public static function activity(Board $board, BoardCard $card, int $actor_id, string $summary, array $exclude = array()): void {
		$recipients = array_diff(self::cardWatcherIds($card), array($actor_id), array_map('intval', $exclude));
		self::send('boards.card_activity', $board, $card, $summary, $recipients, $actor_id);
	}

	/**
	 * Mentions: tell each mentioned person, and report who was told so the caller
	 * can leave them out of the card-activity message for the same action.
	 *
	 * @param int[] $user_ids as the author named them (narrowed to people on the board)
	 * @param string $where "in a comment" / "in the description"
	 * @return int[] the people who were mentioned
	 */
	public static function mentioned(Board $board, BoardCard $card, int $actor_id, array $user_ids, string $where, string $excerpt = ''): array {
		$mentioned = self::mentionable($board, $user_ids, $actor_id);
		if (!$mentioned) {
			return array();
		}
		$summary = Boards::person($actor_id)['name'] . ' mentioned you ' . $where . '.';
		if (trim($excerpt) !== '') {
			$summary .= ' "' . mb_substr(self::oneLine($excerpt), 0, 160) . '"';
		}
		self::send('boards.mentioned', $board, $card, $summary, $mentioned, $actor_id);
		return $mentioned;
	}

	/** A card or checklist item is coming due (the due-reminder task's call). */
	public static function dueSoon(Board $board, BoardCard $card, array $recipients, string $what, string $due_text): void {
		self::send('boards.due_soon', $board, $card, ucfirst($what) . ' is due ' . $due_text . '.',
			$recipients, 0);
	}

	private static function truthy($value): bool {
		return $value === true || $value === 't' || $value === 'true' || $value === 1 || $value === '1';
	}
}
