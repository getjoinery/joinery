<?php
/**
 * BoardsTemplates — starter boards, board templates and copies.
 *
 * Three things make a board from a shape: a starter (a definition the plugin
 * ships in starter_boards.json), a template board (any board an admin marked as a
 * template — people copy it instead of using it), and a plain copy. All three
 * build the same way: a "definition" of labels, lists and cards, laid down on a
 * new board. A copy keeps the shape and none of the people or history — lists,
 * labels, cards (title, description, labels, checklists with every item not
 * done), and nothing else.
 *
 * @version 1.0.0
 */

class BoardsTemplates {

	/** @return array<string,array{key:string,name:string,description:string,background:string}> */
	public static function starters(): array {
		$out = array();
		foreach (self::load() as $key => $def) {
			$out[$key] = array(
				'key'         => $key,
				'name'        => (string)$def['name'],
				'description' => (string)($def['description'] ?? ''),
				'background'  => (string)($def['background'] ?? 'blue'),
			);
		}
		return $out;
	}

	private static function load(): array {
		static $defs = null;
		if ($defs === null) {
			$raw = file_get_contents(PathHelper::getIncludePath('plugins/boards/includes/starter_boards.json'));
			$defs = $raw === false ? array() : (json_decode($raw, true) ?: array());
		}
		return $defs;
	}

	/** @throws BoardsRefusal */
	public static function definition(string $key): array {
		$defs = self::load();
		if (!isset($defs[$key])) {
			throw new BoardsRefusal('Unknown starter board.');
		}
		return $defs[$key];
	}

	/**
	 * What a board is made of, in the form build() lays down: its labels, its live
	 * lists in order and the live cards on them.
	 */
	public static function snapshot(Board $source): array {
		$board_id = (int)$source->key;
		$def = array('labels' => array(), 'lists' => array());

		foreach (BoardsPayload::labels($board_id) as $label) {
			$def['labels'][] = array('name' => $label['name'], 'color' => $label['color']);
		}

		$label_names = array();
		foreach (BoardsPayload::labels($board_id) as $label) {
			$label_names[$label['id']] = $label['name'] !== '' ? $label['name'] : ('#' . $label['color']);
		}

		$q = DbConnector::GetPreparedStatement(
			'SELECT bls_list_id, bls_name FROM bls_lists WHERE bls_bbd_board_id = ? AND bls_archived_time IS NULL ORDER BY bls_position, bls_list_id');
		$q->execute(array($board_id));
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $list) {
			$cards = array();
			$c = DbConnector::GetPreparedStatement(
				'SELECT * FROM bcd_cards WHERE bcd_bls_list_id = ? AND bcd_delete_time IS NULL AND bcd_archived_time IS NULL ORDER BY bcd_position, bcd_card_id');
			$c->execute(array((int)$list['bls_list_id']));
			foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $card) {
				$card_id = (int)$card['bcd_card_id'];
				$labels = array();
				$lq = DbConnector::GetPreparedStatement('SELECT bcl_blb_label_id FROM bcl_card_labels WHERE bcl_bcd_card_id = ?');
				$lq->execute(array($card_id));
				foreach ($lq->fetchAll(PDO::FETCH_COLUMN) as $label_id) {
					if (isset($label_names[(int)$label_id])) {
						$labels[] = (int)$label_id;
					}
				}
				$checklists = array();
				foreach (BoardsPayload::checklists($card_id) as $checklist) {
					$items = array();
					foreach ($checklist['items'] as $item) {
						$items[] = $item['text'];
					}
					$checklists[] = array('title' => $checklist['title'], 'items' => $items);
				}
				$cards[] = array(
					'title'       => (string)$card['bcd_title'],
					'description' => (string)$card['bcd_description'],
					'label_ids'   => $labels,
					'checklists'  => $checklists,
					'is_template' => Boards::flag($card['bcd_is_template']),
				);
			}
			$def['lists'][] = array('name' => (string)$list['bls_name'], 'cards' => $cards);
		}
		return $def;
	}

	/**
	 * Lay a definition down on a board that has no lists yet.
	 *
	 * A definition names a card's labels either by name (a starter) or by the id
	 * of the source board's label (a snapshot, which also carries the labels in
	 * the same order so the two line up).
	 */
	public static function build(Board $board, array $def, int $user_id): void {
		Boards::transaction(function () use ($board, $def, $user_id) {
			$board_id = (int)$board->key;

			$label_ids = array();   // by name, and by position in the definition
			foreach (($def['labels'] ?? array()) as $i => $label) {
				$row = new BoardLabel(NULL);
				$row->set('blb_bbd_board_id', $board_id);
				$row->set('blb_name', ($label['name'] ?? '') === '' ? null : mb_substr($label['name'], 0, 100));
				$row->set('blb_color', in_array($label['color'] ?? '', BoardLabel::COLORS, true) ? $label['color'] : 'gray');
				$row->set('blb_position', Boards::computePosition('label', $board_id));
				$row->save();
				if (($label['name'] ?? '') !== '') {
					$label_ids[$label['name']] = (int)$row->key;
				}
				$label_ids['#' . $i] = (int)$row->key;
			}
			// A snapshot refers to source label ids; map them through their order.
			$source_order = isset($def['source_label_ids']) ? array_values($def['source_label_ids']) : array();

			foreach (($def['lists'] ?? array()) as $list_def) {
				$list = new BoardList(NULL);
				$list->set('bls_bbd_board_id', $board_id);
				$list->set('bls_name', Boards::cleanTitle($list_def['name'] ?? 'List', 255, 'A list name'));
				$list->set('bls_position', Boards::computePosition('list', $board_id));
				$list->save();

				foreach (($list_def['cards'] ?? array()) as $card_def) {
					$card = BoardsCards::create($board, $list, $user_id, $card_def['title'], array(
						'description' => $card_def['description'] ?? '',
						'is_template' => !empty($card_def['is_template']),
						'notify'      => false,
						'log'         => false,
					));
					$ids = array();
					foreach (($card_def['labels'] ?? array()) as $name) {
						if (isset($label_ids[$name])) {
							$ids[] = $label_ids[$name];
						}
					}
					foreach (($card_def['label_ids'] ?? array()) as $source_id) {
						$at = array_search($source_id, $source_order, true);
						if ($at !== false && isset($label_ids['#' . $at])) {
							$ids[] = $label_ids['#' . $at];
						}
					}
					foreach (array_unique($ids) as $label_id) {
						BoardsCards::addLabel($card, $board, $user_id, $label_id, false);
					}
					foreach (($card_def['checklists'] ?? array()) as $checklist_def) {
						$checklist = BoardsCards::addChecklist($card, $board, $user_id, $checklist_def['title'] ?? 'Checklist');
						foreach (($checklist_def['items'] ?? array()) as $text) {
							BoardsCards::addItem($checklist, $card, $board, $user_id, $text, array('notify' => false));
						}
					}
				}
			}
		});
	}

	/** Copy a board's shape onto a new board that has no lists yet. */
	public static function copyInto(Board $target, Board $source, int $user_id): void {
		$def = self::snapshot($source);
		$def['source_label_ids'] = array_map(function ($label) { return $label['id']; }, BoardsPayload::labels((int)$source->key));
		self::build($target, $def, $user_id);
	}
}
