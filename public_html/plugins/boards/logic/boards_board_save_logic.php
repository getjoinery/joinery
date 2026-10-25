<?php
/**
 * API action: boards/boards_board_save — make a board, change it, archive it.
 *
 * POST /api/v1/action/boards/boards_board_save
 *
 * One action, chosen by `op`:
 *   create        name [description, visibility, background, starter | template_id]
 *                 — any signed-in member; the creator owns the board and is its admin.
 *                 `starter` is the key of a shipped starter board; `template_id` is a
 *                 template board to copy the shape of (lists, labels, cards).
 *   update        board_id [name, description, visibility, background]   — board admin
 *   archive       board_id   — board admin; an archived board is read-only
 *   restore       board_id   — board admin
 *   delete        board_id   — board admin; hides the board and its cards (soft delete)
 *   set_template  board_id, is_template   — board admin
 *   watch         board_id   — tell me about every new card on this board
 *   unwatch       board_id
 *
 * @version 1.0.1
 * @changelog 1.0.1 - a board admin's delete runs under rule_checked_write()
 */

function boards_board_save_logic(array $input): LogicResult {
	return BoardsAction::run(function (int $user_id) use ($input) {
		$op = BoardsAction::oneOf($input['op'] ?? '', array(
			'create', 'update', 'archive', 'restore', 'delete', 'set_template', 'watch', 'unwatch',
		), 'operation');

		if ($op === 'create') {
			$board = BoardsManager::createBoard($user_id, (string)($input['name'] ?? ''), array(
				'description' => $input['description'] ?? null,
				'visibility'  => $input['visibility'] ?? Board::VISIBILITY_INVITE_ONLY,
				'background'  => $input['background'] ?? null,
				'starter'     => $input['starter'] ?? null,
				'template_id' => BoardsAction::idOrNull($input, 'template_id'),
			));
			return array('board' => BoardsPayload::board($board), 'role' => Boards::ROLE_ADMIN);
		}

		$minimum = in_array($op, array('watch', 'unwatch'), true) ? 'viewer' : 'admin';
		$board = Boards::boardFor($input['board_id'] ?? 0, $user_id, $minimum, $op === 'restore');

		switch ($op) {
			case 'update':
				$changes = array();
				foreach (array('name', 'description', 'visibility', 'background') as $field) {
					if (array_key_exists($field, $input)) {
						$changes[$field] = $input[$field];
					}
				}
				BoardsManager::updateBoard($board, $user_id, $changes);
				break;
			case 'archive':
				BoardsManager::archiveBoard($board);
				break;
			case 'restore':
				BoardsManager::restoreBoard($board);
				break;
			case 'delete':
				// A board admin (boardFor() above); the delete level is staff's.
				SystemBase::rule_checked_write(function () use ($board) { $board->soft_delete(); });
				return array('deleted' => true, 'board_id' => (int)$board->key);
			case 'set_template':
				BoardsManager::setTemplate($board, !empty($input['is_template']));
				break;
			case 'watch':
				BoardsManager::setWatching($board, $user_id, true);
				break;
			case 'unwatch':
				BoardsManager::setWatching($board, $user_id, false);
				break;
		}
		return array(
			'board'          => BoardsPayload::board($board),
			'role'           => Boards::roleFor($board, $user_id),
			'watching_board' => BoardsPayload::isWatchingBoard((int)$board->key, $user_id),
		);
	});
}

function boards_board_save_logic_descriptor(): array {
	return array(
		'description'      => 'Create a board (blank, from a starter, or from a template board), change it, archive or restore it, delete it, or watch it for new cards.',
		'requires_session' => true,
		'requires_setting' => 'boards_active',
		'mutates'          => true,
		'input'            => array(
			'op'          => array('type' => 'string', 'required' => true, 'label' => 'Operation',
				'enum' => array('create', 'update', 'archive', 'restore', 'delete', 'set_template', 'watch', 'unwatch')),
			'board_id'    => array('type' => 'int', 'required' => false, 'label' => 'Board'),
			'name'        => array('type' => 'string', 'required' => false, 'max_length' => 255, 'label' => 'Name'),
			'description' => array('type' => 'text', 'required' => false, 'label' => 'Description'),
			'visibility'  => array('type' => 'string', 'required' => false, 'enum' => array('invite_only', 'all_members'), 'label' => 'Visibility'),
			'background'  => array('type' => 'string', 'required' => false, 'max_length' => 64, 'label' => 'Background'),
			'starter'     => array('type' => 'string', 'required' => false, 'max_length' => 64, 'label' => 'Starter board'),
			'template_id' => array('type' => 'int', 'required' => false, 'label' => 'Template board'),
			'is_template' => array('type' => 'bool', 'required' => false, 'label' => 'Is a template'),
		),
		'auth'             => array('min_user_permission' => 0, 'capability' => 'write'),
		'ai_agent'         => 'confirm',
	);
}
