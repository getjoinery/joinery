<?php
/**
 * BoardCardLabel — a label put on a card.
 *
 * @version 1.0.0
 */

class BoardCardLabel extends SystemBase {

	public static $prefix = 'bcl';
	public static $tablename = 'bcl_card_labels';
	public static $access_class = 'content';
	public static $pkey_column = 'bcl_card_label_id';

	public static $field_specifications = array(
		'bcl_card_label_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'bcl_bcd_card_id'   => array('type' => 'int8', 'is_nullable' => false, 'required' => true, 'index' => true,
			'foreign_key' => array('table' => 'bcd_cards', 'column' => 'bcd_card_id', 'on_delete' => 'CASCADE')),
		'bcl_blb_label_id'  => array('type' => 'int8', 'is_nullable' => false, 'required' => true, 'index' => true,
			'unique_with' => array('bcl_bcd_card_id'),
			'foreign_key' => array('table' => 'blb_labels', 'column' => 'blb_label_id', 'on_delete' => 'CASCADE')),
	);

	protected static $foreign_key_actions = array(
		'bcl_bcd_card_id'  => array('action' => 'cascade'),
		'bcl_blb_label_id' => array('action' => 'cascade'),
	);

	public function boardId(): int {
		return Boards::boardIdForCard((int)$this->get('bcl_bcd_card_id'));
	}

	function authenticate_read($data) {
		Boards::assertRoleForBoardId($this->boardId(), $data, 'viewer');
	}

	function authenticate_write($data) {
		Boards::assertRoleForBoardId($this->boardId(), $data, 'editor');
	}
}

class MultiBoardCardLabel extends SystemMultiBase {
	protected static $model_class = 'BoardCardLabel';

	protected function getMultiResults($only_count = false, $debug = false) {
		return $this->_get_resultsv2('bcl_card_labels', array(), $this->order_by, $only_count, $debug);
	}
}
