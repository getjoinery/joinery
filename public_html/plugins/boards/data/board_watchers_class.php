<?php
/**
 * BoardWatcher — "tell me about every new card on this board".
 *
 * Separate from membership on purpose: it works the same for someone who is on
 * the board only through a group. Rows are removed when the person loses access
 * (Boards::pruneWatchers()).
 *
 * @version 1.0.0
 */

class BoardWatcher extends SystemBase {

	public static $prefix = 'bbw';
	public static $tablename = 'bbw_board_watchers';
	public static $pkey_column = 'bbw_board_watcher_id';

	public static $field_specifications = array(
		'bbw_board_watcher_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'bbw_bbd_board_id'     => array('type' => 'int8', 'is_nullable' => false, 'required' => true, 'index' => true,
			'foreign_key' => array('table' => 'bbd_boards', 'column' => 'bbd_board_id', 'on_delete' => 'CASCADE')),
		'bbw_usr_user_id'      => array('type' => 'int8', 'is_nullable' => false, 'required' => true, 'index' => true,
			'unique_with' => array('bbw_bbd_board_id')),
		'bbw_create_time'      => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
	);

	protected static $foreign_key_actions = array(
		'bbw_bbd_board_id' => array('action' => 'cascade'),
		'bbw_usr_user_id'  => array('action' => 'cascade'),
	);

	public function boardId(): int {
		return (int)$this->get('bbw_bbd_board_id');
	}

	function authenticate_read($data) {
		Boards::assertRoleForBoardId($this->boardId(), $data, 'viewer');
	}

	function authenticate_write($data) {
		// A watch is the watcher's own to set; the role check is only that they
		// can still see the board.
		Boards::assertRoleForBoardId($this->boardId(), $data, 'viewer');
		if ((int)$this->get('bbw_usr_user_id') !== (int)$data['current_user_id']) {
			throw new SystemAuthenticationError('A board watch can only be changed by the person who set it.');
		}
	}
}

class MultiBoardWatcher extends SystemMultiBase {
	protected static $model_class = 'BoardWatcher';

	protected function getMultiResults($only_count = false, $debug = false) {
		return $this->_get_resultsv2('bbw_board_watchers', array(), $this->order_by, $only_count, $debug);
	}
}
