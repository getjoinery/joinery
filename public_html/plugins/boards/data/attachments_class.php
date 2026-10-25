<?php
/**
 * BoardAttachment — a file or a link on a card.
 *
 * A file attachment holds a private File gated on the board (see
 * BoardsAttachmentGate), so anyone with a role on the board can open it and
 * nobody else can. A link attachment (an imported link, a pasted URL) holds a
 * URL and no File. Removing the attachment removes its File.
 *
 * @version 1.0.0
 */

class BoardAttachment extends SystemBase {

	public static $prefix = 'bat';
	public static $tablename = 'bat_attachments';
	public static $access_class = 'content';
	public static $pkey_column = 'bat_attachment_id';

	public static $field_specifications = array(
		'bat_attachment_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'bat_bcd_card_id'   => array('type' => 'int8', 'is_nullable' => false, 'required' => true, 'index' => true,
			'foreign_key' => array('table' => 'bcd_cards', 'column' => 'bcd_card_id', 'on_delete' => 'CASCADE')),
		// A file, or…
		'bat_fil_file_id'   => array('type' => 'int8', 'is_nullable' => true, 'index' => true),
		// …a link. A link carries its own display name.
		'bat_name'          => array('type' => 'varchar(255)', 'is_nullable' => true),
		'bat_url'           => array('type' => 'varchar(2000)', 'is_nullable' => true),
		'bat_usr_user_id'   => array('type' => 'int8', 'is_nullable' => true),
		'bat_create_time'   => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
	);

	protected static $foreign_key_actions = array(
		'bat_bcd_card_id' => array('action' => 'permanent_delete'),
		// A File removed elsewhere (an admin's file cleanup) takes its attachment.
		'bat_fil_file_id' => array('action' => 'cascade'),
		'bat_usr_user_id' => array('action' => 'set_value', 'value' => User::USER_DELETED),
	);

	function prepare() {
		$result = parent::prepare();
		$has_file = (bool)$this->get('bat_fil_file_id');
		$has_url = trim((string)$this->get('bat_url')) !== '';
		if ($has_file === $has_url) {
			$result['messages'][] = 'An attachment is either a file or a link.';
			$result['success'] = false;
		}
		if ($has_url && !preg_match('#^https?://#i', trim((string)$this->get('bat_url')))) {
			$result['messages'][] = 'A link attachment must be an http or https address.';
			$result['success'] = false;
		}
		return $result;
	}

	public function boardId(): int {
		return Boards::boardIdForCard((int)$this->get('bat_bcd_card_id'));
	}

	public function is_link(): bool {
		return !$this->get('bat_fil_file_id');
	}

	/** Remove the attachment and, if it holds one, its File. */
	public function permanent_delete($debug = false) {
		$file_id = (int)$this->get('bat_fil_file_id');
		$result = parent::permanent_delete($debug);
		if ($result && !$debug && $file_id) {
			Boards::deleteFile($file_id);
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

class MultiBoardAttachment extends SystemMultiBase {
	protected static $model_class = 'BoardAttachment';

	protected function getMultiResults($only_count = false, $debug = false) {
		return $this->_get_resultsv2('bat_attachments', array(), $this->order_by, $only_count, $debug);
	}
}
