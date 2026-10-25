<?php
/**
 * API action: boards/boards_card — read one card, the whole card back.
 *
 * POST /api/v1/action/boards/boards_card
 *
 * The card's fields, labels, members, checklists with their items, comments
 * (newest first), attachments and history, plus the person's role and whether they
 * are watching it. With move_targets: also the boards the person can edit and
 * their lists, for the Move and Copy pickers.
 *
 * Params:
 *   card_id        the card
 *   move_targets   also return boards (and lists) the person could move the card to
 *
 * @version 1.0.0
 */

function boards_card_logic(array $input): LogicResult {
	return BoardsAction::run(function (int $user_id) use ($input) {
		list($card, $board) = Boards::cardFor($input['card_id'] ?? 0, $user_id, 'viewer', true);
		$role = Boards::roleFor($board, $user_id);
		$out = BoardsPayload::cardDetail($card, $board, $user_id, $role);
		$out['lists'] = array();
		$q = DbConnector::GetPreparedStatement(
			'SELECT * FROM bls_lists WHERE bls_bbd_board_id = ? AND bls_archived_time IS NULL ORDER BY bls_position, bls_list_id');
		$q->execute(array((int)$board->key));
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$out['lists'][] = BoardsPayload::listRow($row);
		}
		if (!empty($input['move_targets']) && Boards::roleAtLeast($role, 'editor')) {
			$out['move_targets'] = BoardsPayload::moveTargets($user_id);
		}
		return $out;
	});
}

function boards_card_logic_descriptor(): array {
	return array(
		'description'      => 'Read one card: fields, labels, members, checklists, comments, attachments and history.',
		'requires_session' => true,
		'requires_setting' => 'boards_active',
		'mutates'          => false,
		'input'            => array(
			'card_id'      => array('type' => 'int', 'required' => true, 'label' => 'Card'),
			'move_targets' => array('type' => 'bool', 'required' => false, 'label' => 'Include move targets'),
		),
		'auth'             => array('min_user_permission' => 0, 'capability' => 'read'),
		'ai_agent'         => 'auto',
	);
}
