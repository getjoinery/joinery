<?php
/**
 * BoardsImporter — bring a board across from another kanban tool's JSON export.
 *
 * The import accepts the board JSON such tools write (a board with labels, lists,
 * cards, checklists, members and an action log). What comes across:
 *
 *  - the board's name, description and background colour;
 *  - lists in order, archived lists staying archived;
 *  - labels with their names and colours (mapped to the nearest of our ten);
 *  - cards with title, description, due date, done state, position and archived
 *    state;
 *  - every checklist and its items, including done state, due date and assignee;
 *  - comments, with their original dates;
 *  - members.
 *
 * MEMBERS. The export names people, usually without email addresses. A person is
 * matched to a site member by email when the export carries one, and otherwise
 * the importer is asked to pick a site member or skip. A matched or picked person
 * is added to the new board as an editor so their cards and comments can be
 * theirs. Comments by anyone left unmatched are kept, under the importer's name,
 * with "Originally by {name}:" in front.
 *
 * ATTACHMENTS. Links come across as links. An uploaded file comes across as a link
 * back to where it lives, with a note on the card saying so. The site never
 * fetches attachment bytes: those files are served only to authenticated
 * requests, and fetching an address a user supplied from the server is a
 * server-side-request risk and a background job to run. With nothing to download,
 * the whole import runs in one request.
 *
 * NOT IMPORTED: add-on data, custom fields and automation rules.
 *
 * @version 1.0.0
 */

class BoardsImporter {

	/** The biggest export accepted, in bytes. */
	const MAX_BYTES = 10 * 1024 * 1024;

	/** Most lists, labels, comments or checklist items one export may carry. */
	const MAX_PARTS = 20000;

	/** The export's label colours onto our ten. Light and dark variants collapse to the base colour. */
	const COLOR_MAP = array(
		'green' => 'green', 'yellow' => 'yellow', 'orange' => 'orange', 'red' => 'red', 'purple' => 'purple',
		'blue' => 'blue', 'sky' => 'sky', 'lime' => 'lime', 'pink' => 'pink',
		'black' => 'gray', 'gray' => 'gray', 'grey' => 'gray',
	);

	// ------------------------------------------------------------------
	// Reading the export
	// ------------------------------------------------------------------

	/**
	 * Parse an export into the shape the rest of the class works with.
	 *
	 * @throws BoardsRefusal when it is not a board export
	 */
	public static function parse(string $json): array {
		if (strlen($json) > self::MAX_BYTES) {
			throw new BoardsRefusal('That file is too large to import.');
		}
		$data = json_decode($json, true);
		if (!is_array($data) || !isset($data['lists']) || !is_array($data['lists']) || !isset($data['cards']) || !is_array($data['cards'])) {
			throw new BoardsRefusal('That does not look like a board export. Export the board as JSON and choose that file.');
		}

		if (count($data['lists']) > 1000 || count((array)($data['labels'] ?? array())) > 1000
			|| count((array)($data['actions'] ?? array())) > self::MAX_PARTS || count((array)($data['checklists'] ?? array())) > self::MAX_PARTS) {
			throw new BoardsRefusal('That board is too large to import in one go.');
		}

		$members = array();
		foreach ((array)($data['members'] ?? array()) as $m) {
			if (is_array($m) && !empty($m['id'])) {
				$members[(string)$m['id']] = array(
					'id'       => (string)$m['id'],
					'name'     => trim((string)($m['fullName'] ?? $m['username'] ?? 'Unknown')),
					'username' => (string)($m['username'] ?? ''),
					'email'    => trim((string)($m['email'] ?? '')),
				);
			}
		}

		$comments = array();
		foreach ((array)($data['actions'] ?? array()) as $a) {
			if (is_array($a) && ($a['type'] ?? '') === 'commentCard' && !empty($a['data']['card']['id']) && trim((string)($a['data']['text'] ?? '')) !== '') {
				$creator = $a['memberCreator'] ?? array();
				$comments[(string)$a['data']['card']['id']][] = array(
					'text'       => (string)$a['data']['text'],
					'date'       => (string)($a['date'] ?? ''),
					'member_id'  => (string)($a['idMemberCreator'] ?? ($creator['id'] ?? '')),
					'name'       => trim((string)($creator['fullName'] ?? $creator['username'] ?? '')),
				);
				$id = (string)($a['idMemberCreator'] ?? ($creator['id'] ?? ''));
				if ($id !== '' && !isset($members[$id])) {
					$members[$id] = array('id' => $id, 'name' => trim((string)($creator['fullName'] ?? $creator['username'] ?? 'Unknown')),
						'username' => (string)($creator['username'] ?? ''), 'email' => '');
				}
			}
		}

		$checklists = array();
		foreach ((array)($data['checklists'] ?? array()) as $c) {
			if (is_array($c) && !empty($c['idCard'])) {
				$checklists[(string)$c['idCard']][] = $c;
			}
		}

		return array(
			'name'        => trim((string)($data['name'] ?? 'Imported board')) ?: 'Imported board',
			'description' => (string)($data['desc'] ?? ''),
			'background'  => self::background($data),
			'labels'      => array_values(array_filter((array)($data['labels'] ?? array()), 'is_array')),
			'lists'       => array_values(array_filter($data['lists'], 'is_array')),
			'cards'       => array_values(array_filter($data['cards'], 'is_array')),
			'checklists'  => $checklists,
			'comments'    => $comments,
			'members'     => $members,
		);
	}

	/** The board's background, as one of our colour tokens (an image becomes the default blue). */
	private static function background(array $data): string {
		$bg = strtolower(trim((string)($data['prefs']['background'] ?? '')));
		$bg = preg_replace('/_(light|dark)$/', '', $bg);
		if ($bg === 'grey') {
			$bg = 'gray';
		}
		return in_array($bg, BoardsManager::BACKGROUNDS, true) ? $bg : 'blue';
	}

	/** An export colour, as one of ours. */
	public static function mapColor($color): string {
		$color = strtolower(trim((string)$color));
		$color = preg_replace('/_(light|dark)$/', '', $color);
		return self::COLOR_MAP[$color] ?? 'gray';
	}

	// ------------------------------------------------------------------
	// Preview
	// ------------------------------------------------------------------

	/**
	 * What an import would bring, and who in the export matches a site member.
	 * Nothing is written.
	 */
	public static function preview(string $json): array {
		$parsed = self::parse($json);
		$people = array();
		foreach ($parsed['members'] as $member) {
			// Whether an address belongs to a site member is all the preview says —
			// never who. Anyone can upload a made-up export, and naming the member
			// would turn this screen into an email-to-person lookup.
			$people[] = array(
				'id'       => $member['id'],
				'name'     => $member['name'],
				'username' => $member['username'],
				'match'    => ($member['email'] !== '' && self::userByEmail($member['email'])) ? array('name' => 'a site member') : null,
			);
		}

		$open_cards = 0;
		foreach ($parsed['cards'] as $card) {
			if (empty($card['closed'])) {
				$open_cards++;
			}
		}
		$items = 0;
		foreach ($parsed['checklists'] as $lists) {
			foreach ($lists as $c) {
				$items += count((array)($c['checkItems'] ?? array()));
			}
		}
		$comment_count = 0;
		foreach ($parsed['comments'] as $c) {
			$comment_count += count($c);
		}

		return array(
			'name'       => $parsed['name'],
			'counts'     => array(
				'lists'      => count($parsed['lists']),
				'cards'      => count($parsed['cards']),
				'open_cards' => $open_cards,
				'labels'     => count($parsed['labels']),
				'checklist_items' => $items,
				'comments'   => $comment_count,
			),
			'members'    => $people,
		);
	}

	/** The site member with this email address, or null. */
	private static function userByEmail(string $email): ?int {
		$q = DbConnector::GetPreparedStatement(
			'SELECT usr_user_id FROM usr_users WHERE lower(usr_email) = lower(?) AND usr_delete_time IS NULL LIMIT 1');
		$q->execute(array($email));
		$id = $q->fetchColumn();
		return $id && !User::is_placeholder((int)$id) ? (int)$id : null;
	}

	// ------------------------------------------------------------------
	// Import
	// ------------------------------------------------------------------

	/**
	 * Make the board.
	 *
	 * @param array $member_map export member id => site user id (0 or absent to
	 *   skip). A member with an email that matches a site member is mapped without
	 *   being listed.
	 * @throws BoardsRefusal
	 */
	public static function import(string $json, int $importer_id, array $member_map = array()): Board {
		$parsed = self::parse($json);
		$ceiling = Boards::settingInt('boards_max_cards_per_board', 5000);
		if (count($parsed['cards']) > $ceiling) {
			throw new BoardsRefusal('This board has more cards than the limit of ' . number_format($ceiling) . ' for one board.');
		}

		// Resolve every person in the export to a site member, or to nobody.
		$people = array();
		foreach ($parsed['members'] as $id => $member) {
			$uid = isset($member_map[$id]) ? (int)$member_map[$id] : 0;
			if (!$uid && $member['email'] !== '') {
				$uid = (int)self::userByEmail($member['email']);
			}
			if ($uid && (User::is_placeholder($uid) || !User::check_if_exists($uid))) {
				$uid = 0;
			}
			$people[$id] = $uid ?: null;
		}

		return Boards::transaction(function () use ($parsed, $importer_id, $people) {
			$board = new Board(NULL);
			$board->set('bbd_name', Boards::cleanTitle($parsed['name'], 255, 'A board name'));
			$board->set('bbd_description', trim($parsed['description']) === '' ? null : $parsed['description']);
			$board->set('bbd_visibility', Board::VISIBILITY_INVITE_ONLY);
			$board->set('bbd_background', $parsed['background']);
			$board->set('bbd_usr_user_id_owner', $importer_id);
			$board->save();
			$board->load();
			$board_id = (int)$board->key;

			$admin = new BoardMember(NULL);
			$admin->set('bbm_bbd_board_id', $board_id);
			$admin->set('bbm_usr_user_id', $importer_id);
			$admin->set('bbm_role', BoardMember::ROLE_ADMIN);
			$admin->save();
			Boards::forgetRoles();

			// The people who came across can do their own work on the board.
			foreach (array_unique(array_filter($people)) as $uid) {
				if ($uid !== $importer_id) {
					BoardsManager::addUser($board, (int)$uid, BoardMember::ROLE_EDITOR);
				}
			}

			// Labels.
			$label_ids = array();
			foreach (self::byPosition($parsed['labels']) as $label) {
				$row = BoardsManager::saveLabel($board, null, (string)($label['name'] ?? ''), self::mapColor($label['color'] ?? ''));
				if (!empty($label['id'])) {
					$label_ids[(string)$label['id']] = (int)$row->key;
				}
			}

			// Lists, in order; a closed list stays archived.
			$list_ids = array();
			$list_models = array();
			foreach (self::byPosition($parsed['lists']) as $list) {
				$model = new BoardList(NULL);
				$model->set('bls_bbd_board_id', $board_id);
				$model->set('bls_name', Boards::cleanTitle($list['name'] ?? 'List', 255, 'A list name'));
				$model->set('bls_position', Boards::computePosition('list', $board_id));
				if (!empty($list['closed'])) {
					$model->set('bls_archived_time', 'now()');
				}
				$model->save();
				if (!empty($list['id'])) {
					$list_ids[(string)$list['id']] = (int)$model->key;
					$list_models[(string)$list['id']] = $model;
				}
			}

			// Cards, in order within their list.
			foreach (self::byPosition($parsed['cards']) as $card) {
				$list_key = (string)($card['idList'] ?? '');
				if (!isset($list_models[$list_key])) {
					continue;   // a card on a list the export does not describe
				}
				$title = trim(preg_replace('/\s+/u', ' ', (string)($card['name'] ?? '')));
				if ($title === '') {
					continue;
				}
				$description = (string)($card['desc'] ?? '');
				$links = array();
				foreach ((array)($card['attachments'] ?? array()) as $a) {
					if (is_array($a) && preg_match('#^https?://#i', (string)($a['url'] ?? ''))) {
						$links[] = $a;
						if (!empty($a['isUpload'])) {
							$description = rtrim($description) . ($description === '' ? '' : "\n\n")
								. 'Attachment "' . ($a['name'] ?? 'file') . '" stays at the original board: download it there and attach it here if you still need it.';
						}
					}
				}

				$model = BoardsCards::create($board, $list_models[$list_key], $importer_id, $title, array(
					'description' => $description,
					'due'         => !empty($card['due']) ? Boards::utcFromInput($card['due']) : null,
					'source'      => BoardCard::SOURCE_IMPORT,
					'notify'      => false,
					'log'         => false,
				));
				if (!empty($card['dueComplete'])) {
					$model->set('bcd_is_done', true);
				}
				if (!empty($card['closed'])) {
					$model->set('bcd_archived_time', 'now()');
				}
				if (!empty($card['dueComplete']) || !empty($card['closed'])) {
					$model->save();
				}

				foreach ((array)($card['idLabels'] ?? array()) as $source_label) {
					if (isset($label_ids[(string)$source_label])) {
						BoardsCards::addLabel($model, $board, $importer_id, $label_ids[(string)$source_label], false);
					}
				}
				foreach ((array)($card['idMembers'] ?? array()) as $source_member) {
					$uid = $people[(string)$source_member] ?? null;
					if ($uid) {
						BoardsCards::addMember($model, $board, $importer_id, (int)$uid, false);
					}
				}

				foreach (self::byPosition($parsed['checklists'][(string)($card['id'] ?? '')] ?? array()) as $source_checklist) {
					$checklist = BoardsCards::addChecklist($model, $board, $importer_id, (string)($source_checklist['name'] ?? 'Checklist') ?: 'Checklist');
					foreach (self::byPosition((array)($source_checklist['checkItems'] ?? array())) as $source_item) {
						$text = trim((string)($source_item['name'] ?? ''));
						if ($text === '') {
							continue;
						}
						$assignee = $people[(string)($source_item['idMember'] ?? '')] ?? null;
						$item = BoardsCards::addItem($checklist, $model, $board, $importer_id, $text, array(
							'user_id' => $assignee,
							'due'     => !empty($source_item['due']) ? Boards::utcFromInput($source_item['due']) : null,
							'notify'  => false,
						));
						if (($source_item['state'] ?? '') === 'complete') {
							$item->set('bci_is_done', true);
							$item->save();
						}
					}
				}

				foreach ($links as $a) {
					BoardsCards::attachLink($model, $board, $importer_id, (string)$a['url'], (string)($a['name'] ?? ''));
				}

				// Comments, oldest first, with the date they were written.
				$card_comments = $parsed['comments'][(string)($card['id'] ?? '')] ?? array();
				usort($card_comments, function ($a, $b) { return strcmp($a['date'], $b['date']); });
				foreach ($card_comments as $c) {
					// Always under the importer, crediting who wrote it. The export is
					// the importer's own file, so a name or an email in it proves
					// nothing: attributing a comment to a site member on its say-so
					// would let anyone put words in another member's mouth.
					$body = 'Originally by ' . ($c['name'] !== '' ? $c['name'] : 'someone') . ":\n\n" . $c['text'];
					$comment = new BoardComment(NULL);
					$comment->set('bco_bcd_card_id', (int)$model->key);
					$comment->set('bco_usr_user_id', $importer_id);
					$comment->set('bco_body', mb_substr($body, 0, 20000));
					$comment->set('bco_source', BoardComment::SOURCE_WEB);
					$ts = $c['date'] !== '' ? strtotime($c['date']) : false;
					if ($ts !== false) {
						$comment->set('bco_create_time', gmdate('Y-m-d H:i:s', $ts));
					}
					$comment->save();
				}
			}
			return $board;
		});
	}

	/** Items ordered by their `pos` field (the export's own order), keeping ties stable. */
	private static function byPosition(array $items): array {
		$items = array_values($items);
		$indexed = array();
		foreach ($items as $i => $item) {
			$indexed[] = array('pos' => (float)($item['pos'] ?? $i), 'i' => $i, 'item' => $item);
		}
		usort($indexed, function ($a, $b) {
			return $a['pos'] <=> $b['pos'] ?: $a['i'] <=> $b['i'];
		});
		return array_map(function ($row) { return $row['item']; }, $indexed);
	}
}
