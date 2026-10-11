<?php
/**
 * API action: boards/boards_list_save — add, rename, archive and restore lists.
 *
 * POST /api/v1/action/boards/boards_list_save
 *
 *   create    board_id, name [prev_id, next_id]   — placed between two lists (default: the end)
 *   rename    list_id, name
 *   archive   list_id   — hides the list; its cards stay in it
 *   restore   list_id   — back at the end of the board
 *
 * Needs an editor. Returns the list and the board's lists in order.
 *
 * @version 1.0.0
 */

function boards_list_save_logic(array $input): LogicResult {
	return BoardsAction::run(function (int $user_id) use ($input) {
		$op = BoardsAction::oneOf($input['op'] ?? '', array('create', 'rename', 'archive', 'restore'), 'operation');

		if ($op === 'create') {
			$board = Boards::boardFor($input['board_id'] ?? 0, $user_id, 'editor');
			$list = BoardsManager::createList($board, $user_id, (string)($input['name'] ?? ''), array(
				'prev_id' => BoardsAction::idOrNull($input, 'prev_id'),
				'next_id' => BoardsAction::idOrNull($input, 'next_id'),
			));
		} else {
			$list_id = (int)($input['list_id'] ?? 0);
			if ($list_id <= 0 || !BoardList::check_if_exists($list_id)) {
				throw new BoardsRefusal('List not found.');
			}
			$list = new BoardList($list_id, TRUE);
			$board = Boards::boardFor($list->get('bls_bbd_board_id'), $user_id, 'editor');
			switch ($op) {
				case 'rename':
					BoardsManager::renameList($board, $list, $user_id, (string)($input['name'] ?? ''));
					break;
				case 'archive':
					BoardsManager::archiveList($board, $list, $user_id);
					break;
				case 'restore':
					BoardsManager::restoreList($board, $list, $user_id);
					break;
			}
		}

		$lists = array();
		$q = DbConnector::GetPreparedStatement(
			'SELECT * FROM bls_lists WHERE bls_bbd_board_id = ? AND bls_archived_time IS NULL ORDER BY bls_position, bls_list_id');
		$q->execute(array((int)$board->key));
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$lists[] = BoardsPayload::listRow($row);
		}
		$row = DbConnector::GetPreparedStatement('SELECT * FROM bls_lists WHERE bls_list_id = ?');
		$row->execute(array((int)$list->key));
		return array('list' => BoardsPayload::listRow($row->fetch(PDO::FETCH_ASSOC)), 'lists' => $lists);
	});
}

function boards_list_save_logic_descriptor(): array {
	return array(
		'description'      => 'Add a list to a board, rename it, archive it or restore it.',
		'requires_session' => true,
		'requires_setting' => 'boards_active',
		'mutates'          => true,
		'input'            => array(
			'op'       => array('type' => 'string', 'required' => true, 'label' => 'Operation', 'enum' => array('create', 'rename', 'archive', 'restore')),
			'board_id' => array('type' => 'int', 'required' => false, 'label' => 'Board'),
			'list_id'  => array('type' => 'int', 'required' => false, 'label' => 'List'),
			'name'     => array('type' => 'string', 'required' => false, 'max_length' => 255, 'label' => 'Name'),
			'prev_id'  => array('type' => 'int', 'required' => false, 'label' => 'List above'),
			'next_id'  => array('type' => 'int', 'required' => false, 'label' => 'List below'),
		),
		'auth'             => array('capability' => 'write'),
		'ai_agent'         => 'confirm',
	);
}
