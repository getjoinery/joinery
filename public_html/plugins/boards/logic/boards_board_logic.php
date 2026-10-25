<?php
/**
 * API action: boards/boards_board — read a board, or the list of boards.
 *
 * POST /api/v1/action/boards/boards_board
 *
 * With no board_id: the boards the person can open, split into theirs, open to
 * all members, templates and archived, plus the starter boards a new board can be
 * made from. With a board_id: everything the board page draws — the board, the
 * person's role, its lists and cards, labels and people, and the cursor the poll
 * continues from. A board the person cannot open is "Board not found".
 *
 * Params:
 *   board_id          the board to open (omit for the board list)
 *   include_archived  also return archived lists and cards (for the archive panel)
 *   with_members      board admins only: the membership rows, for the settings screen
 *
 * @version 1.0.0
 */

function boards_board_logic(array $input): LogicResult {
	return BoardsAction::run(function (int $user_id) use ($input) {
		$board_id = BoardsAction::idOrNull($input, 'board_id');

		if (!$board_id) {
			return array(
				'user_id'      => $user_id,
				'index'        => BoardsPayload::boardIndex($user_id),
				'starters'     => array_values(BoardsTemplates::starters()),
				'backgrounds'  => BoardsManager::BACKGROUNDS,
				'settings'     => Boards::clientSettings(),
			);
		}

		$board = Boards::boardFor($board_id, $user_id, 'viewer');
		$role = Boards::roleFor($board, $user_id);
		$page = BoardsPayload::boardPage($board, $user_id, $role, !empty($input['include_archived']));
		$page['user_id'] = $user_id;
		$page['backgrounds'] = BoardsManager::BACKGROUNDS;
		$page['label_colors'] = BoardLabel::COLORS;
		if (!empty($input['with_members']) && $role === Boards::ROLE_ADMIN) {
			$page['member_rows'] = BoardsPayload::memberRows($board);
		}
		return $page;
	});
}

function boards_board_logic_descriptor(): array {
	return array(
		'description'      => 'Read one board (its lists, cards, labels, people and role) or, with no board_id, the list of boards the person can open.',
		'requires_session' => true,
		'requires_setting' => 'boards_active',
		'mutates'          => false,
		'input'            => array(
			'board_id'         => array('type' => 'int', 'required' => false, 'label' => 'Board'),
			'include_archived' => array('type' => 'bool', 'required' => false, 'label' => 'Include archived'),
			'with_members'     => array('type' => 'bool', 'required' => false, 'label' => 'Include membership rows'),
		),
		'auth'             => array('min_user_permission' => 0, 'capability' => 'read'),
		'ai_agent'         => 'auto',
	);
}
