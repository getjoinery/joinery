<?php
/**
 * API action: boards/boards_list_move — drag a list to a new place.
 *
 * POST /api/v1/action/boards/boards_list_move
 *
 * The browser says which lists the dropped one now sits between (prev_id and
 * next_id; either may be absent at an end). The server reads their real positions
 * and takes the midpoint, so a stale drag still lands beside the right lists and
 * one drag changes one row.
 *
 * @version 1.0.0
 */

function boards_list_move_logic(array $input): LogicResult {
	return BoardsAction::run(function (int $user_id) use ($input) {
		$list_id = (int)($input['list_id'] ?? 0);
		if ($list_id <= 0 || !BoardList::check_if_exists($list_id)) {
			throw new BoardsRefusal('List not found.');
		}
		$list = new BoardList($list_id, TRUE);
		$board = Boards::boardFor($list->get('bls_bbd_board_id'), $user_id, 'editor');
		BoardsManager::moveList($board, $list, $user_id,
			BoardsAction::idOrNull($input, 'prev_id'), BoardsAction::idOrNull($input, 'next_id'));
		return array('list' => array('id' => (int)$list->key, 'position' => (float)$list->get('bls_position')));
	});
}

function boards_list_move_logic_descriptor(): array {
	return array(
		'description'      => 'Move a list to between two other lists.',
		'requires_session' => true,
		'requires_setting' => 'boards_active',
		'mutates'          => true,
		'input'            => array(
			'list_id' => array('type' => 'int', 'required' => true, 'label' => 'List'),
			'prev_id' => array('type' => 'int', 'required' => false, 'label' => 'List above'),
			'next_id' => array('type' => 'int', 'required' => false, 'label' => 'List below'),
		),
		'auth'             => array('min_user_permission' => 0, 'capability' => 'write'),
	);
}
