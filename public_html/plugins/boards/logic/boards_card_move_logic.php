<?php
/**
 * API action: boards/boards_card_move — drag a card to a new place.
 *
 * POST /api/v1/action/boards/boards_card_move
 *
 * The browser says which list the card now sits in and which cards it sits
 * between (prev_id and next_id; either may be absent at an end). The server reads
 * their real positions and takes the midpoint, so a stale drag still lands beside
 * the right cards and one drag changes one row. The list may be on another board:
 * the card keeps its checklists, comments and attachments, its labels follow by
 * name and colour, and its members stay only if they can open the other board.
 *
 * Needs an editor on the card's board, and on the other board for a cross-board move.
 *
 * @version 1.0.0
 */

function boards_card_move_logic(array $input): LogicResult {
	return BoardsAction::run(function (int $user_id) use ($input) {
		list($card, $board) = Boards::cardFor($input['card_id'] ?? 0, $user_id, 'editor');

		$list_id = (int)($input['list_id'] ?? 0);
		if ($list_id <= 0 || !BoardList::check_if_exists($list_id)) {
			throw new BoardsRefusal('List not found.');
		}
		$target = new BoardList($list_id, TRUE);
		$target_board = (int)$target->get('bls_bbd_board_id') === (int)$board->key
			? $board
			: Boards::boardFor($target->get('bls_bbd_board_id'), $user_id, 'editor');
		$target = BoardsCards::listOn($target_board, $list_id);

		$prev_id = BoardsAction::idOrNull($input, 'prev_id');
		$next_id = BoardsAction::idOrNull($input, 'next_id');
		if ((int)$target_board->key === (int)$board->key) {
			BoardsCards::move($card, $board, $target, $user_id, $prev_id, $next_id);
		} else {
			BoardsCards::moveToBoard($card, $board, $target_board, $target, $user_id, $prev_id, $next_id);
		}
		return array(
			'card'     => BoardsPayload::cardSummary((int)$card->key),
			'board_id' => (int)$target_board->key,
		);
	});
}

function boards_card_move_logic_descriptor(): array {
	return array(
		'description'      => 'Move a card to a list (on this board or another), between two cards.',
		'requires_session' => true,
		'requires_setting' => 'boards_active',
		'mutates'          => true,
		'input'            => array(
			'card_id' => array('type' => 'int', 'required' => true, 'label' => 'Card'),
			'list_id' => array('type' => 'int', 'required' => true, 'label' => 'List'),
			'prev_id' => array('type' => 'int', 'required' => false, 'label' => 'Card above'),
			'next_id' => array('type' => 'int', 'required' => false, 'label' => 'Card below'),
		),
		'auth'             => array('min_user_permission' => 0, 'capability' => 'write'),
		'ai_agent'         => 'confirm',
	);
}
