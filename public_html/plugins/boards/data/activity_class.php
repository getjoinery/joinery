<?php
/**
 * BoardActivity — one thing that happened on a board.
 *
 * The same rows are two things: a card's history list, and the change feed the
 * poll action reads (boards_poll returns every row above the browser's cursor).
 * A row is never edited. bac_data carries what the verb needs to read back
 * ("from list", "to list", the old title, the label name) as it was at the time.
 *
 * @version 1.0.0
 */

class BoardActivity extends SystemBase {

	public static $prefix = 'bac';
	public static $tablename = 'bac_activity';
	public static $pkey_column = 'bac_activity_id';

	/** Every verb a row may carry. */
	const VERBS = array(
		'card.created', 'card.moved', 'card.renamed', 'card.described', 'card.due_set', 'card.done',
		'card.archived', 'card.restored', 'card.updated', 'member.added', 'member.removed', 'label.added', 'label.removed',
		'checklist.item_done', 'comment.added', 'attachment.added',
		'list.created', 'list.moved', 'list.archived',
	);

	public static $field_specifications = array(
		'bac_activity_id'  => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'bac_bbd_board_id' => array('type' => 'int8', 'is_nullable' => false, 'required' => true, 'index' => true,
			'foreign_key' => array('table' => 'bbd_boards', 'column' => 'bbd_board_id', 'on_delete' => 'CASCADE')),
		'bac_bcd_card_id'  => array('type' => 'int8', 'is_nullable' => true, 'index' => true,
			'foreign_key' => array('table' => 'bcd_cards', 'column' => 'bcd_card_id', 'on_delete' => 'CASCADE')),
		'bac_usr_user_id'  => array('type' => 'int8', 'is_nullable' => true),
		'bac_verb'         => array('type' => 'varchar(32)', 'is_nullable' => false, 'required' => true,
			'allowed_values' => self::VERBS),
		'bac_data'         => array('type' => 'jsonb', 'is_nullable' => true),
		'bac_create_time'  => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
	);

	protected static $foreign_key_actions = array(
		'bac_bbd_board_id' => array('action' => 'cascade'),
		'bac_bcd_card_id'  => array('action' => 'cascade'),
		'bac_usr_user_id'  => array('action' => 'set_value', 'value' => User::USER_DELETED),
	);

	public function boardId(): int {
		return (int)$this->get('bac_bbd_board_id');
	}

	function authenticate_read($data) {
		Boards::assertRoleForBoardId($this->boardId(), $data, 'viewer');
	}

	// Activity is written by the application as things happen, never by a caller.
	function authenticate_write($data) {
		throw new SystemAuthenticationError('Board activity is recorded by the system.');
	}
}

class MultiBoardActivity extends SystemMultiBase {
	protected static $model_class = 'BoardActivity';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		// after_id => rows newer than a poll cursor.
		if (isset($this->options['after_id'])) {
			$filters['bac_activity_id'] = '> ' . (int)$this->options['after_id'];
		}
		return $this->_get_resultsv2('bac_activity', $filters, $this->order_by, $only_count, $debug);
	}
}
