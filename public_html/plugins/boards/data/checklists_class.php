<?php
/**
 * BoardChecklist — a titled list of items on a card. A card can have several.
 *
 * @version 1.0.0
 */

class BoardChecklist extends SystemBase {

	public static $prefix = 'bck';
	public static $tablename = 'bck_checklists';
	public static $access_class = 'content';
	public static $pkey_column = 'bck_checklist_id';

	public static $field_specifications = array(
		'bck_checklist_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'bck_bcd_card_id'  => array('type' => 'int8', 'is_nullable' => false, 'required' => true, 'index' => true,
			'foreign_key' => array('table' => 'bcd_cards', 'column' => 'bcd_card_id', 'on_delete' => 'CASCADE')),
		'bck_title'        => array('type' => 'varchar(255)', 'is_nullable' => false, 'default' => 'Checklist'),
		'bck_position'     => array('type' => 'numeric(20,6)', 'is_nullable' => false, 'default' => 0),
	);

	protected static $foreign_key_actions = array(
		'bck_bcd_card_id' => array('action' => 'permanent_delete'),
	);

	public function boardId(): int {
		return Boards::boardIdForCard((int)$this->get('bck_bcd_card_id'));
	}

	function authenticate_read($data) {
		Boards::assertRoleForBoardId($this->boardId(), $data, 'viewer');
	}

	function authenticate_write($data) {
		Boards::assertRoleForBoardId($this->boardId(), $data, 'editor');
	}
}

class MultiBoardChecklist extends SystemMultiBase {
	protected static $model_class = 'BoardChecklist';

	protected function getMultiResults($only_count = false, $debug = false) {
		return $this->_get_resultsv2('bck_checklists', array(), $this->order_by, $only_count, $debug);
	}
}
