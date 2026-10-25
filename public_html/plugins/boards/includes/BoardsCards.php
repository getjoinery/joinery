<?php
/**
 * BoardsCards — everything that happens to a card, a checklist or a comment.
 *
 * The API actions are thin: they find out who is asking, load the board and
 * check the role (Boards::boardFor / cardFor), and call one method here. Each
 * method does the change, writes the activity row the poll and the card history
 * read, and tells the right people — so an import, a template and the web page
 * all behave the same way, and a test can drive the whole thing without HTTP.
 *
 * Methods assume the caller has already checked the role; they check what only
 * they can know (the list belongs to the board, the person being added can open
 * it, the card ceiling).
 *
 * @version 1.0.1
 * @changelog 1.0.1 - deletes the caller's board role admitted run under rule_checked_write()
 */

class BoardsCards {

	// ------------------------------------------------------------------
	// Cards
	// ------------------------------------------------------------------

	/** Refuse when the board is at its card ceiling. */
	public static function checkCapacity(Board $board, int $adding = 1): void {
		$ceiling = Boards::settingInt('boards_max_cards_per_board', 5000);
		$q = DbConnector::GetPreparedStatement('SELECT COUNT(*) FROM bcd_cards WHERE bcd_bbd_board_id = ? AND bcd_delete_time IS NULL');
		$q->execute(array((int)$board->key));
		if ((int)$q->fetchColumn() + $adding > $ceiling) {
			throw new BoardsRefusal('This board is at its limit of ' . number_format($ceiling) . ' cards. Archive or delete some to add more.');
		}
	}

	/**
	 * A list that belongs to the board and is on it.
	 *
	 * @throws BoardsRefusal
	 */
	public static function listOn(Board $board, $list_id, bool $allow_archived = false): BoardList {
		$list_id = (int)$list_id;
		if ($list_id <= 0 || !BoardList::check_if_exists($list_id)) {
			throw new BoardsRefusal('List not found.');
		}
		$list = new BoardList($list_id, TRUE);
		if ((int)$list->get('bls_bbd_board_id') !== (int)$board->key
			|| (!$allow_archived && $list->get('bls_archived_time'))) {
			throw new BoardsRefusal('List not found.');
		}
		return $list;
	}

	/**
	 * Make a card.
	 *
	 * @param array $opts description, due (UTC string), prev_id / next_id (place
	 *   between two cards) or at ('top'|'bottom', default bottom), source,
	 *   member_ids, label_ids, is_template, message_id (the mailbox message it was made
	 *   from), notify (false for imports/templates),
	 *   log (false to skip the activity row: a card seeded from a template or an
	 *   import has no history worth keeping)
	 */
	public static function create(Board $board, BoardList $list, int $user_id, string $title, array $opts = array()): BoardCard {
		$title = Boards::cleanTitle($title, 255, 'A card title');
		self::checkCapacity($board);

		$notify = ($opts['notify'] ?? true) !== false;
		$list_id = (int)$list->key;

		if (isset($opts['prev_id']) || isset($opts['next_id'])) {
			$position = Boards::computePosition('card', $list_id, $opts['prev_id'] ?? null, $opts['next_id'] ?? null);
		} elseif (($opts['at'] ?? 'bottom') === 'top') {
			$first = DbConnector::GetPreparedStatement(
				'SELECT bcd_card_id FROM bcd_cards WHERE bcd_bls_list_id = ? AND bcd_delete_time IS NULL AND bcd_archived_time IS NULL ORDER BY bcd_position LIMIT 1');
			$first->execute(array($list_id));
			$first_id = $first->fetchColumn();
			$position = Boards::computePosition('card', $list_id, null, $first_id ? (int)$first_id : null);
		} else {
			$position = Boards::computePosition('card', $list_id);
		}

		$card = Boards::transaction(function () use ($board, $list, $user_id, $title, $opts, $position, $list_id) {
			$card = new BoardCard(NULL);
			$card->set('bcd_bls_list_id', $list_id);
			$card->set('bcd_bbd_board_id', (int)$board->key);
			$card->set('bcd_title', $title);
			$card->set('bcd_description', isset($opts['description']) && trim((string)$opts['description']) !== '' ? (string)$opts['description'] : null);
			$card->set('bcd_due_time', $opts['due'] ?? null);
			$card->set('bcd_position', $position);
			$card->set('bcd_is_template', !empty($opts['is_template']));
			$card->set('bcd_source', $opts['source'] ?? BoardCard::SOURCE_WEB);
			$card->set('bcd_usr_user_id_creator', $user_id ?: null);
			// The mailbox message a card was made from (email-to-board, Make a card).
			$card->set('bcd_iem_message_id', !empty($opts['message_id']) ? (int)$opts['message_id'] : null);
			$card->save();
			$card->load();

			if (($opts['log'] ?? true) !== false) {
				Boards::log((int)$board->key, (int)$card->key, $user_id ?: null, 'card.created', array(
					'list_id' => $list_id, 'list_name' => (string)$list->get('bls_name'), 'title' => $title,
				));
			}
			// Labels and people go in the same transaction: a bad id refuses the
			// whole request instead of leaving a half-made card to be retried
			// into a duplicate.
			foreach ((array)($opts['label_ids'] ?? array()) as $label_id) {
				self::addLabel($card, $board, $user_id, (int)$label_id, false);
			}
			foreach ((array)($opts['member_ids'] ?? array()) as $member_id) {
				self::addMember($card, $board, $user_id, (int)$member_id, false);
			}
			return $card;
		});

		// Told after the card exists. Someone put on the card is told they were
		// added, and is not also told about a new card on a board they watch.
		$added = array_map('intval', (array)($opts['member_ids'] ?? array()));
		if ($notify && $user_id) {
			foreach ($added as $member_id) {
				BoardsNotifier::assigned($board, $card, $member_id, $user_id);
			}
			BoardsNotifier::cardCreated($board, $card, $user_id, (string)$list->get('bls_name'), $added);
		}
		return $card;
	}

	/**
	 * Change a card's title, description, due date or done state.
	 *
	 * @param array $changes any of: title, description, due (UTC string or null),
	 *   is_done (bool)
	 * @param int[] $mentions people the author @mentioned in the description
	 */
	public static function update(BoardCard $card, Board $board, int $user_id, array $changes, array $mentions = array()): BoardCard {
		$card_id = (int)$card->key;
		$board_id = (int)$board->key;
		$told = array();       // people a more specific message already reached
		$activity = array();   // [summary] for the watchers, in order
		$mention_ids = array();

		Boards::transaction(function () use ($card, $board_id, $card_id, $user_id, $changes, $mentions, &$activity, &$mention_ids) {
			Boards::lockAndReload($card);
			$actor = Boards::person($user_id)['name'];

			if (array_key_exists('title', $changes)) {
				$title = Boards::cleanTitle($changes['title'], 255, 'A card title');
				if ($title !== (string)$card->get('bcd_title')) {
					Boards::log($board_id, $card_id, $user_id, 'card.renamed', array('from' => (string)$card->get('bcd_title'), 'to' => $title));
					$card->set('bcd_title', $title);
				}
			}

			if (array_key_exists('description', $changes)) {
				$old = (string)$card->get('bcd_description');
				$new = trim((string)$changes['description']);
				if ($new !== $old) {
					$card->set('bcd_description', $new === '' ? null : $new);
					Boards::log($board_id, $card_id, $user_id, 'card.described', array('quiet' => true));
					$mention_ids = self::newMentions($old, $new, $mentions);
				}
			}

			if (array_key_exists('due', $changes)) {
				$old = $card->get('bcd_due_time');
				$new = $changes['due'];
				if ($new !== $old) {
					$card->set('bcd_due_time', $new);
					Boards::log($board_id, $card_id, $user_id, 'card.due_set', array('from' => Boards::isoFromUtc($old), 'to' => Boards::isoFromUtc($new)));
					$activity[] = $new
						? $actor . ' set the due date to ' . gmdate('M j, Y H:i', strtotime($new . ' UTC')) . ' UTC.'
						: $actor . ' removed the due date.';
				}
			}

			if (array_key_exists('is_done', $changes)) {
				$old = Boards::flag($card->get('bcd_is_done'));
				$new = (bool)$changes['is_done'];
				if ($new !== $old) {
					$card->set('bcd_is_done', $new);
					Boards::log($board_id, $card_id, $user_id, 'card.done', array('is_done' => $new));
					$activity[] = $actor . ' marked this card ' . ($new ? 'done' : 'not done') . '.';
				}
			}

			$card->set('bcd_update_time', 'now()');
			$card->save();
			$card->load();
		});

		if ($mention_ids) {
			$told = BoardsNotifier::mentioned($board, $card, $user_id, $mention_ids, 'in the description');
		}
		foreach ($activity as $summary) {
			BoardsNotifier::activity($board, $card, $user_id, $summary, $told);
		}
		return $card;
	}

	/**
	 * The people named in $new but not in $old: someone is "newly mentioned" when
	 * their @name is in the text now and was not before. The author's list of ids
	 * only narrows who to look for — the text has to carry the name — so a
	 * mention cannot be conjured with no @name in it, and saving a description
	 * again never tells the same person twice.
	 *
	 * @param int[] $candidate_ids
	 * @return int[]
	 */
	public static function newMentions(?string $old, string $new, array $candidate_ids): array {
		$out = array();
		foreach ($candidate_ids as $uid) {
			$uid = (int)$uid;
			if ($uid <= 0) {
				continue;
			}
			$tag = '@' . Boards::person($uid)['name'];
			if (mb_stripos($new, $tag) !== false && ($old === null || mb_stripos($old, $tag) === false)) {
				$out[$uid] = $uid;
			}
		}
		return array_values($out);
	}

	/**
	 * Move a card within its board: to a list, between two neighbours.
	 * A move to another list is recorded and told; a reorder inside the same list
	 * is recorded quietly (the other browsers need to see it, nobody needs a message).
	 */
	public static function move(BoardCard $card, Board $board, BoardList $target, int $user_id, ?int $prev_id, ?int $next_id): BoardCard {
		if ((int)$target->get('bls_bbd_board_id') !== (int)$board->key || (int)$card->get('bcd_bbd_board_id') !== (int)$board->key) {
			throw new BoardsRefusal('List not found.');
		}
		$to_list_id = (int)$target->key;
		$changed_list = false;
		$from_name = '';

		Boards::transaction(function () use ($card, $board, $target, $user_id, $prev_id, $next_id, $to_list_id, &$changed_list, &$from_name) {
			Boards::lockAndReload($card);
			$from_list_id = (int)$card->get('bcd_bls_list_id');
			$changed_list = $from_list_id !== $to_list_id;
			if ($changed_list && BoardList::check_if_exists($from_list_id)) {
				$from_name = (string)(new BoardList($from_list_id, TRUE))->get('bls_name');
			}
			$position = Boards::computePosition('card', $to_list_id, $prev_id, $next_id, (int)$card->key);
			$card->set('bcd_bls_list_id', $to_list_id);
			$card->set('bcd_position', $position);
			$card->set('bcd_update_time', 'now()');
			$card->save();
			$card->load();
			Boards::log((int)$board->key, (int)$card->key, $user_id, 'card.moved', $changed_list
				? array('from_list_id' => $from_list_id, 'from' => $from_name, 'to_list_id' => $to_list_id, 'to' => (string)$target->get('bls_name'))
				: array('quiet' => true));
		});

		if ($changed_list) {
			BoardsNotifier::activity($board, $card, $user_id,
				Boards::person($user_id)['name'] . ' moved this card from ' . $from_name . ' to ' . $target->get('bls_name') . '.');
		}
		return $card;
	}

	/**
	 * Move a card to a list on another board.
	 *
	 * The card keeps its checklists, comments and attachments. Its labels follow by
	 * name and colour (a label the other board lacks is created there), its members
	 * stay only if they can open the other board, and its attachments are re-gated
	 * on the new board so the people there — and no longer the people here — can
	 * open them.
	 */
	public static function moveToBoard(BoardCard $card, Board $from, Board $to, BoardList $target, int $user_id, ?int $prev_id = null, ?int $next_id = null): BoardCard {
		if ((int)$target->get('bls_bbd_board_id') !== (int)$to->key) {
			throw new BoardsRefusal('List not found.');
		}
		if ((int)$from->key === (int)$to->key) {
			return self::move($card, $from, $target, $user_id, $prev_id, $next_id);
		}
		self::checkCapacity($to);

		$card_id = (int)$card->key;

		Boards::transaction(function () use ($card, $card_id, $from, $to, $target, $user_id, $prev_id, $next_id) {
			$db = DbConnector::get_instance()->get_db_link();
			// Row first, then the boards' activity locks: the same order every other
			// card change takes them in.
			Boards::lockAndReload($card);
			Boards::lockBoards(array((int)$from->key, (int)$to->key));

			// Checklist items assigned to someone who cannot open the other board.
			$q = $db->prepare('SELECT DISTINCT bci.bci_usr_user_id FROM bci_checklist_items bci
				JOIN bck_checklists bck ON bck.bck_checklist_id = bci.bci_bck_checklist_id
				WHERE bck.bck_bcd_card_id = ? AND bci.bci_usr_user_id IS NOT NULL');
			$q->execute(array($card_id));
			foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $uid) {
				if (Boards::roleFor($to, (int)$uid) === null) {
					$db->prepare('UPDATE bci_checklist_items SET bci_usr_user_id = NULL WHERE bci_usr_user_id = ? AND bci_bck_checklist_id IN
						(SELECT bck_checklist_id FROM bck_checklists WHERE bck_bcd_card_id = ?)')->execute(array((int)$uid, $card_id));
				}
			}

			// Labels, by name and colour.
			$q = $db->prepare('SELECT blb.blb_label_id, blb.blb_name, blb.blb_color FROM bcl_card_labels bcl
				JOIN blb_labels blb ON blb.blb_label_id = bcl.bcl_blb_label_id WHERE bcl.bcl_bcd_card_id = ?');
			$q->execute(array($card_id));
			$labels = $q->fetchAll(PDO::FETCH_ASSOC);
			$db->prepare('DELETE FROM bcl_card_labels WHERE bcl_bcd_card_id = ?')->execute(array($card_id));
			foreach ($labels as $label) {
				$target_label = self::labelByNameAndColor((int)$to->key, $label['blb_name'], $label['blb_color']);
				$db->prepare('INSERT INTO bcl_card_labels (bcl_bcd_card_id, bcl_blb_label_id) VALUES (?, ?)')
					->execute(array($card_id, $target_label));
			}

			// Members and watchers who cannot open the other board.
			foreach (array(array('bcm_card_members', 'bcm_bcd_card_id', 'bcm_usr_user_id'), array('bcw_card_watchers', 'bcw_bcd_card_id', 'bcw_usr_user_id')) as $t) {
				$q = $db->prepare("SELECT {$t[2]} FROM {$t[0]} WHERE {$t[1]} = ?");
				$q->execute(array($card_id));
				foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $uid) {
					if (Boards::roleFor($to, (int)$uid) === null) {
						$db->prepare("DELETE FROM {$t[0]} WHERE {$t[1]} = ? AND {$t[2]} = ?")->execute(array($card_id, (int)$uid));
					}
				}
			}

			// Attachments: gate the Files on the new board.
			$db->prepare("UPDATE fil_files SET fil_access_ref = ? WHERE fil_access_provider = ? AND fil_file_id IN
				(SELECT bat_fil_file_id FROM bat_attachments WHERE bat_bcd_card_id = ? AND bat_fil_file_id IS NOT NULL)")
				->execute(array((int)$to->key, BoardsAttachmentGate::KEY, $card_id));

			$position = Boards::computePosition('card', (int)$target->key, $prev_id, $next_id, $card_id);
			$card->set('bcd_bbd_board_id', (int)$to->key);
			$card->set('bcd_bls_list_id', (int)$target->key);
			$card->set('bcd_position', $position);
			$card->set('bcd_update_time', 'now()');
			$card->save();
			$card->load();

			// Each board's history says only that the card left or arrived. The other
			// board's name is not written into it: the people here may not be able
			// to open the board the card came from, or went to.
			Boards::log((int)$from->key, $card_id, $user_id, 'card.moved', array('left_board' => true));
			Boards::log((int)$to->key, $card_id, $user_id, 'card.moved', array(
				'arrived_from_another_board' => true, 'to_list_id' => (int)$target->key, 'to' => (string)$target->get('bls_name')));
		});
		return $card;
	}

	/** The label on a board with this name and colour, made if the board has none. */
	private static function labelByNameAndColor(int $board_id, $name, $color): int {
		$q = DbConnector::GetPreparedStatement(
			'SELECT blb_label_id FROM blb_labels WHERE blb_bbd_board_id = ? AND blb_color = ? AND COALESCE(blb_name, \'\') = ?');
		$q->execute(array($board_id, $color, (string)$name));
		$id = $q->fetchColumn();
		if ($id) {
			return (int)$id;
		}
		$label = new BoardLabel(NULL);
		$label->set('blb_bbd_board_id', $board_id);
		$label->set('blb_name', $name === null || $name === '' ? null : $name);
		$label->set('blb_color', $color);
		$label->set('blb_position', Boards::computePosition('label', $board_id));
		$label->save();
		return (int)$label->key;
	}

	/**
	 * Copy a card (onto the same board or another) and return the copy.
	 *
	 * The copy carries the title, description, due date, labels and checklists
	 * (with every item not done). Members, comments, attachments and history are
	 * not copied — a copy is a fresh start from the same shape of work.
	 *
	 * @param array $opts title, keep_members (bool), prev_id/next_id
	 */
	public static function copy(BoardCard $card, Board $from, Board $to, BoardList $target, int $user_id, array $opts = array()): BoardCard {
		return Boards::transaction(function () use ($card, $from, $to, $target, $user_id, $opts) {
		$title = isset($opts['title']) ? $opts['title'] : (string)$card->get('bcd_title');
		$copy = self::create($to, $target, $user_id, $title, array(
			'description' => (string)$card->get('bcd_description'),
			'due'         => $card->get('bcd_due_time'),
			'prev_id'     => $opts['prev_id'] ?? null,
			'next_id'     => $opts['next_id'] ?? null,
			'notify'      => false,
		));

		$q = DbConnector::GetPreparedStatement(
			'SELECT blb.blb_name, blb.blb_color FROM bcl_card_labels bcl
			 JOIN blb_labels blb ON blb.blb_label_id = bcl.bcl_blb_label_id WHERE bcl.bcl_bcd_card_id = ?');
		$q->execute(array((int)$card->key));
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $label) {
			// On the same board this finds the very label; on another it finds or makes its match.
			self::addLabel($copy, $to, $user_id, self::labelByNameAndColor((int)$to->key, $label['blb_name'], $label['blb_color']), false);
		}

		foreach (BoardsPayload::checklists((int)$card->key) as $checklist) {
			$new = self::addChecklist($copy, $to, $user_id, $checklist['title']);
			foreach ($checklist['items'] as $item) {
				self::addItem($new, $copy, $to, $user_id, $item['text'], array(
					'due' => $item['due'] ? Boards::utcFromInput($item['due']) : null,
					'user_id' => !empty($opts['keep_members']) && $item['user'] ? (int)$item['user']['id'] : null,
					'notify' => false,
				));
			}
		}

		if (!empty($opts['keep_members'])) {
			$q = DbConnector::GetPreparedStatement('SELECT bcm_usr_user_id FROM bcm_card_members WHERE bcm_bcd_card_id = ?');
			$q->execute(array((int)$card->key));
			foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $uid) {
				if (Boards::roleFor($to, (int)$uid) !== null) {
					self::addMember($copy, $to, $user_id, (int)$uid, false);
				}
			}
		}
		return $copy;
		});
	}

	/** Archive a card: hidden from the board, kept for a restore. */
	public static function archive(BoardCard $card, Board $board, int $user_id): void {
		if ($card->get('bcd_archived_time')) {
			return;
		}
		Boards::transaction(function () use ($card, $board, $user_id) {
			Boards::lockAndReload($card);
			$card->set('bcd_archived_time', 'now()');
			$card->set('bcd_update_time', 'now()');
			$card->save();
			$card->load();
			Boards::log((int)$board->key, (int)$card->key, $user_id, 'card.archived', array());
		});
	}

	/** Bring an archived card back to the bottom of its list (or the first live list if its own is archived). */
	public static function restore(BoardCard $card, Board $board, int $user_id): void {
		if (!$card->get('bcd_archived_time')) {
			return;
		}
		Boards::transaction(function () use ($card, $board, $user_id) {
			Boards::lockAndReload($card);
			$list_id = (int)$card->get('bcd_bls_list_id');
			if (!BoardList::check_if_exists($list_id) || (new BoardList($list_id, TRUE))->get('bls_archived_time')) {
				$q = DbConnector::GetPreparedStatement(
					'SELECT bls_list_id FROM bls_lists WHERE bls_bbd_board_id = ? AND bls_archived_time IS NULL ORDER BY bls_position LIMIT 1');
				$q->execute(array((int)$board->key));
				$list_id = (int)$q->fetchColumn();
				if (!$list_id) {
					throw new BoardsRefusal('Add a list to the board first, then restore the card.');
				}
				$card->set('bcd_bls_list_id', $list_id);
			}
			$card->set('bcd_position', Boards::computePosition('card', $list_id, null, null, (int)$card->key));
			$card->set('bcd_archived_time', null);
			$card->set('bcd_update_time', 'now()');
			$card->save();
			$card->load();
			Boards::log((int)$board->key, (int)$card->key, $user_id, 'card.restored', array());
		});
	}

	/** Delete a card (admins). It leaves the board; the row is kept until an admin purges it. */
	public static function delete(BoardCard $card, Board $board, int $user_id): void {
		Boards::transaction(function () use ($card, $board, $user_id) {
			// A board admin (the caller's cardFor(..., 'admin')).
			SystemBase::rule_checked_write(function () use ($card) { $card->soft_delete(); });
			// The poll learns a card is gone from the activity on it.
			Boards::log((int)$board->key, (int)$card->key, $user_id, 'card.archived', array('deleted' => true, 'quiet' => true));
		});
	}

	// ------------------------------------------------------------------
	// Members, labels, watching
	// ------------------------------------------------------------------

	public static function addMember(BoardCard $card, Board $board, int $actor_id, int $user_id, bool $notify = true): void {
		if ($user_id <= 0 || User::is_placeholder($user_id) || Boards::roleFor($board, $user_id) === null) {
			throw new BoardsRefusal('That person cannot open this board, so they cannot be added to a card on it.');
		}
		$q = DbConnector::GetPreparedStatement('SELECT 1 FROM bcm_card_members WHERE bcm_bcd_card_id = ? AND bcm_usr_user_id = ?');
		$q->execute(array((int)$card->key, $user_id));
		if ($q->fetchColumn()) {
			return;
		}
		Boards::transaction(function () use ($card, $board, $actor_id, $user_id) {
			$member = new BoardCardMember(NULL);
			$member->set('bcm_bcd_card_id', (int)$card->key);
			$member->set('bcm_usr_user_id', $user_id);
			$member->save();
			Boards::log((int)$board->key, (int)$card->key, $actor_id ?: null, 'member.added', array('user_id' => $user_id, 'name' => Boards::person($user_id)['name']));
		});
		if ($notify && $actor_id) {
			BoardsNotifier::assigned($board, $card, $user_id, $actor_id);
		}
	}

	public static function removeMember(BoardCard $card, Board $board, int $actor_id, int $user_id): void {
		$q = DbConnector::GetPreparedStatement('SELECT bcm_card_member_id FROM bcm_card_members WHERE bcm_bcd_card_id = ? AND bcm_usr_user_id = ?');
		$q->execute(array((int)$card->key, $user_id));
		$id = $q->fetchColumn();
		if (!$id) {
			return;
		}
		Boards::transaction(function () use ($card, $board, $actor_id, $user_id, $id) {
			// An editor on the board (the caller's cardFor()).
			SystemBase::rule_checked_write(function () use ($id) {
				(new BoardCardMember((int)$id, TRUE))->permanent_delete();
			});
			Boards::log((int)$board->key, (int)$card->key, $actor_id, 'member.removed', array('user_id' => $user_id, 'name' => Boards::person($user_id)['name']));
		});
	}

	public static function addLabel(BoardCard $card, Board $board, int $actor_id, int $label_id, bool $log = true): void {
		$label = self::labelOn($board, $label_id);
		$q = DbConnector::GetPreparedStatement('SELECT 1 FROM bcl_card_labels WHERE bcl_bcd_card_id = ? AND bcl_blb_label_id = ?');
		$q->execute(array((int)$card->key, $label_id));
		if ($q->fetchColumn()) {
			return;
		}
		Boards::transaction(function () use ($card, $board, $actor_id, $label, $label_id, $log) {
			$row = new BoardCardLabel(NULL);
			$row->set('bcl_bcd_card_id', (int)$card->key);
			$row->set('bcl_blb_label_id', $label_id);
			$row->save();
			if ($log) {
				Boards::log((int)$board->key, (int)$card->key, $actor_id ?: null, 'label.added',
					array('label_id' => $label_id, 'name' => (string)$label->get('blb_name'), 'color' => (string)$label->get('blb_color')));
			}
		});
	}

	public static function removeLabel(BoardCard $card, Board $board, int $actor_id, int $label_id): void {
		$label = self::labelOn($board, $label_id);
		$q = DbConnector::GetPreparedStatement('SELECT bcl_card_label_id FROM bcl_card_labels WHERE bcl_bcd_card_id = ? AND bcl_blb_label_id = ?');
		$q->execute(array((int)$card->key, $label_id));
		$id = $q->fetchColumn();
		if (!$id) {
			return;
		}
		Boards::transaction(function () use ($card, $board, $actor_id, $label, $label_id, $id) {
			SystemBase::rule_checked_write(function () use ($id) {
				(new BoardCardLabel((int)$id, TRUE))->permanent_delete();
			});
			Boards::log((int)$board->key, (int)$card->key, $actor_id, 'label.removed',
				array('label_id' => $label_id, 'name' => (string)$label->get('blb_name'), 'color' => (string)$label->get('blb_color')));
		});
	}

	/** @throws BoardsRefusal when the label is not on this board */
	public static function labelOn(Board $board, int $label_id): BoardLabel {
		if ($label_id <= 0 || !BoardLabel::check_if_exists($label_id)) {
			throw new BoardsRefusal('Label not found.');
		}
		$label = new BoardLabel($label_id, TRUE);
		if ((int)$label->get('blb_bbd_board_id') !== (int)$board->key) {
			throw new BoardsRefusal('Label not found.');
		}
		return $label;
	}

	/** Press Watch or Unwatch. */
	public static function setWatching(BoardCard $card, int $user_id, bool $watch): void {
		$q = DbConnector::GetPreparedStatement('SELECT bcw_card_watcher_id FROM bcw_card_watchers WHERE bcw_bcd_card_id = ? AND bcw_usr_user_id = ?');
		$q->execute(array((int)$card->key, $user_id));
		$id = $q->fetchColumn();
		$row = $id ? new BoardCardWatcher((int)$id, TRUE) : new BoardCardWatcher(NULL);
		if (!$id) {
			$row->set('bcw_bcd_card_id', (int)$card->key);
			$row->set('bcw_usr_user_id', $user_id);
		}
		$row->set('bcw_muted', !$watch);
		$row->save();
	}

	// ------------------------------------------------------------------
	// Checklists
	// ------------------------------------------------------------------

	public static function addChecklist(BoardCard $card, Board $board, int $actor_id, string $title = 'Checklist'): BoardChecklist {
		$title = Boards::cleanTitle($title, 255, 'A checklist title');
		return Boards::transaction(function () use ($card, $board, $actor_id, $title) {
			$checklist = new BoardChecklist(NULL);
			$checklist->set('bck_bcd_card_id', (int)$card->key);
			$checklist->set('bck_title', $title);
			$checklist->set('bck_position', Boards::computePosition('checklist', (int)$card->key));
			$checklist->save();
			Boards::log((int)$board->key, (int)$card->key, $actor_id ?: null, 'card.updated', array('quiet' => true, 'what' => 'checklist'));
			return $checklist;
		});
	}

	/** A checklist that belongs to the card. @throws BoardsRefusal */
	public static function checklistOn(BoardCard $card, $checklist_id): BoardChecklist {
		$checklist_id = (int)$checklist_id;
		if ($checklist_id <= 0 || !BoardChecklist::check_if_exists($checklist_id)) {
			throw new BoardsRefusal('Checklist not found.');
		}
		$checklist = new BoardChecklist($checklist_id, TRUE);
		if ((int)$checklist->get('bck_bcd_card_id') !== (int)$card->key) {
			throw new BoardsRefusal('Checklist not found.');
		}
		return $checklist;
	}

	/** A checklist item that belongs to the card. @return array{0:BoardChecklistItem,1:BoardChecklist} */
	public static function itemOn(BoardCard $card, $item_id): array {
		$item_id = (int)$item_id;
		if ($item_id <= 0 || !BoardChecklistItem::check_if_exists($item_id)) {
			throw new BoardsRefusal('Checklist item not found.');
		}
		$item = new BoardChecklistItem($item_id, TRUE);
		$checklist = self::checklistOn($card, $item->get('bci_bck_checklist_id'));
		return array($item, $checklist);
	}

	/**
	 * Add a line to a checklist.
	 *
	 * @param array $opts user_id (assignee), due (UTC string), prev_id/next_id, notify
	 */
	public static function addItem(BoardChecklist $checklist, BoardCard $card, Board $board, int $actor_id, string $text, array $opts = array()): BoardChecklistItem {
		$text = Boards::cleanTitle($text, 500, 'A checklist item');
		$assignee = !empty($opts['user_id']) ? (int)$opts['user_id'] : null;
		if ($assignee !== null && ($assignee <= 0 || Boards::roleFor($board, $assignee) === null)) {
			throw new BoardsRefusal('That person cannot open this board, so they cannot be given an item on it.');
		}

		$item = Boards::transaction(function () use ($checklist, $card, $board, $actor_id, $text, $opts, $assignee) {
			$item = new BoardChecklistItem(NULL);
			$item->set('bci_bck_checklist_id', (int)$checklist->key);
			$item->set('bci_text', $text);
			$item->set('bci_usr_user_id', $assignee);
			$item->set('bci_due_time', $opts['due'] ?? null);
			$item->set('bci_position', Boards::computePosition('item', (int)$checklist->key, $opts['prev_id'] ?? null, $opts['next_id'] ?? null));
			$item->save();
			Boards::log((int)$board->key, (int)$card->key, $actor_id ?: null, 'card.updated', array('quiet' => true, 'what' => 'checklist item'));
			return $item;
		});

		if ($assignee && $actor_id && ($opts['notify'] ?? true) !== false) {
			BoardsNotifier::assigned($board, $card, $assignee, $actor_id, 'the checklist item "' . $text . '"');
		}
		return $item;
	}

	/**
	 * Edit a checklist item.
	 *
	 * @param array $changes any of: text, is_done, user_id (assignee, 0 to clear),
	 *   due (UTC string or null)
	 */
	public static function updateItem(BoardChecklistItem $item, BoardChecklist $checklist, BoardCard $card, Board $board, int $actor_id, array $changes): BoardChecklistItem {
		$new_assignee = null;
		Boards::transaction(function () use ($item, $checklist, $card, $board, $actor_id, $changes, &$new_assignee) {
			if (array_key_exists('text', $changes)) {
				$item->set('bci_text', Boards::cleanTitle($changes['text'], 500, 'A checklist item'));
			}
			if (array_key_exists('user_id', $changes)) {
				$assignee = (int)$changes['user_id'];
				if ($assignee < 0 || ($assignee > 0 && Boards::roleFor($board, $assignee) === null)) {
					throw new BoardsRefusal('That person cannot open this board, so they cannot be given an item on it.');
				}
				if (($assignee ?: null) !== ($item->get('bci_usr_user_id') ? (int)$item->get('bci_usr_user_id') : null)) {
					$item->set('bci_usr_user_id', $assignee ?: null);
					$new_assignee = $assignee ?: null;
				}
			}
			if (array_key_exists('due', $changes) && $changes['due'] !== $item->get('bci_due_time')) {
				$item->set('bci_due_time', $changes['due']);
			}
			$done_changed = false;
			if (array_key_exists('is_done', $changes)) {
				$done = (bool)$changes['is_done'];
				if ($done !== Boards::flag($item->get('bci_is_done'))) {
					$item->set('bci_is_done', $done);
					$done_changed = true;
				}
			}
			$item->save();
			$item->load();
			Boards::log((int)$board->key, (int)$card->key, $actor_id,
				$done_changed ? 'checklist.item_done' : 'card.updated',
				$done_changed
					? array('text' => (string)$item->get('bci_text'), 'checklist' => (string)$checklist->get('bck_title'), 'is_done' => Boards::flag($item->get('bci_is_done')))
					: array('quiet' => true, 'what' => 'checklist item'));
		});
		if ($new_assignee && $actor_id) {
			BoardsNotifier::assigned($board, $card, $new_assignee, $actor_id, 'the checklist item "' . $item->get('bci_text') . '"');
		}
		return $item;
	}

	public static function deleteItem(BoardChecklistItem $item, BoardCard $card, Board $board, int $actor_id): void {
		Boards::transaction(function () use ($item, $card, $board, $actor_id) {
			// An editor on the board (the caller's cardFor()).
			SystemBase::rule_checked_write(function () use ($item) { $item->permanent_delete(); });
			Boards::log((int)$board->key, (int)$card->key, $actor_id, 'card.updated', array('quiet' => true, 'what' => 'checklist item'));
		});
	}

	public static function deleteChecklist(BoardChecklist $checklist, BoardCard $card, Board $board, int $actor_id): void {
		Boards::transaction(function () use ($checklist, $card, $board, $actor_id) {
			SystemBase::rule_checked_write(function () use ($checklist) { $checklist->permanent_delete(); });
			Boards::log((int)$board->key, (int)$card->key, $actor_id, 'card.updated', array('quiet' => true, 'what' => 'checklist'));
		});
	}

	/**
	 * Turn a checklist item into a card of its own, in the same list as the card
	 * it came from, and take it off the checklist. The assignee and due date come
	 * with it: the item's person becomes the new card's member.
	 */
	public static function itemToCard(BoardChecklistItem $item, BoardCard $card, Board $board, int $actor_id): BoardCard {
		$list = new BoardList((int)$card->get('bcd_bls_list_id'), TRUE);
		$assignee = $item->get('bci_usr_user_id') ? (int)$item->get('bci_usr_user_id') : null;
		$new = self::create($board, $list, $actor_id, (string)$item->get('bci_text'), array(
			'due'        => $item->get('bci_due_time'),
			'prev_id'    => (int)$card->key,
			'member_ids' => $assignee ? array($assignee) : array(),
		));
		self::deleteItem($item, $card, $board, $actor_id);
		return $new;
	}

	// ------------------------------------------------------------------
	// Comments
	// ------------------------------------------------------------------

	/**
	 * Add a comment, and tell the right people: the @mentioned, then the card's
	 * watchers who were not already told.
	 *
	 * @param int[] $mentions people the author @mentioned
	 */
	public static function addComment(BoardCard $card, Board $board, int $user_id, string $body, array $mentions = array(), string $source = 'web'): BoardComment {
		$body = trim($body);
		if ($body === '') {
			throw new BoardsRefusal('A comment cannot be empty.');
		}
		if (mb_strlen($body) > 20000) {
			throw new BoardsRefusal('That comment is too long.');
		}
		$comment = Boards::transaction(function () use ($card, $board, $user_id, $body, $source) {
			$comment = new BoardComment(NULL);
			$comment->set('bco_bcd_card_id', (int)$card->key);
			$comment->set('bco_usr_user_id', $user_id ?: null);
			$comment->set('bco_body', $body);
			$comment->set('bco_source', $source);
			$comment->save();
			Boards::log((int)$board->key, (int)$card->key, $user_id ?: null, 'comment.added',
				array('comment_id' => (int)$comment->key, 'excerpt' => mb_substr(preg_replace('/\s+/', ' ', $body), 0, 160)));
			return $comment;
		});

		$told = BoardsNotifier::mentioned($board, $card, $user_id, self::newMentions(null, $body, $mentions), 'in a comment', $body);
		// The commenter now watches the card, and the mention was their message.
		BoardsNotifier::activity($board, $card, $user_id,
			Boards::person($user_id)['name'] . ' commented: ' . mb_substr(preg_replace('/\s+/', ' ', $body), 0, 160), $told);
		return $comment;
	}

	/**
	 * Take a comment's text out of the activity row that quoted it. The history
	 * keeps that a comment was made; it must not keep the words of one that was
	 * edited or deleted.
	 */
	private static function scrubExcerpt(int $comment_id): void {
		$q = DbConnector::GetPreparedStatement(
			"UPDATE bac_activity SET bac_data = bac_data - 'excerpt'
			 WHERE bac_verb = 'comment.added' AND (bac_data->>'comment_id')::bigint = ?");
		$q->execute(array($comment_id));
	}

	/** A comment on this card. @throws BoardsRefusal */
	public static function commentOn(BoardCard $card, $comment_id): BoardComment {
		$comment_id = (int)$comment_id;
		if ($comment_id <= 0 || !BoardComment::check_if_exists($comment_id)) {
			throw new BoardsRefusal('Comment not found.');
		}
		$comment = new BoardComment($comment_id, TRUE);
		if ((int)$comment->get('bco_bcd_card_id') !== (int)$card->key || $comment->get('bco_delete_time')) {
			throw new BoardsRefusal('Comment not found.');
		}
		return $comment;
	}

	public static function editComment(BoardComment $comment, BoardCard $card, Board $board, int $user_id, string $body): BoardComment {
		if ((int)$comment->get('bco_usr_user_id') !== $user_id) {
			throw new BoardsRefusal('You can only edit your own comments.');
		}
		$body = trim($body);
		if ($body === '') {
			throw new BoardsRefusal('A comment cannot be empty.');
		}
		Boards::transaction(function () use ($comment, $card, $board, $user_id, $body) {
			self::scrubExcerpt((int)$comment->key);
			$comment->set('bco_body', $body);
			$comment->set('bco_edited_time', 'now()');
			$comment->save();
			$comment->load();
			Boards::log((int)$board->key, (int)$card->key, $user_id, 'card.updated', array('quiet' => true, 'what' => 'comment'));
		});
		return $comment;
	}

	public static function deleteComment(BoardComment $comment, BoardCard $card, Board $board, int $user_id, string $role): void {
		$own = (int)$comment->get('bco_usr_user_id') === $user_id;
		if (!$own && $role !== Boards::ROLE_ADMIN) {
			throw new BoardsRefusal('You can only remove your own comments.');
		}
		Boards::transaction(function () use ($comment, $card, $board, $user_id) {
			self::scrubExcerpt((int)$comment->key);
			// The author, or a board admin (checked above).
			SystemBase::rule_checked_write(function () use ($comment) { $comment->soft_delete(); });
			Boards::log((int)$board->key, (int)$card->key, $user_id, 'card.updated', array('quiet' => true, 'what' => 'comment'));
		});
	}

	// ------------------------------------------------------------------
	// Attachments
	// ------------------------------------------------------------------

	/** Record a stored File on the card, and tell the watchers. */
	public static function attachFile(BoardCard $card, Board $board, int $user_id, File $file): BoardAttachment {
		$attachment = Boards::transaction(function () use ($card, $board, $user_id, $file) {
			$attachment = BoardsUploads::attach($card, $file, $user_id);
			Boards::log((int)$board->key, (int)$card->key, $user_id, 'attachment.added', array('name' => (string)$file->get('fil_title')));
			return $attachment;
		});
		BoardsNotifier::activity($board, $card, $user_id,
			Boards::person($user_id)['name'] . ' attached ' . $file->get('fil_title') . '.');
		return $attachment;
	}

	/** Put a link on the card. */
	public static function attachLink(BoardCard $card, Board $board, int $user_id, string $url, ?string $name = null): BoardAttachment {
		$url = trim($url);
		if (!preg_match('#^https?://#i', $url)) {
			throw new BoardsRefusal('A link must start with http:// or https://');
		}
		return Boards::transaction(function () use ($card, $board, $user_id, $url, $name) {
			$attachment = new BoardAttachment(NULL);
			$attachment->set('bat_bcd_card_id', (int)$card->key);
			$attachment->set('bat_url', mb_substr($url, 0, 2000));
			$attachment->set('bat_name', $name !== null && trim($name) !== '' ? mb_substr(trim($name), 0, 255) : null);
			$attachment->set('bat_usr_user_id', $user_id ?: null);
			$attachment->save();
			Boards::log((int)$board->key, (int)$card->key, $user_id ?: null, 'attachment.added', array('name' => $name ?: $url));
			return $attachment;
		});
	}

	/** An attachment on this card. @throws BoardsRefusal */
	public static function attachmentOn(BoardCard $card, $attachment_id): BoardAttachment {
		$attachment_id = (int)$attachment_id;
		if ($attachment_id <= 0 || !BoardAttachment::check_if_exists($attachment_id)) {
			throw new BoardsRefusal('Attachment not found.');
		}
		$attachment = new BoardAttachment($attachment_id, TRUE);
		if ((int)$attachment->get('bat_bcd_card_id') !== (int)$card->key) {
			throw new BoardsRefusal('Attachment not found.');
		}
		return $attachment;
	}

	/** Take an attachment (and its File) off the card; clears the cover if it was the cover. */
	public static function removeAttachment(BoardAttachment $attachment, BoardCard $card, Board $board, int $user_id): void {
		Boards::transaction(function () use ($attachment, $card, $board, $user_id) {
			Boards::lockAndReload($card);
			$file_id = (int)$attachment->get('bat_fil_file_id');
			if ($file_id && (int)$card->get('bcd_cover_fil_file_id') === $file_id) {
				$card->set('bcd_cover_fil_file_id', null);
				$card->save();
			}
			// An editor on the board (the caller's cardFor()); the attachment's
			// File goes with it.
			SystemBase::rule_checked_write(function () use ($attachment) { $attachment->permanent_delete(); });
			Boards::log((int)$board->key, (int)$card->key, $user_id, 'card.updated', array('quiet' => true, 'what' => 'attachment'));
		});
	}

	/** Make an image attachment the card's cover (0 clears the cover). */
	public static function setCover(BoardCard $card, Board $board, int $user_id, int $attachment_id): void {
		$file_id = null;
		if ($attachment_id > 0) {
			$attachment = self::attachmentOn($card, $attachment_id);
			$file_id = (int)$attachment->get('bat_fil_file_id');
			if (!$file_id || !File::check_if_exists($file_id)
				|| strpos((string)(new File($file_id, TRUE))->get('fil_type'), 'image/') !== 0) {
				throw new BoardsRefusal('Only an image can be a card cover.');
			}
		}
		Boards::transaction(function () use ($card, $board, $user_id, $file_id) {
			Boards::lockAndReload($card);
			$card->set('bcd_cover_fil_file_id', $file_id);
			$card->set('bcd_update_time', 'now()');
			$card->save();
			$card->load();
			Boards::log((int)$board->key, (int)$card->key, $user_id, 'card.updated', array('quiet' => true, 'what' => 'cover'));
		});
	}
}
