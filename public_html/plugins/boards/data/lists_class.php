<?php
/**
 * BoardList — one column on a board. Archive hides it (and its cards stay put
 * inside it); there is no soft delete, because archive is the everyday undo.
 *
 * @version 1.0.0
 */

class BoardList extends SystemBase {

	public static $prefix = 'bls';
	public static $tablename = 'bls_lists';
	public static $pkey_column = 'bls_list_id';

	public static $field_specifications = array(
		'bls_list_id'       => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'bls_bbd_board_id'  => array('type' => 'int8', 'is_nullable' => false, 'required' => true, 'index' => true,
			'foreign_key' => array('table' => 'bbd_boards', 'column' => 'bbd_board_id', 'on_delete' => 'CASCADE')),
		'bls_name'          => array('type' => 'varchar(255)', 'is_nullable' => false, 'required' => true),
		// numeric(20,6), not a float: see Boards::MIN_POSITION_GAP.
		'bls_position'      => array('type' => 'numeric(20,6)', 'is_nullable' => false, 'default' => 0),
		'bls_archived_time' => array('type' => 'timestamp(6)', 'is_nullable' => true),
		'bls_create_time'   => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
	);

	protected static $foreign_key_actions = array(
		'bls_bbd_board_id' => array('action' => 'permanent_delete'),
	);

	public function boardId(): int {
		return (int)$this->get('bls_bbd_board_id');
	}

	function authenticate_read($data) {
		Boards::assertRoleForBoardId($this->boardId(), $data, 'viewer');
	}

	function authenticate_write($data) {
		Boards::assertRoleForBoardId($this->boardId(), $data, 'editor');
	}
}

class MultiBoardList extends SystemMultiBase {
	protected static $model_class = 'BoardList';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['archived'])) {
			$filters['bls_archived_time'] = $this->options['archived'] ? 'IS NOT NULL' : 'IS NULL';
		}
		return $this->_get_resultsv2('bls_lists', $filters, $this->order_by, $only_count, $debug);
	}
}
