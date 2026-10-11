<?php
/**
 * BoardChecklistItem — one line of a checklist.
 *
 * Unlike a plain checkbox it can carry an assignee and a due date, and be turned
 * into a card of its own (the "real subtask" Boards answers the usual complaint
 * about checklists with). An assigned item with a due date is a calendar item
 * for its assignee and gets the same due reminder a card does.
 *
 * @version 1.0.0
 */

class BoardChecklistItem extends SystemBase {

	public static $prefix = 'bci';
	public static $tablename = 'bci_checklist_items';
	public static $pkey_column = 'bci_checklist_item_id';

	public static $field_specifications = array(
		'bci_checklist_item_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'bci_bck_checklist_id'  => array('type' => 'int8', 'is_nullable' => false, 'required' => true, 'index' => true,
			'foreign_key' => array('table' => 'bck_checklists', 'column' => 'bck_checklist_id', 'on_delete' => 'CASCADE')),
		'bci_text'              => array('type' => 'varchar(500)', 'is_nullable' => false, 'required' => true),
		'bci_is_done'           => array('type' => 'bool', 'is_nullable' => false, 'default' => false),
		'bci_usr_user_id'       => array('type' => 'int8', 'is_nullable' => true, 'index' => true),
		'bci_due_time'          => array('type' => 'timestamp(6)', 'is_nullable' => true),
		'bci_reminder_sent_for_due_time' => array('type' => 'timestamp(6)', 'is_nullable' => true),
		'bci_position'          => array('type' => 'numeric(20,6)', 'is_nullable' => false, 'default' => 0),
	);

	protected static $foreign_key_actions = array(
		'bci_bck_checklist_id' => array('action' => 'cascade'),
		// An assignee who leaves the site leaves the item behind, unassigned.
		'bci_usr_user_id'      => array('action' => 'null'),
	);

	public function boardId(): int {
		return Boards::boardIdForChecklist((int)$this->get('bci_bck_checklist_id'));
	}

	function authenticate_read($data) {
		Boards::assertRoleForBoardId($this->boardId(), $data, 'viewer');
	}

	function authenticate_write($data) {
		Boards::assertRoleForBoardId($this->boardId(), $data, 'editor');
	}
}

class MultiBoardChecklistItem extends SystemMultiBase {
	protected static $model_class = 'BoardChecklistItem';

	protected function getMultiResults($only_count = false, $debug = false) {
		return $this->_get_resultsv2('bci_checklist_items', array(), $this->order_by, $only_count, $debug);
	}
}
