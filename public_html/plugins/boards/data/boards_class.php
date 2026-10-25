<?php
/**
 * Board — one kanban board: its lists, cards, labels and the people on it.
 *
 * A board is the unit of access. Everything under it (lists, cards, comments,
 * checklists, attachments) is readable by exactly the people Boards::roleFor()
 * gives a role on the board, and by nobody else; see docs/overview.md § Access.
 *
 * Deleting a board is two different things. soft_delete() hides it — and every
 * card in it, with the same timestamp, so undelete() brings back exactly the
 * cards the delete took and leaves alone any that were deleted earlier on
 * their own. permanent_delete() removes every row under the board and the
 * Files its attachments hold.
 *
 * @version 1.0.1
 * @changelog 1.0.1 - the owner column is bbd_usr_user_id_owner; board admins write the board
 */

class Board extends SystemBase {

	public static $prefix = 'bbd';
	public static $tablename = 'bbd_boards';
	public static $access_class = 'content';
	public static $pkey_column = 'bbd_board_id';
	/** The person who made the board owns it. */
	public static $owner_column = 'bbd_usr_user_id_owner';

	const VISIBILITY_INVITE_ONLY = 'invite_only';
	const VISIBILITY_ALL_MEMBERS = 'all_members';

	// AI auto-discovery is deliberately not offered on a board itself: a board's
	// name and description are not what a recipe needs, and the cards are.

	public static $field_specifications = array(
		'bbd_board_id'            => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'bbd_name'                => array('type' => 'varchar(255)', 'is_nullable' => false, 'required' => true),
		'bbd_description'         => array('type' => 'text', 'is_nullable' => true),
		'bbd_visibility'          => array('type' => 'varchar(16)', 'is_nullable' => false, 'default' => 'invite_only',
			'allowed_values' => array(self::VISIBILITY_INVITE_ONLY, self::VISIBILITY_ALL_MEMBERS)),
		// A colour token ('blue', 'green', …) or 'file:{id}' for an uploaded image.
		'bbd_background'          => array('type' => 'varchar(64)', 'is_nullable' => true),
		'bbd_is_template'         => array('type' => 'bool', 'is_nullable' => false, 'default' => false),
		// Email-to-board (the mailbox plugin). The mail domain this board's addresses
		// use; NULL means the default. No database constraint across plugins: the rule
		// below clears it if the domain row is ever removed.
		'bbd_ied_inbound_email_domain_id' => array('type' => 'int8', 'is_nullable' => true),
		// Where a card made by email lands: this list (NULL: the first list), at the
		// top or the bottom of it.
		'bbd_email_bls_list_id'   => array('type' => 'int8', 'is_nullable' => true,
			'foreign_key' => array('table' => 'bls_lists', 'column' => 'bls_list_id', 'on_delete' => 'SET NULL')),
		'bbd_email_position'      => array('type' => 'varchar(8)', 'is_nullable' => false, 'default' => 'bottom',
			'allowed_values' => array('top', 'bottom')),
		'bbd_usr_user_id_owner'   => array('type' => 'int8', 'is_nullable' => true, 'index' => true),
		'bbd_archived_time'       => array('type' => 'timestamp(6)', 'is_nullable' => true),
		'bbd_create_time'         => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
		'bbd_update_time'         => array('type' => 'timestamp(6)', 'is_nullable' => true),
		'bbd_delete_time'         => array('type' => 'timestamp(6)', 'is_nullable' => true),
	);

	protected static $foreign_key_actions = array(
		'bbd_usr_user_id_owner' => array('action' => 'set_value', 'value' => User::USER_DELETED),
		// An explicit source_table resolves whether or not the mailbox plugin is active,
		// and without the declaration the column's `ied` prefix would register it as
		// `prevent` and refuse to remove a mail domain a board once used.
		'bbd_ied_inbound_email_domain_id' => array('action' => 'null', 'source_table' => 'ied_inbound_email_domains'),
		'bbd_email_bls_list_id'           => array('action' => 'null', 'source_table' => 'bls_lists'),
	);

	function prepare() {
		$result = parent::prepare();
		$name = trim((string)$this->get('bbd_name'));
		if ($name === '' && $this->get('bbd_name') !== null) {
			$result['messages'][] = 'A board needs a name.';
			$result['success'] = false;
		}
		return $result;
	}

	public function is_archived(): bool {
		return (bool)$this->get('bbd_archived_time');
	}

	/**
	 * Hide the board and every card in it, with one timestamp, so undelete()
	 * can tell the cards this delete took from cards deleted on their own.
	 */
	function soft_delete() {
		return Boards::transaction(function () {
			// The base class goes first: it refuses a GET before anything is
			// written. now() is the transaction's start time, so the board and
			// its cards are stamped with the identical value.
			parent::soft_delete();
			$q = DbConnector::GetPreparedStatement('UPDATE bcd_cards SET bcd_delete_time = now()
				WHERE bcd_bbd_board_id = ? AND bcd_delete_time IS NULL');
			$q->execute(array($this->key));
			return true;
		});
	}

	/** Bring the board back, with exactly the cards its delete took. */
	function undelete() {
		$this->guard_raw_write('soft_delete');
		$db = DbConnector::get_instance()->get_db_link();
		$own_transaction = !$db->inTransaction();
		if ($own_transaction) {
			$db->beginTransaction();
		}
		try {
			$q = $db->prepare('UPDATE bcd_cards SET bcd_delete_time = NULL
				WHERE bcd_bbd_board_id = ?
				AND bcd_delete_time = (SELECT bbd_delete_time FROM bbd_boards WHERE bbd_board_id = ?)');
			$q->execute(array($this->key, $this->key));
			$q = $db->prepare('UPDATE bbd_boards SET bbd_delete_time = NULL, bbd_update_time = now()
				WHERE bbd_board_id = ?');
			$q->execute(array($this->key));
			if ($own_transaction) {
				$db->commit();
			}
		} catch (Throwable $e) {
			if ($own_transaction && $db->inTransaction()) {
				$db->rollBack();
			}
			throw $e;
		}
		$this->load();
		return true;
	}

	/**
	 * Writers: the board's owner (a new board is its creator's), the model's
	 * level, or anyone the board makes an admin. Running a board is a role on
	 * it, as everything under the board is (BoardCard::authenticate_write()).
	 */
	/** Readers: the owner, the model's level, or anyone with a role on the board. */
	function authenticate_read($data) {
		try {
			parent::authenticate_read($data);
		} catch (SystemAuthenticationError $e) {
			if (!$this->key) {
				throw $e;
			}
			Boards::assertRoleForBoardId((int)$this->key, $data, 'viewer');
		}
	}

	function authenticate_write($data) {
		try {
			parent::authenticate_write($data);
		} catch (SystemAuthenticationError $e) {
			if (!$this->key) {
				throw $e;
			}
			Boards::assertRoleForBoardId((int)$this->key, $data, 'admin');
		}
	}

	/**
	 * Remove the board and everything under it. The cards go through their own
	 * permanent_delete(); the rest are leaves the deletion rules (and the database's
	 * own foreign keys, as a backstop) clear. Files are not rows those know to
	 * unlink, so the attachment Files and the background image are collected first
	 * and removed here, whichever way the rows go.
	 */
	public function permanent_delete($debug = false) {
		$file_ids = array();
		if (!$debug && $this->key) {
			$q = DbConnector::GetPreparedStatement(
				'SELECT bat.bat_fil_file_id FROM bat_attachments bat
				 JOIN bcd_cards bcd ON bcd.bcd_card_id = bat.bat_bcd_card_id
				 WHERE bcd.bcd_bbd_board_id = ? AND bat.bat_fil_file_id IS NOT NULL');
			$q->execute(array($this->key));
			$file_ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
		}
		$background_file = Boards::backgroundFileId((string)$this->get('bbd_background'));
		if ($background_file) {
			$file_ids[] = $background_file;
		}
		$board_id = (int)$this->key;
		$result = parent::permanent_delete($debug);
		if ($result && !$debug) {
			// Every member's email address for the board goes with it.
			if ($board_id > 0 && class_exists('InboundPersonalAddress')) {
				InboundPersonalAddress::revokeAllFor(BoardsEmail::HANDLER, BoardsEmail::REF_TYPE, $board_id);
			}
			foreach (array_unique($file_ids) as $file_id) {
				Boards::deleteFile($file_id);
			}
		}
		return $result;
	}
}

class MultiBoard extends SystemMultiBase {
	protected static $model_class = 'Board';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		// archived => TRUE selects archived boards, FALSE live ones.
		if (isset($this->options['archived'])) {
			$filters['bbd_archived_time'] = $this->options['archived'] ? 'IS NOT NULL' : 'IS NULL';
		}

		// visible_to => user id: the boards that person has any role on —
		// directly, through a group, or because the board is open to all
		// members. The role itself is still Boards::roleFor()'s to decide; this
		// only narrows the query to the boards worth asking about.
		if (isset($this->options['visible_to'])) {
			$uid = (int)$this->options['visible_to'];
			$filters['bbd_board_id'] = 'IN (
				SELECT bbd_board_id FROM bbd_boards WHERE bbd_visibility = \'all_members\'
				UNION SELECT bbm_bbd_board_id FROM bbm_board_members WHERE bbm_usr_user_id = ' . $uid . '
				UNION SELECT bbm_bbd_board_id FROM bbm_board_members
					WHERE bbm_grp_group_id IN (SELECT grm.grm_grp_group_id FROM grm_group_members grm
						JOIN grp_groups grp ON grp.grp_group_id = grm.grm_grp_group_id
						WHERE grm.grm_foreign_key_id = ' . $uid . ' AND grp.grp_category = \'user\'
						AND grp.grp_delete_time IS NULL)
				UNION SELECT bbd_board_id FROM bbd_boards WHERE bbd_usr_user_id_owner = ' . $uid . ')';
		}

		return $this->_get_resultsv2('bbd_boards', $filters, $this->order_by, $only_count, $debug);
	}
}
