<?php
/**
 * API action: boards/boards_poll — what changed on a board since last time.
 *
 * POST /api/v1/action/boards/boards_poll
 *
 * One action carries the board's liveness: the activity rows above the browser's
 * cursor, the current state of every card they touched (and which are gone), the
 * lists and labels as they stand, and the people when they changed. The browser
 * calls it every few seconds while the board is visible (assets/js/joinery-poll.js)
 * and not at all while the tab is hidden.
 *
 * Params:
 *   board_id            the open board
 *   after_activity_id   the cursor — everything above this id is new to the caller
 *   people_stamp        the stamp of the people list the browser holds
 *
 * @version 1.0.0
 */

function boards_poll_logic(array $input): LogicResult {
	return BoardsAction::run(function (int $user_id) use ($input) {
		$board = Boards::boardFor($input['board_id'] ?? 0, $user_id, 'viewer');
		$role = Boards::roleFor($board, $user_id);
		return BoardsPayload::poll($board, $user_id, $role,
			(int)($input['after_activity_id'] ?? 0), (string)($input['people_stamp'] ?? ''));
	});
}

function boards_poll_logic_descriptor(): array {
	return array(
		'description'      => 'Everything that changed on a board since a cursor: activity, the cards it touched, lists, labels and people.',
		'requires_session' => true,
		'requires_setting' => 'boards_active',
		'mutates'          => false,
		'input'            => array(
			'board_id'          => array('type' => 'int', 'required' => true, 'label' => 'Board'),
			'after_activity_id' => array('type' => 'int', 'required' => false, 'default' => 0, 'label' => 'Cursor'),
			'people_stamp'      => array('type' => 'string', 'required' => false, 'max_length' => 64, 'label' => 'People stamp'),
		),
		'auth'             => array('min_user_permission' => 0, 'capability' => 'read'),
	);
}
