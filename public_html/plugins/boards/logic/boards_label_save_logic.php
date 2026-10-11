<?php
/**
 * API action: boards/boards_label_save — a board's labels.
 *
 * POST /api/v1/action/boards/boards_label_save
 *
 *   create   board_id [name, color]
 *   update   label_id [name, color]
 *   delete   label_id
 *   move     label_id [prev_id, next_id]
 *
 * Board admins only. A colour is one of ten fixed tokens (green, yellow, orange,
 * red, purple, blue, sky, lime, pink, gray); a label may have no name.
 *
 * @version 1.0.0
 */

function boards_label_save_logic(array $input): LogicResult {
	return BoardsAction::run(function (int $user_id) use ($input) {
		$op = BoardsAction::oneOf($input['op'] ?? '', array('create', 'update', 'delete', 'move'), 'operation');

		if ($op === 'create') {
			$board = Boards::boardFor($input['board_id'] ?? 0, $user_id, 'admin');
			BoardsManager::saveLabel($board, null,
				array_key_exists('name', $input) ? (string)$input['name'] : null,
				isset($input['color']) ? (string)$input['color'] : 'gray');
		} else {
			$label_id = (int)($input['label_id'] ?? 0);
			if ($label_id <= 0 || !BoardLabel::check_if_exists($label_id)) {
				throw new BoardsRefusal('Label not found.');
			}
			$label = new BoardLabel($label_id, TRUE);
			$board = Boards::boardFor($label->get('blb_bbd_board_id'), $user_id, 'admin');
			switch ($op) {
				case 'update':
					BoardsManager::saveLabel($board, $label,
						array_key_exists('name', $input) ? (string)$input['name'] : null,
						isset($input['color']) ? (string)$input['color'] : null);
					break;
				case 'delete':
					BoardsManager::deleteLabel($board, $label);
					break;
				case 'move':
					BoardsManager::moveLabel($board, $label, BoardsAction::idOrNull($input, 'prev_id'), BoardsAction::idOrNull($input, 'next_id'));
					break;
			}
		}
		return array('labels' => BoardsPayload::labels((int)$board->key));
	});
}

function boards_label_save_logic_descriptor(): array {
	return array(
		'description'      => 'Add, rename, recolour, reorder or delete a board\'s labels.',
		'requires_session' => true,
		'requires_setting' => 'boards_active',
		'mutates'          => true,
		'input'            => array(
			'op'       => array('type' => 'string', 'required' => true, 'label' => 'Operation', 'enum' => array('create', 'update', 'delete', 'move')),
			'board_id' => array('type' => 'int', 'required' => false, 'label' => 'Board'),
			'label_id' => array('type' => 'int', 'required' => false, 'label' => 'Label'),
			'name'     => array('type' => 'string', 'required' => false, 'max_length' => 100, 'label' => 'Name'),
			'color'    => array('type' => 'string', 'required' => false, 'max_length' => 16, 'label' => 'Colour'),
			'prev_id'  => array('type' => 'int', 'required' => false, 'label' => 'Label above'),
			'next_id'  => array('type' => 'int', 'required' => false, 'label' => 'Label below'),
		),
		'auth'             => array('capability' => 'write'),
	);
}
