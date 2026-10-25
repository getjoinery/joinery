<?php
/**
 * BoardCardMember — a person assigned to a card.
 *
 * Card members are told about the card (boards.assigned) and are watchers of it
 * by default. They must be able to see the board: assigning someone with no role
 * on it is refused by the logic layer, not here.
 *
 * @version 1.0.0
 */

class BoardCardMember extends SystemBase {

	public static $prefix = 'bcm';
	public static $tablename = 'bcm_card_members';
	public static $access_class = 'personal';
	public static $pkey_column = 'bcm_card_member_id';

	public static $field_specifications = array(
		'bcm_card_member_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'bcm_bcd_card_id'    => array('type' => 'int8', 'is_nullable' => false, 'required' => true, 'index' => true,
			'foreign_key' => array('table' => 'bcd_cards', 'column' => 'bcd_card_id', 'on_delete' => 'CASCADE')),
		'bcm_usr_user_id'    => array('type' => 'int8', 'is_nullable' => false, 'required' => true, 'index' => true,
			'unique_with' => array('bcm_bcd_card_id')),
		'bcm_create_time'    => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
	);

	protected static $foreign_key_actions = array(
		'bcm_bcd_card_id' => array('action' => 'cascade'),
		'bcm_usr_user_id' => array('action' => 'cascade'),
	);

	public function boardId(): int {
		return Boards::boardIdForCard((int)$this->get('bcm_bcd_card_id'));
	}

	function authenticate_read($data) {
		Boards::assertRoleForBoardId($this->boardId(), $data, 'viewer');
	}

	function authenticate_write($data) {
		Boards::assertRoleForBoardId($this->boardId(), $data, 'editor');
	}
}

class MultiBoardCardMember extends SystemMultiBase {
	protected static $model_class = 'BoardCardMember';

	protected function getMultiResults($only_count = false, $debug = false) {
		return $this->_get_resultsv2('bcm_card_members', array(), $this->order_by, $only_count, $debug);
	}
}
