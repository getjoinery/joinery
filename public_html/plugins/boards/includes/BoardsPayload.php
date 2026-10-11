<?php
/**
 * BoardsPayload — how a board, a card and a person's cards are described to the
 * browser.
 *
 * The board page, the poll and the card modal all draw from the same shapes, and
 * the shapes are built here and nowhere else so they cannot drift. Every time is
 * an ISO-8601 UTC instant; the browser puts it in the viewer's zone. Every piece
 * of Markdown is rendered here with the restricted profile (text other people
 * wrote), so the browser can set it as HTML without sanitising it again.
 *
 * @version 1.0.0
 */

class BoardsPayload {

	/** Cards shown in the card-back activity list. */
	const ACTIVITY_LIMIT = 50;

	/** The thumbnail size key for an image attachment. */
	private static function thumbSize(): string {
		require_once(PathHelper::getIncludePath('includes/ImageSizeRegistry.php'));
		return ImageSizeRegistry::has_size('content') ? 'content' : 'original';
	}

	// ------------------------------------------------------------------
	// A board
	// ------------------------------------------------------------------

	/** The board's own fields. */
	public static function board(Board $board): array {
		return array(
			'id'          => (int)$board->key,
			'name'        => (string)$board->get('bbd_name'),
			'description' => (string)$board->get('bbd_description'),
			'visibility'  => (string)$board->get('bbd_visibility'),
			'background'  => (string)$board->get('bbd_background'),
			'background_url' => self::backgroundUrl((string)$board->get('bbd_background')),
			'is_template' => (bool)$board->get('bbd_is_template'),
			'archived'    => (bool)$board->get('bbd_archived_time'),
			'owner_id'    => (int)$board->get('bbd_usr_user_id_owner'),
		);
	}

	/** The URL for an uploaded background ('file:123'), or null for a colour. */
	public static function backgroundUrl(string $background): ?string {
		$file_id = Boards::backgroundFileId($background);
		if (!$file_id || !File::check_if_exists($file_id)) {
			return null;
		}
		$file = new File($file_id, TRUE);
		return $file->get_url('original');
	}

	/**
	 * Everything the board page needs in one go: the board, the person's role, the
	 * lists, every card on them, the labels, the people, and the cursor the poll
	 * continues from.
	 */
	public static function boardPage(Board $board, int $user_id, string $role, bool $include_archived = false): array {
		$board_id = (int)$board->key;

		$lists = array();
		$q = DbConnector::GetPreparedStatement(
			'SELECT * FROM bls_lists WHERE bls_bbd_board_id = ?'
			. ($include_archived ? '' : ' AND bls_archived_time IS NULL') . ' ORDER BY bls_position, bls_list_id');
		$q->execute(array($board_id));
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$lists[] = self::listRow($row);
		}

		$q = DbConnector::GetPreparedStatement(
			'SELECT * FROM bcd_cards WHERE bcd_bbd_board_id = ? AND bcd_delete_time IS NULL'
			. ($include_archived ? '' : ' AND bcd_archived_time IS NULL') . ' ORDER BY bcd_position, bcd_card_id');
		$q->execute(array($board_id));

		$people = self::people($board);

		return array(
			'board'          => self::board($board),
			'role'           => $role,
			'lists'          => $lists,
			'cards'          => self::cardSummaries($q->fetchAll(PDO::FETCH_ASSOC)),
			'labels'         => self::labels($board_id),
			'people'         => $people['people'],
			'people_stamp'   => $people['stamp'],
			'watching_board' => self::isWatchingBoard($board_id, $user_id),
			'cursor'         => Boards::latestActivityId($board_id),
			'settings'       => Boards::clientSettings(),
		);
	}

	/** One list row. */
	public static function listRow(array $row): array {
		return array(
			'id'       => (int)$row['bls_list_id'],
			'name'     => (string)$row['bls_name'],
			'position' => (float)$row['bls_position'],
			'archived' => !empty($row['bls_archived_time']),
		);
	}

	/** The board's labels in order. */
	public static function labels(int $board_id): array {
		$q = DbConnector::GetPreparedStatement(
			'SELECT blb_label_id, blb_name, blb_color, blb_position FROM blb_labels WHERE blb_bbd_board_id = ? ORDER BY blb_position, blb_label_id');
		$q->execute(array($board_id));
		$out = array();
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$out[] = array(
				'id'       => (int)$row['blb_label_id'],
				'name'     => (string)$row['blb_name'],
				'color'    => (string)$row['blb_color'],
				'position' => (float)$row['blb_position'],
			);
		}
		return $out;
	}

	/**
	 * The people on the board's explicit list, and a stamp that changes when the
	 * list does (so the poll only resends it when it moved).
	 *
	 * @return array{people:array,stamp:string}
	 */
	public static function people(Board $board): array {
		$members = Boards::explicitMembers($board);
		ksort($members);
		$people = array();
		foreach ($members as $uid => $role) {
			$person = Boards::person($uid);
			$people[] = array('id' => $uid, 'name' => $person['name'], 'avatar' => $person['avatar'], 'role' => $role);
		}
		return array('people' => $people, 'stamp' => md5(json_encode($members) . '|' . $board->get('bbd_visibility')));
	}

	/** Which direct and group rows make up the board's membership — for the settings screen. */
	public static function memberRows(Board $board): array {
		$q = DbConnector::GetPreparedStatement(
			'SELECT bbm_board_member_id, bbm_usr_user_id, bbm_grp_group_id, bbm_role FROM bbm_board_members
			 WHERE bbm_bbd_board_id = ? ORDER BY bbm_board_member_id');
		$q->execute(array((int)$board->key));
		$out = array();
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$entry = array('id' => (int)$row['bbm_board_member_id'], 'role' => (string)$row['bbm_role']);
			if ($row['bbm_usr_user_id']) {
				$person = Boards::person((int)$row['bbm_usr_user_id']);
				$entry += array('kind' => 'user', 'user_id' => (int)$row['bbm_usr_user_id'],
					'name' => $person['name'], 'avatar' => $person['avatar']);
			} else {
				$group = new Group((int)$row['bbm_grp_group_id'], TRUE);
				$entry += array('kind' => 'group', 'group_id' => (int)$row['bbm_grp_group_id'],
					'name' => $group->key ? (string)$group->get('grp_name') : 'Removed group');
			}
			$out[] = $entry;
		}
		return $out;
	}

	public static function isWatchingBoard(int $board_id, int $user_id): bool {
		$q = DbConnector::GetPreparedStatement('SELECT 1 FROM bbw_board_watchers WHERE bbw_bbd_board_id = ? AND bbw_usr_user_id = ?');
		$q->execute(array($board_id, $user_id));
		return (bool)$q->fetchColumn();
	}

	// ------------------------------------------------------------------
	// Cards, as they sit on the board
	// ------------------------------------------------------------------

	/**
	 * The front of each card: just what the board draws.
	 *
	 * Takes raw bcd_cards rows and fills the rest in with a handful of queries for
	 * the whole set, never one per card.
	 *
	 * @param array[] $rows bcd_cards rows
	 * @return array[]
	 */
	public static function cardSummaries(array $rows): array {
		if (!$rows) {
			return array();
		}
		$ids = array();
		foreach ($rows as $row) {
			$ids[] = (int)$row['bcd_card_id'];
		}
		$array = '{' . implode(',', $ids) . '}';

		$by_card = function (string $sql, callable $reduce) use ($array) {
			$q = DbConnector::GetPreparedStatement($sql);
			$q->execute(array($array));
			$out = array();
			foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) {
				$reduce($out, $r);
			}
			return $out;
		};

		$members = $by_card(
			'SELECT bcm_bcd_card_id, bcm_usr_user_id FROM bcm_card_members WHERE bcm_bcd_card_id = ANY(?::bigint[]) ORDER BY bcm_card_member_id',
			function (&$out, $r) { $out[(int)$r[0]][] = (int)$r[1]; });
		$labels = $by_card(
			'SELECT bcl_bcd_card_id, bcl_blb_label_id FROM bcl_card_labels WHERE bcl_bcd_card_id = ANY(?::bigint[])',
			function (&$out, $r) { $out[(int)$r[0]][] = (int)$r[1]; });
		$checklists = $by_card(
			'SELECT bck.bck_bcd_card_id, COUNT(bci.bci_checklist_item_id), COUNT(*) FILTER (WHERE bci.bci_is_done)
			 FROM bck_checklists bck JOIN bci_checklist_items bci ON bci.bci_bck_checklist_id = bck.bck_checklist_id
			 WHERE bck.bck_bcd_card_id = ANY(?::bigint[]) GROUP BY bck.bck_bcd_card_id',
			function (&$out, $r) { $out[(int)$r[0]] = array('total' => (int)$r[1], 'done' => (int)$r[2]); });
		$comments = $by_card(
			'SELECT bco_bcd_card_id, COUNT(*) FROM bco_comments WHERE bco_bcd_card_id = ANY(?::bigint[]) AND bco_delete_time IS NULL GROUP BY bco_bcd_card_id',
			function (&$out, $r) { $out[(int)$r[0]] = (int)$r[1]; });
		$attachments = $by_card(
			'SELECT bat_bcd_card_id, COUNT(*) FROM bat_attachments WHERE bat_bcd_card_id = ANY(?::bigint[]) GROUP BY bat_bcd_card_id',
			function (&$out, $r) { $out[(int)$r[0]] = (int)$r[1]; });

		$out = array();
		foreach ($rows as $row) {
			$id = (int)$row['bcd_card_id'];
			$out[] = array(
				'id'              => $id,
				'list_id'         => (int)$row['bcd_bls_list_id'],
				'position'        => (float)$row['bcd_position'],
				'title'           => (string)$row['bcd_title'],
				'due'             => Boards::isoFromUtc($row['bcd_due_time']),
				'is_done'         => self::bool($row['bcd_is_done']),
				'labels'          => $labels[$id] ?? array(),
				'members'         => $members[$id] ?? array(),
				'checklist'       => $checklists[$id] ?? array('total' => 0, 'done' => 0),
				'comments'        => $comments[$id] ?? 0,
				'attachments'     => $attachments[$id] ?? 0,
				'cover'           => self::coverUrl($row['bcd_cover_fil_file_id']),
				'has_description' => trim((string)$row['bcd_description']) !== '',
				'is_template'     => self::bool($row['bcd_is_template']),
				'archived'        => !empty($row['bcd_archived_time']),
			);
		}
		return $out;
	}

	/** One card, as the board draws it (read fresh from the database), or null if it is gone. */
	public static function cardSummary(int $card_id): ?array {
		$q = DbConnector::GetPreparedStatement('SELECT * FROM bcd_cards WHERE bcd_card_id = ?');
		$q->execute(array($card_id));
		$row = $q->fetch(PDO::FETCH_ASSOC);
		return $row ? self::cardSummaries(array($row))[0] : null;
	}

	/** A cover image's URL, or null. */
	private static function coverUrl($file_id): ?string {
		$file_id = (int)$file_id;
		if (!$file_id || !File::check_if_exists($file_id)) {
			return null;
		}
		$file = new File($file_id, TRUE);
		if (strpos((string)$file->get('fil_type'), 'image/') !== 0) {
			return null;
		}
		return $file->get_url(self::thumbSize());
	}

	/** Postgres booleans arrive as true/false or 't'/'f' depending on the path. */
	private static function bool($value): bool {
		return Boards::flag($value);
	}

	// ------------------------------------------------------------------
	// A card, opened
	// ------------------------------------------------------------------

	/** The card back: everything about one card. */
	public static function cardDetail(BoardCard $card, Board $board, int $user_id, string $role): array {
		$card_id = (int)$card->key;

		$list_name = '';
		if (BoardList::check_if_exists((int)$card->get('bcd_bls_list_id'))) {
			$list_name = (string)(new BoardList((int)$card->get('bcd_bls_list_id'), TRUE))->get('bls_name');
		}

		$row = array(
			'bcd_card_id' => $card_id,
			'bcd_bls_list_id' => $card->get('bcd_bls_list_id'),
			'bcd_position' => $card->get('bcd_position'),
			'bcd_title' => $card->get('bcd_title'),
			'bcd_due_time' => $card->get('bcd_due_time'),
			'bcd_is_done' => $card->get('bcd_is_done'),
			'bcd_cover_fil_file_id' => $card->get('bcd_cover_fil_file_id'),
			'bcd_description' => $card->get('bcd_description'),
			'bcd_is_template' => $card->get('bcd_is_template'),
			'bcd_archived_time' => $card->get('bcd_archived_time'),
		);
		$summary = self::cardSummaries(array($row))[0];

		$description = (string)$card->get('bcd_description');
		$people = array();
		foreach ($summary['members'] as $uid) {
			$people[] = Boards::person($uid);
		}

		return array(
			'card' => array(
				'id'               => $card_id,
				'board_id'         => (int)$board->key,
				'list_id'          => (int)$card->get('bcd_bls_list_id'),
				'list_name'        => $list_name,
				'title'            => (string)$card->get('bcd_title'),
				'description'      => $description,
				'description_html' => $description === '' ? '' : MarkdownRenderer::render($description, true, true),
				'due'              => $summary['due'],
				'is_done'          => $summary['is_done'],
				'archived'         => $summary['archived'],
				'is_template'      => $summary['is_template'],
				'cover'            => $summary['cover'],
				'cover_file_id'    => (int)$card->get('bcd_cover_fil_file_id'),
				'source'           => (string)$card->get('bcd_source'),
				'creator'          => $card->get('bcd_usr_user_id_creator') ? Boards::person((int)$card->get('bcd_usr_user_id_creator')) : null,
				'created'          => Boards::isoFromUtc($card->get('bcd_create_time')),
			),
			'board'       => array('id' => (int)$board->key, 'name' => (string)$board->get('bbd_name')),
			'role'        => $role,
			'labels'      => $summary['labels'],
			'members'     => $people,
			'checklists'  => self::checklists($card_id),
			'comments'    => self::comments($card_id, $user_id, $role),
			'attachments' => self::attachments($card_id, (int)$card->get('bcd_cover_fil_file_id')),
			'activity'    => self::activity($card_id),
			'watching'    => self::isWatchingCard($card, $user_id),
			// The email this card was made from, as this viewer may see it: a link when they
			// can open its mailbox, otherwise a sentence that says why not.
			'source_email' => BoardsEmail::sourceEmail($card, $user_id),
		);
	}

	/** @return array[] */
	public static function checklists(int $card_id): array {
		$q = DbConnector::GetPreparedStatement(
			'SELECT bck_checklist_id, bck_title, bck_position FROM bck_checklists WHERE bck_bcd_card_id = ? ORDER BY bck_position, bck_checklist_id');
		$q->execute(array($card_id));
		$lists = array();
		$index = array();
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$index[(int)$row['bck_checklist_id']] = count($lists);
			$lists[] = array('id' => (int)$row['bck_checklist_id'], 'title' => (string)$row['bck_title'],
				'position' => (float)$row['bck_position'], 'items' => array());
		}
		if ($lists) {
			$q = DbConnector::GetPreparedStatement(
				'SELECT * FROM bci_checklist_items WHERE bci_bck_checklist_id = ANY(?::bigint[]) ORDER BY bci_position, bci_checklist_item_id');
			$q->execute(array('{' . implode(',', array_keys($index)) . '}'));
			foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
				$lists[$index[(int)$row['bci_bck_checklist_id']]]['items'][] = array(
					'id'       => (int)$row['bci_checklist_item_id'],
					'text'     => (string)$row['bci_text'],
					'is_done'  => self::bool($row['bci_is_done']),
					'user'     => $row['bci_usr_user_id'] ? Boards::person((int)$row['bci_usr_user_id']) : null,
					'due'      => Boards::isoFromUtc($row['bci_due_time']),
					'position' => (float)$row['bci_position'],
				);
			}
		}
		return $lists;
	}

	/** Newest first. */
	public static function comments(int $card_id, int $user_id, string $role): array {
		$q = DbConnector::GetPreparedStatement(
			'SELECT * FROM bco_comments WHERE bco_bcd_card_id = ? AND bco_delete_time IS NULL ORDER BY bco_comment_id DESC');
		$q->execute(array($card_id));
		$out = array();
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$author = (int)$row['bco_usr_user_id'];
			$mine = $author === $user_id;
			$out[] = array(
				'id'        => (int)$row['bco_comment_id'],
				'user'      => $author ? Boards::person($author) : null,
				'body'      => (string)$row['bco_body'],
				'body_html' => MarkdownRenderer::render((string)$row['bco_body'], true, true),
				'source'    => (string)$row['bco_source'],
				'created'   => Boards::isoFromUtc($row['bco_create_time']),
				'edited'    => Boards::isoFromUtc($row['bco_edited_time']),
				// Your own comment is yours to edit; a board admin can remove anyone's.
				'can_edit'   => $mine && Boards::roleAtLeast($role, 'editor'),
				'can_delete' => ($mine && Boards::roleAtLeast($role, 'editor')) || $role === Boards::ROLE_ADMIN,
			);
		}
		return $out;
	}

	/** @return array[] */
	public static function attachments(int $card_id, int $cover_file_id = 0): array {
		$q = DbConnector::GetPreparedStatement('SELECT * FROM bat_attachments WHERE bat_bcd_card_id = ? ORDER BY bat_attachment_id');
		$q->execute(array($card_id));
		$out = array();
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$entry = array(
				'id'      => (int)$row['bat_attachment_id'],
				'user'    => $row['bat_usr_user_id'] ? Boards::person((int)$row['bat_usr_user_id']) : null,
				'created' => Boards::isoFromUtc($row['bat_create_time']),
			);
			if ($row['bat_fil_file_id'] && File::check_if_exists((int)$row['bat_fil_file_id'])) {
				$file = new File((int)$row['bat_fil_file_id'], TRUE);
				$is_image = strpos((string)$file->get('fil_type'), 'image/') === 0;
				$entry += array(
					'is_link'  => false,
					'file_id'  => (int)$file->key,
					'name'     => (string)($file->get('fil_title') ?: $row['bat_name']),
					'url'      => $file->get_url('original'),
					'mime'     => (string)$file->get('fil_type'),
					'size'     => (int)$file->size_bytes(),
					'is_image' => $is_image,
					'thumb'    => $is_image ? $file->get_url(self::thumbSize()) : null,
					'is_cover' => $cover_file_id && (int)$file->key === $cover_file_id,
				);
			} elseif ($row['bat_fil_file_id']) {
				// The File was removed out from under the attachment.
				$entry += array('is_link' => false, 'file_id' => 0, 'name' => (string)$row['bat_name'] . ' (removed)',
					'url' => null, 'mime' => '', 'size' => 0, 'is_image' => false, 'thumb' => null, 'is_cover' => false);
			} else {
				$entry += array('is_link' => true, 'name' => (string)($row['bat_name'] ?: $row['bat_url']),
					'url' => (string)$row['bat_url'], 'is_image' => false, 'thumb' => null, 'is_cover' => false);
			}
			$out[] = $entry;
		}
		return $out;
	}

	/** The card's history, newest first, without the changes that are only there for the poll. */
	public static function activity(int $card_id): array {
		$q = DbConnector::GetPreparedStatement(
			'SELECT * FROM bac_activity WHERE bac_bcd_card_id = ? ORDER BY bac_activity_id DESC LIMIT ' . (self::ACTIVITY_LIMIT * 2));
		$q->execute(array($card_id));
		$out = array();
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$data = $row['bac_data'] ? (json_decode($row['bac_data'], true) ?: array()) : array();
			if (!empty($data['quiet'])) {
				continue;
			}
			$out[] = array(
				'id'      => (int)$row['bac_activity_id'],
				'verb'    => (string)$row['bac_verb'],
				'user'    => $row['bac_usr_user_id'] ? Boards::person((int)$row['bac_usr_user_id']) : null,
				'data'    => $data,
				'created' => Boards::isoFromUtc($row['bac_create_time']),
			);
			if (count($out) >= self::ACTIVITY_LIMIT) {
				break;
			}
		}
		return $out;
	}

	/** Is this person on the receiving end of a card's activity? */
	public static function isWatchingCard(BoardCard $card, int $user_id): bool {
		return in_array($user_id, BoardsNotifier::cardWatcherIds($card), true);
	}

	// ------------------------------------------------------------------
	// The poll
	// ------------------------------------------------------------------

	/**
	 * What changed on a board since the browser's cursor.
	 *
	 * The rows above the cursor name the cards they touched; each is described as
	 * it stands now (a card that has since been deleted or archived is reported as
	 * gone). The lists and labels are cheap and go every time, so a rename or a
	 * reorder needs no verb of its own.
	 */
	public static function poll(Board $board, int $user_id, string $role, int $after_activity_id, string $people_stamp = ''): array {
		$board_id = (int)$board->key;
		$q = DbConnector::GetPreparedStatement(
			'SELECT * FROM bac_activity WHERE bac_bbd_board_id = ? AND bac_activity_id > ? ORDER BY bac_activity_id LIMIT 500');
		$q->execute(array($board_id, $after_activity_id));
		$rows = $q->fetchAll(PDO::FETCH_ASSOC);

		$cursor = $after_activity_id;
		$touched = array();
		$activity = array();
		foreach ($rows as $row) {
			$cursor = max($cursor, (int)$row['bac_activity_id']);
			if ($row['bac_bcd_card_id']) {
				$touched[(int)$row['bac_bcd_card_id']] = true;
			}
			$data = $row['bac_data'] ? (json_decode($row['bac_data'], true) ?: array()) : array();
			if (!empty($data['renumbered_list'])) {
				// Every card on that list changed position.
				$all = DbConnector::GetPreparedStatement('SELECT bcd_card_id FROM bcd_cards WHERE bcd_bls_list_id = ? AND bcd_bbd_board_id = ?');
				$all->execute(array((int)$data['renumbered_list'], $board_id));
				foreach ($all->fetchAll(PDO::FETCH_COLUMN) as $cid) {
					$touched[(int)$cid] = true;
				}
			}
			if (empty($data['quiet'])) {
				$activity[] = array(
					'id'      => (int)$row['bac_activity_id'],
					'card_id' => $row['bac_bcd_card_id'] ? (int)$row['bac_bcd_card_id'] : null,
					'verb'    => (string)$row['bac_verb'],
					'user'    => $row['bac_usr_user_id'] ? Boards::person((int)$row['bac_usr_user_id']) : null,
					'data'    => $data,
				);
			}
		}

		$cards = array();
		$gone = array();
		if ($touched) {
			$ids = array_keys($touched);
			$q = DbConnector::GetPreparedStatement(
				'SELECT * FROM bcd_cards WHERE bcd_card_id = ANY(?::bigint[]) AND bcd_bbd_board_id = ?');
			$q->execute(array('{' . implode(',', $ids) . '}', $board_id));
			$live = array();
			foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
				if (empty($row['bcd_delete_time']) && empty($row['bcd_archived_time'])) {
					$live[] = $row;
				} else {
					$gone[(int)$row['bcd_card_id']] = true;
				}
			}
			$cards = self::cardSummaries($live);
			foreach ($ids as $id) {
				$found = false;
				foreach ($cards as $c) {
					if ($c['id'] === $id) { $found = true; break; }
				}
				if (!$found) {
					$gone[$id] = true;
				}
			}
		}

		$lists = array();
		$q = DbConnector::GetPreparedStatement(
			'SELECT * FROM bls_lists WHERE bls_bbd_board_id = ? AND bls_archived_time IS NULL ORDER BY bls_position, bls_list_id');
		$q->execute(array($board_id));
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$lists[] = self::listRow($row);
		}

		$people = self::people($board);
		$out = array(
			'cursor'   => $cursor,
			'board'    => self::board($board),
			'role'     => $role,
			'lists'    => $lists,
			'labels'   => self::labels($board_id),
			'cards'    => $cards,
			'gone'     => array_keys($gone),
			'activity' => $activity,
			'people_stamp' => $people['stamp'],
		);
		if ($people['stamp'] !== $people_stamp) {
			$out['people'] = $people['people'];
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// The board list and "My cards"
	// ------------------------------------------------------------------

	/**
	 * The boards a person can open, split the way the board list page shows them:
	 * theirs, open to all members, templates, and archived.
	 */
	public static function boardIndex(int $user_id): array {
		$boards = new MultiBoard(array('visible_to' => $user_id, 'deleted' => false), array('bbd_name' => 'ASC'));

		$rows = array();
		$ids = array();
		foreach ($boards as $board) {
			$role = Boards::roleFor($board, $user_id);
			if ($role === null) {
				continue;
			}
			$ids[] = (int)$board->key;
			$rows[(int)$board->key] = array('board' => $board, 'role' => $role);
		}

		$counts = array();
		if ($ids) {
			$q = DbConnector::GetPreparedStatement(
				'SELECT bcd_bbd_board_id, COUNT(*) FROM bcd_cards
				 WHERE bcd_bbd_board_id = ANY(?::bigint[]) AND bcd_delete_time IS NULL AND bcd_archived_time IS NULL AND bcd_is_template = false
				 GROUP BY bcd_bbd_board_id');
			$q->execute(array('{' . implode(',', $ids) . '}'));
			foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) {
				$counts[(int)$r[0]] = (int)$r[1];
			}
		}

		$out = array('mine' => array(), 'open' => array(), 'templates' => array(), 'archived' => array());
		foreach ($rows as $id => $entry) {
			$board = $entry['board'];
			$card = array(
				'id'         => $id,
				'name'       => (string)$board->get('bbd_name'),
				'description' => (string)$board->get('bbd_description'),
				'background' => (string)$board->get('bbd_background'),
				'background_url' => self::backgroundUrl((string)$board->get('bbd_background')),
				'visibility' => (string)$board->get('bbd_visibility'),
				'role'       => $entry['role'],
				'cards'      => $counts[$id] ?? 0,
			);
			if ($board->get('bbd_is_template')) {
				$out['templates'][] = $card;
			} elseif ($board->get('bbd_archived_time')) {
				$out['archived'][] = $card;
			} elseif (self::isExplicitlyOn($board, $user_id)) {
				$out['mine'][] = $card;
			} else {
				$out['open'][] = $card;
			}
		}
		return $out;
	}

	/**
	 * The boards a person can put cards on (an editor or admin of a live, non-
	 * template board) with their lists — the Move and Copy pickers.
	 *
	 * @return array[]
	 */
	public static function moveTargets(int $user_id): array {
		$out = array();
		$boards = new MultiBoard(array('visible_to' => $user_id, 'deleted' => false, 'archived' => false), array('bbd_name' => 'ASC'));
		foreach ($boards as $board) {
			if (Boards::flag($board->get('bbd_is_template'))
				|| !Boards::roleAtLeast(Boards::roleFor($board, $user_id), 'editor')) {
				continue;
			}
			$lists = array();
			$q = DbConnector::GetPreparedStatement(
				'SELECT * FROM bls_lists WHERE bls_bbd_board_id = ? AND bls_archived_time IS NULL ORDER BY bls_position, bls_list_id');
			$q->execute(array((int)$board->key));
			foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
				$lists[] = self::listRow($row);
			}
			$out[] = array('id' => (int)$board->key, 'name' => (string)$board->get('bbd_name'), 'lists' => $lists);
		}
		return $out;
	}

	/** Is the person on the board by name (directly, through a group, or as its owner) — not merely because it is open to all? */
	private static function isExplicitlyOn(Board $board, int $user_id): bool {
		if ((int)$board->get('bbd_usr_user_id_owner') === $user_id) {
			return true;
		}
		return array_key_exists($user_id, Boards::explicitMembers($board));
	}

	/**
	 * Every open card assigned to a person (and every open checklist item), across
	 * all the boards they can still open, soonest due first and undated last.
	 *
	 * @param array $opts window => 'week' keeps only overdue and the next seven days
	 * @return array{cards:array[],count:int}
	 */
	public static function myCards(int $user_id, array $opts = array()): array {
		$window = ($opts['window'] ?? '') === 'week';
		$horizon = gmdate('Y-m-d H:i:s', time() + 7 * 86400);

		$q = DbConnector::GetPreparedStatement(
			'SELECT bcd.bcd_card_id, bcd.bcd_bbd_board_id, bcd.bcd_title, bcd.bcd_due_time, bcd.bcd_is_done,
			        bls.bls_name, bbd.bbd_name
			 FROM bcd_cards bcd
			 JOIN bcm_card_members bcm ON bcm.bcm_bcd_card_id = bcd.bcd_card_id
			 JOIN bls_lists bls ON bls.bls_list_id = bcd.bcd_bls_list_id AND bls.bls_archived_time IS NULL
			 JOIN bbd_boards bbd ON bbd.bbd_board_id = bcd.bcd_bbd_board_id
			 WHERE bcm.bcm_usr_user_id = ? AND bcd.bcd_is_done = false AND bcd.bcd_is_template = false
			   AND bcd.bcd_delete_time IS NULL AND bcd.bcd_archived_time IS NULL
			   AND bbd.bbd_delete_time IS NULL AND bbd.bbd_archived_time IS NULL AND bbd.bbd_is_template = false
			 ORDER BY bcd.bcd_due_time ASC NULLS LAST, bcd.bcd_card_id');
		$q->execute(array($user_id));
		$rows = $q->fetchAll(PDO::FETCH_ASSOC);

		$q = DbConnector::GetPreparedStatement(
			'SELECT bci.bci_checklist_item_id, bci.bci_text, bci.bci_due_time, bcd.bcd_card_id, bcd.bcd_bbd_board_id,
			        bcd.bcd_title, bls.bls_name, bbd.bbd_name
			 FROM bci_checklist_items bci
			 JOIN bck_checklists bck ON bck.bck_checklist_id = bci.bci_bck_checklist_id
			 JOIN bcd_cards bcd ON bcd.bcd_card_id = bck.bck_bcd_card_id
			 JOIN bls_lists bls ON bls.bls_list_id = bcd.bcd_bls_list_id AND bls.bls_archived_time IS NULL
			 JOIN bbd_boards bbd ON bbd.bbd_board_id = bcd.bcd_bbd_board_id
			 WHERE bci.bci_usr_user_id = ? AND bci.bci_is_done = false AND bcd.bcd_is_template = false
			   AND bcd.bcd_delete_time IS NULL AND bcd.bcd_archived_time IS NULL
			   AND bbd.bbd_delete_time IS NULL AND bbd.bbd_archived_time IS NULL AND bbd.bbd_is_template = false
			 ORDER BY bci.bci_due_time ASC NULLS LAST, bci.bci_checklist_item_id');
		$q->execute(array($user_id));
		$item_rows = $q->fetchAll(PDO::FETCH_ASSOC);

		$now = gmdate('Y-m-d H:i:s');
		$allowed = array();
		$may = function (int $board_id) use (&$allowed, $user_id) {
			if (!isset($allowed[$board_id])) {
				$allowed[$board_id] = Board::check_if_exists($board_id)
					&& Boards::roleFor(new Board($board_id, TRUE), $user_id) !== null;
			}
			return $allowed[$board_id];
		};

		$cards = array();
		foreach ($rows as $row) {
			if (!$may((int)$row['bcd_bbd_board_id'])) {
				continue;
			}
			$due = $row['bcd_due_time'];
			if ($window && (!$due || $due > $horizon)) {
				continue;
			}
			$cards[] = array(
				'kind' => 'card', 'id' => (int)$row['bcd_card_id'], 'board_id' => (int)$row['bcd_bbd_board_id'],
				'board_name' => (string)$row['bbd_name'], 'list_name' => (string)$row['bls_name'],
				'title' => (string)$row['bcd_title'], 'due' => Boards::isoFromUtc($due),
				'overdue' => $due !== null && $due < $now,
			);
		}
		foreach ($item_rows as $row) {
			if (!$may((int)$row['bcd_bbd_board_id'])) {
				continue;
			}
			$due = $row['bci_due_time'];
			if ($window && (!$due || $due > $horizon)) {
				continue;
			}
			$cards[] = array(
				'kind' => 'item', 'id' => (int)$row['bcd_card_id'], 'item_id' => (int)$row['bci_checklist_item_id'],
				'board_id' => (int)$row['bcd_bbd_board_id'], 'board_name' => (string)$row['bbd_name'],
				'list_name' => (string)$row['bls_name'], 'title' => (string)$row['bci_text'],
				'card_title' => (string)$row['bcd_title'], 'due' => Boards::isoFromUtc($due),
				'overdue' => $due !== null && $due < $now,
			);
		}

		usort($cards, function ($a, $b) {
			if ($a['due'] === $b['due']) { return 0; }
			if ($a['due'] === null) { return 1; }
			if ($b['due'] === null) { return -1; }
			return strcmp($a['due'], $b['due']);
		});
		return array('cards' => $cards, 'count' => count($cards));
	}
}
