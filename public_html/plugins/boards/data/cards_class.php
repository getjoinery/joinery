<?php
/**
 * BoardCard — one card: a task, a thing to do, a thing being tracked.
 *
 * bcd_bbd_board_id is held on the card as well as on its list so a board loads
 * in one query and an access check never has to walk card → list → board. The
 * two are kept consistent here: a card can only sit in a list of its own board
 * (prepare() refuses otherwise), and moving a card to another board updates both
 * columns together.
 *
 * Archived cards are ordinary rows with bcd_archived_time set. Archive is the
 * everyday undo; delete is for admins.
 *
 * @version 1.0.0
 */

class BoardCard extends SystemBase {

	public static $prefix = 'bcd';
	public static $tablename = 'bcd_cards';
	public static $access_class = 'content';
	public static $pkey_column = 'bcd_card_id';

	const SOURCE_WEB = 'web';
	const SOURCE_EMAIL = 'email';
	const SOURCE_IMPORT = 'import';
	const SOURCE_AI = 'ai';

	// ---- AI surface ----------------------------------------------------
	// Deliberately NOT offered as a model ($ai_readable / $ai_writable_fields are
	// absent). The AI read path queries a table directly and never calls
	// authenticate_read(), and an admin's reads are not fenced by owner, so a
	// site admin (5–9) — who has no special access to a board — could read every
	// card. Writes would skip the activity log the poll depends on. Joinery AI
	// works with cards through the boards_* actions, which declare ai_agent and
	// check the board role on every call. See docs/overview.md § Joinery AI.

	public static $field_specifications = array(
		'bcd_card_id'          => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'bcd_bls_list_id'      => array('type' => 'int8', 'is_nullable' => false, 'required' => true, 'index' => true,
			'foreign_key' => array('table' => 'bls_lists', 'column' => 'bls_list_id', 'on_delete' => 'CASCADE')),
		'bcd_bbd_board_id'     => array('type' => 'int8', 'is_nullable' => false, 'required' => true, 'index' => true,
			'foreign_key' => array('table' => 'bbd_boards', 'column' => 'bbd_board_id', 'on_delete' => 'CASCADE')),
		'bcd_title'            => array('type' => 'varchar(255)', 'is_nullable' => false, 'required' => true),
		'bcd_description'      => array('type' => 'text', 'is_nullable' => true),
		'bcd_due_time'         => array('type' => 'timestamp(6)', 'is_nullable' => true),
		'bcd_is_done'          => array('type' => 'bool', 'is_nullable' => false, 'default' => false),
		// The due time a reminder was last sent for. A reminder is due when this
		// differs from bcd_due_time, so one goes out once and again only if the
		// date moves.
		'bcd_reminder_sent_for_due_time' => array('type' => 'timestamp(6)', 'is_nullable' => true),
		'bcd_position'         => array('type' => 'numeric(20,6)', 'is_nullable' => false, 'default' => 0),
		'bcd_cover_fil_file_id' => array('type' => 'int8', 'is_nullable' => true),
		'bcd_is_template'      => array('type' => 'bool', 'is_nullable' => false, 'default' => false),
		'bcd_source'           => array('type' => 'varchar(16)', 'is_nullable' => false, 'default' => 'web',
			'allowed_values' => array(self::SOURCE_WEB, self::SOURCE_EMAIL, self::SOURCE_IMPORT, self::SOURCE_AI)),
		'bcd_usr_user_id_creator' => array('type' => 'int8', 'is_nullable' => true),
		// The stored mailbox message a card was made from (email-to-board, Make a card).
		// No database constraint across plugins; see $foreign_key_actions.
		'bcd_iem_message_id'   => array('type' => 'int8', 'is_nullable' => true),
		'bcd_archived_time'    => array('type' => 'timestamp(6)', 'is_nullable' => true),
		'bcd_create_time'      => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
		'bcd_update_time'      => array('type' => 'timestamp(6)', 'is_nullable' => true),
		'bcd_delete_time'      => array('type' => 'timestamp(6)', 'is_nullable' => true),
	);

	public static $index_specifications = array(
		array('columns' => array('bcd_due_time'), 'where' => 'bcd_delete_time IS NULL AND bcd_archived_time IS NULL AND bcd_is_done = false'),
		// One live card per mailbox message: a message delivered twice cannot make two.
		array('columns' => array('bcd_iem_message_id'), 'unique' => true,
			'where' => 'bcd_delete_time IS NULL AND bcd_iem_message_id IS NOT NULL'),
	);

	protected static $foreign_key_actions = array(
		'bcd_bls_list_id'         => array('action' => 'permanent_delete'),
		'bcd_bbd_board_id'        => array('action' => 'permanent_delete'),
		// The cover is an image File; if it is removed the card just has no cover.
		'bcd_cover_fil_file_id'   => array('action' => 'null', 'source_table' => 'fil_files'),
		'bcd_usr_user_id_creator' => array('action' => 'set_value', 'value' => User::USER_DELETED),
		// If the email is deleted the card stays intact; its back link reads "The
		// original email is no longer available". The explicit source_table resolves
		// whether or not the mailbox plugin is active, and without it the `iem` prefix
		// would register the column as `prevent` and refuse to purge the email.
		'bcd_iem_message_id'      => array('action' => 'null', 'source_table' => 'iem_inbound_email_messages'),
	);

	function prepare() {
		$result = parent::prepare();

		$title = $this->get('bcd_title');
		if ($title !== null && trim((string)$title) === '') {
			$result['messages'][] = 'A card needs a title.';
			$result['success'] = false;
		}

		// A card sits in a list of its own board, whoever is setting the column
		// (the editor, an import, or an AI write that names a list).
		$list_id = (int)$this->get('bcd_bls_list_id');
		$board_id = (int)$this->get('bcd_bbd_board_id');
		if ($list_id && $board_id) {
			$q = DbConnector::GetPreparedStatement('SELECT bls_bbd_board_id FROM bls_lists WHERE bls_list_id = ?');
			$q->execute(array($list_id));
			$list_board = $q->fetchColumn();
			if ($list_board === false || (int)$list_board !== $board_id) {
				$result['messages'][] = 'That list is not on this card\'s board.';
				$result['success'] = false;
			}
		}
		return $result;
	}

	public function boardId(): int {
		return (int)$this->get('bcd_bbd_board_id');
	}

	public function is_archived(): bool {
		return (bool)$this->get('bcd_archived_time');
	}

	/**
	 * Remove the card, its attachments' Files and its cover. The leaf rows under
	 * it (members, labels, comments, checklists, activity) are cleared by the
	 * deletion rules; Files are not rows the rules know to unlink, so they go
	 * first.
	 */
	public function permanent_delete($debug = false) {
		$file_ids = array();
		if (!$debug && $this->key) {
			$q = DbConnector::GetPreparedStatement(
				'SELECT bat_fil_file_id FROM bat_attachments WHERE bat_bcd_card_id = ? AND bat_fil_file_id IS NOT NULL');
			$q->execute(array($this->key));
			foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $file_id) {
				$file_ids[] = (int)$file_id;
			}
		}
		$result = parent::permanent_delete($debug);
		if ($result && !$debug) {
			foreach ($file_ids as $file_id) {
				Boards::deleteFile($file_id);
			}
		}
		return $result;
	}

	function authenticate_read($data) {
		Boards::assertRoleForBoardId($this->boardId(), $data, 'viewer');
	}

	function authenticate_write($data) {
		Boards::assertRoleForBoardId($this->boardId(), $data, 'editor');
	}
}

class MultiBoardCard extends SystemMultiBase {
	protected static $model_class = 'BoardCard';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['archived'])) {
			$filters['bcd_archived_time'] = $this->options['archived'] ? 'IS NOT NULL' : 'IS NULL';
		}

		// card_ids => a list of ids (integers only; coerced).
		if (isset($this->options['card_ids'])) {
			$ids = array_map('intval', (array)$this->options['card_ids']);
			$filters['bcd_card_id'] = $ids ? 'IN (' . implode(',', $ids) . ')' : 'IN (NULL)';
		}

		// member_user_id => cards that person is on.
		if (isset($this->options['member_user_id'])) {
			$filters['bcd_card_id'] = (isset($filters['bcd_card_id']) ? $filters['bcd_card_id'] . ' AND bcd_card_id ' : '')
				. 'IN (SELECT bcm_bcd_card_id FROM bcm_card_members WHERE bcm_usr_user_id = '
				. (int)$this->options['member_user_id'] . ')';
		}

		// due_before / due_after => UTC 'Y-m-d H:i:s' bounds on the due time.
		if (isset($this->options['due_before'])) {
			$filters['bcd_due_time'] = '< ' . DbConnector::get_instance()->get_db_link()->quote($this->options['due_before']);
		}

		return $this->_get_resultsv2('bcd_cards', $filters, $this->order_by, $only_count, $debug);
	}
}
