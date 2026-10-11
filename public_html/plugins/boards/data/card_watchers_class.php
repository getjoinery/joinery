<?php
/**
 * BoardCardWatcher — an explicit choice to watch (or stop watching) a card.
 *
 * A card's watchers are its members, anyone who commented, and anyone who
 * pressed Watch — minus anyone who pressed Unwatch. A row here records the last
 * two: bcw_muted = false is "watching", true is "explicitly not".
 *
 * @version 1.0.0
 */

class BoardCardWatcher extends SystemBase {

	public static $prefix = 'bcw';
	public static $tablename = 'bcw_card_watchers';
	public static $pkey_column = 'bcw_card_watcher_id';

	public static $field_specifications = array(
		'bcw_card_watcher_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'bcw_bcd_card_id'     => array('type' => 'int8', 'is_nullable' => false, 'required' => true, 'index' => true,
			'foreign_key' => array('table' => 'bcd_cards', 'column' => 'bcd_card_id', 'on_delete' => 'CASCADE')),
		'bcw_usr_user_id'     => array('type' => 'int8', 'is_nullable' => false, 'required' => true, 'index' => true,
			'unique_with' => array('bcw_bcd_card_id')),
		'bcw_muted'           => array('type' => 'bool', 'is_nullable' => false, 'default' => false),
		'bcw_create_time'     => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
	);

	protected static $foreign_key_actions = array(
		'bcw_bcd_card_id' => array('action' => 'cascade'),
		'bcw_usr_user_id' => array('action' => 'cascade'),
	);

	public function boardId(): int {
		return Boards::boardIdForCard((int)$this->get('bcw_bcd_card_id'));
	}

	function authenticate_read($data) {
		Boards::assertRoleForBoardId($this->boardId(), $data, 'viewer');
	}

	function authenticate_write($data) {
		Boards::assertRoleForBoardId($this->boardId(), $data, 'viewer');
		if ((int)$this->get('bcw_usr_user_id') !== (int)$data['current_user_id']) {
			throw new SystemAuthenticationError('A card watch can only be changed by the person who set it.');
		}
	}
}

class MultiBoardCardWatcher extends SystemMultiBase {
	protected static $model_class = 'BoardCardWatcher';

	protected function getMultiResults($only_count = false, $debug = false) {
		return $this->_get_resultsv2('bcw_card_watchers', array(), $this->order_by, $only_count, $debug);
	}
}
