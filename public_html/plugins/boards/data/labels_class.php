<?php
/**
 * BoardLabel — a coloured, optionally named tag a board's cards can carry.
 *
 * The colour is one of ten fixed tokens, not free text, so the stylesheet owns
 * every colour on the board and a label can never be unreadable against it.
 *
 * @version 1.0.0
 */

class BoardLabel extends SystemBase {

	public static $prefix = 'blb';
	public static $tablename = 'blb_labels';
	public static $pkey_column = 'blb_label_id';

	/** The ten colour tokens, in the order the picker offers them. */
	const COLORS = array('green', 'yellow', 'orange', 'red', 'purple', 'blue', 'sky', 'lime', 'pink', 'gray');

	public static $field_specifications = array(
		'blb_label_id'      => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'blb_bbd_board_id'  => array('type' => 'int8', 'is_nullable' => false, 'required' => true, 'index' => true,
			'foreign_key' => array('table' => 'bbd_boards', 'column' => 'bbd_board_id', 'on_delete' => 'CASCADE')),
		'blb_name'          => array('type' => 'varchar(100)', 'is_nullable' => true),
		'blb_color'         => array('type' => 'varchar(16)', 'is_nullable' => false, 'default' => 'gray',
			'allowed_values' => self::COLORS),
		'blb_position'      => array('type' => 'numeric(20,6)', 'is_nullable' => false, 'default' => 0),
	);

	protected static $foreign_key_actions = array(
		'blb_bbd_board_id' => array('action' => 'permanent_delete'),
	);

	public function boardId(): int {
		return (int)$this->get('blb_bbd_board_id');
	}

	function authenticate_read($data) {
		Boards::assertRoleForBoardId($this->boardId(), $data, 'viewer');
	}

	function authenticate_write($data) {
		Boards::assertRoleForBoardId($this->boardId(), $data, 'admin');
	}
}

class MultiBoardLabel extends SystemMultiBase {
	protected static $model_class = 'BoardLabel';

	protected function getMultiResults($only_count = false, $debug = false) {
		return $this->_get_resultsv2('blb_labels', array(), $this->order_by, $only_count, $debug);
	}
}
