<?php
/**
 * BoardComment — a Markdown comment on a card.
 *
 * Comments are soft-deleted (a deleted comment disappears from the card but its
 * activity row stays truthful). The body can come from outside the site — a
 * reply by email — so it is marked untrusted for the AI surface.
 *
 * @version 1.0.0
 */

class BoardComment extends SystemBase {

	public static $prefix = 'bco';
	public static $tablename = 'bco_comments';
	public static $pkey_column = 'bco_comment_id';

	const SOURCE_WEB = 'web';
	const SOURCE_EMAIL = 'email';
	const SOURCE_AI = 'ai';

	public static $field_specifications = array(
		'bco_comment_id'  => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'bco_bcd_card_id' => array('type' => 'int8', 'is_nullable' => false, 'required' => true, 'index' => true,
			'foreign_key' => array('table' => 'bcd_cards', 'column' => 'bcd_card_id', 'on_delete' => 'CASCADE')),
		'bco_usr_user_id' => array('type' => 'int8', 'is_nullable' => true, 'index' => true),
		'bco_body'        => array('type' => 'text', 'is_nullable' => false, 'required' => true),
		'bco_source'      => array('type' => 'varchar(16)', 'is_nullable' => false, 'default' => 'web',
			'allowed_values' => array(self::SOURCE_WEB, self::SOURCE_EMAIL, self::SOURCE_AI)),
		'bco_edited_time' => array('type' => 'timestamp(6)', 'is_nullable' => true),
		'bco_create_time' => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
		'bco_delete_time' => array('type' => 'timestamp(6)', 'is_nullable' => true),
	);

	protected static $foreign_key_actions = array(
		'bco_bcd_card_id' => array('action' => 'cascade'),
		'bco_usr_user_id' => array('action' => 'set_value', 'value' => User::USER_DELETED),
	);

	public function boardId(): int {
		return Boards::boardIdForCard((int)$this->get('bco_bcd_card_id'));
	}

	function authenticate_read($data) {
		Boards::assertRoleForBoardId($this->boardId(), $data, 'viewer');
	}

	function authenticate_write($data) {
		Boards::assertRoleForBoardId($this->boardId(), $data, 'editor');
	}
}

class MultiBoardComment extends SystemMultiBase {
	protected static $model_class = 'BoardComment';

	protected function getMultiResults($only_count = false, $debug = false) {
		return $this->_get_resultsv2('bco_comments', array(), $this->order_by, $only_count, $debug);
	}
}
