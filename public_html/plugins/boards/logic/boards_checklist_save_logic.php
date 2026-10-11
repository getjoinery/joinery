<?php
/**
 * API action: boards/boards_checklist_save — checklists and their items.
 *
 * POST /api/v1/action/boards/boards_checklist_save
 *
 * Needs an editor. A card can have several checklists; an item can have an
 * assignee and a due date, and can be turned into a card of its own.
 *
 *   create         card_id [title]
 *   rename         card_id, checklist_id, title
 *   delete         card_id, checklist_id
 *   move           card_id, checklist_id [prev_id, next_id]
 *   item_add       card_id, checklist_id, text [user_id, due, prev_id, next_id]
 *   item_update    card_id, item_id [text, is_done, user_id (0 clears), due]
 *   item_delete    card_id, item_id
 *   item_move      card_id, item_id [checklist_id (another on the same card), prev_id, next_id]
 *   item_to_card   card_id, item_id   — becomes a card in the same list; the item is removed
 *
 * Returns the card's checklists and its summary.
 *
 * @version 1.0.0
 */

function boards_checklist_save_logic(array $input): LogicResult {
	return BoardsAction::run(function (int $user_id) use ($input) {
		$op = BoardsAction::oneOf($input['op'] ?? '', array(
			'create', 'rename', 'delete', 'move', 'item_add', 'item_update', 'item_delete', 'item_move', 'item_to_card',
		), 'operation');
		list($card, $board) = Boards::cardFor($input['card_id'] ?? 0, $user_id, 'editor');
		$new_card = null;

		switch ($op) {
			case 'create':
				BoardsCards::addChecklist($card, $board, $user_id, trim((string)($input['title'] ?? '')) === '' ? 'Checklist' : (string)$input['title']);
				break;

			case 'rename':
				$checklist = BoardsCards::checklistOn($card, $input['checklist_id'] ?? 0);
				$checklist->set('bck_title', Boards::cleanTitle($input['title'] ?? '', 255, 'A checklist title'));
				$checklist->save();
				Boards::log((int)$board->key, (int)$card->key, $user_id, 'card.updated', array('quiet' => true, 'what' => 'checklist'));
				break;

			case 'delete':
				BoardsCards::deleteChecklist(BoardsCards::checklistOn($card, $input['checklist_id'] ?? 0), $card, $board, $user_id);
				break;

			case 'move':
				$checklist = BoardsCards::checklistOn($card, $input['checklist_id'] ?? 0);
				$checklist->set('bck_position', Boards::computePosition('checklist', (int)$card->key,
					BoardsAction::idOrNull($input, 'prev_id'), BoardsAction::idOrNull($input, 'next_id'), (int)$checklist->key));
				$checklist->save();
				Boards::log((int)$board->key, (int)$card->key, $user_id, 'card.updated', array('quiet' => true, 'what' => 'checklist'));
				break;

			case 'item_add':
				$checklist = BoardsCards::checklistOn($card, $input['checklist_id'] ?? 0);
				BoardsCards::addItem($checklist, $card, $board, $user_id, (string)($input['text'] ?? ''), array(
					'user_id' => BoardsAction::idOrNull($input, 'user_id'),
					'due'     => array_key_exists('due', $input) ? Boards::utcFromInput($input['due']) : null,
					'prev_id' => BoardsAction::idOrNull($input, 'prev_id'),
					'next_id' => BoardsAction::idOrNull($input, 'next_id'),
				));
				break;

			case 'item_update':
				list($item, $checklist) = BoardsCards::itemOn($card, $input['item_id'] ?? 0);
				$changes = array();
				foreach (array('text', 'is_done', 'user_id') as $field) {
					if (array_key_exists($field, $input)) {
						$changes[$field] = $input[$field];
					}
				}
				if (array_key_exists('due', $input)) {
					$changes['due'] = Boards::utcFromInput($input['due']);
				}
				BoardsCards::updateItem($item, $checklist, $card, $board, $user_id, $changes);
				break;

			case 'item_delete':
				list($item) = BoardsCards::itemOn($card, $input['item_id'] ?? 0);
				BoardsCards::deleteItem($item, $card, $board, $user_id);
				break;

			case 'item_move':
				list($item, $checklist) = BoardsCards::itemOn($card, $input['item_id'] ?? 0);
				$target = isset($input['checklist_id'])
					? BoardsCards::checklistOn($card, $input['checklist_id'])
					: $checklist;
				Boards::transaction(function () use ($item, $target, $input, $card, $board, $user_id) {
					$item->set('bci_bck_checklist_id', (int)$target->key);
					$item->set('bci_position', Boards::computePosition('item', (int)$target->key,
						BoardsAction::idOrNull($input, 'prev_id'), BoardsAction::idOrNull($input, 'next_id'), (int)$item->key));
					$item->save();
					Boards::log((int)$board->key, (int)$card->key, $user_id, 'card.updated', array('quiet' => true, 'what' => 'checklist item'));
				});
				break;

			case 'item_to_card':
				list($item) = BoardsCards::itemOn($card, $input['item_id'] ?? 0);
				$new_card = BoardsCards::itemToCard($item, $card, $board, $user_id);
				break;
		}

		$out = array(
			'checklists' => BoardsPayload::checklists((int)$card->key),
			'card'       => BoardsPayload::cardSummary((int)$card->key),
		);
		if ($new_card) {
			$out['new_card'] = BoardsPayload::cardSummary((int)$new_card->key);
		}
		return $out;
	});
}

function boards_checklist_save_logic_descriptor(): array {
	return array(
		'description'      => 'Add, rename, reorder and delete a card\'s checklists and their items; assign and date items; turn an item into a card.',
		'requires_session' => true,
		'requires_setting' => 'boards_active',
		'mutates'          => true,
		'input'            => array(
			'op'           => array('type' => 'string', 'required' => true, 'label' => 'Operation', 'enum' => array(
				'create', 'rename', 'delete', 'move', 'item_add', 'item_update', 'item_delete', 'item_move', 'item_to_card')),
			'card_id'      => array('type' => 'int', 'required' => true, 'label' => 'Card'),
			'checklist_id' => array('type' => 'int', 'required' => false, 'label' => 'Checklist'),
			'item_id'      => array('type' => 'int', 'required' => false, 'label' => 'Item'),
			'title'        => array('type' => 'string', 'required' => false, 'max_length' => 255, 'label' => 'Title'),
			'text'         => array('type' => 'string', 'required' => false, 'max_length' => 500, 'label' => 'Text'),
			'is_done'      => array('type' => 'bool', 'required' => false, 'label' => 'Done'),
			'user_id'      => array('type' => 'int', 'required' => false, 'min' => 0, 'label' => 'Assignee (0 clears)'),
			'due'          => array('type' => 'string', 'required' => false, 'max_length' => 40, 'label' => 'Due (ISO 8601; empty clears)'),
			'prev_id'      => array('type' => 'int', 'required' => false, 'label' => 'Above'),
			'next_id'      => array('type' => 'int', 'required' => false, 'label' => 'Below'),
		),
		'auth'             => array('capability' => 'write'),
		'ai_agent'         => 'confirm',
	);
}
