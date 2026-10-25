<?php
/**
 * BoardMember — one person, or one whole group, on a board, with a role.
 *
 * Exactly one of bbm_usr_user_id and bbm_grp_group_id is set. A group row gives
 * every current member of the group the role — membership is read live, so
 * someone who joins the group sees the board at once and someone who leaves
 * loses it. Only user-category groups can be added.
 *
 * @version 1.0.0
 */

class BoardMember extends SystemBase {

	public static $prefix = 'bbm';
	public static $tablename = 'bbm_board_members';
	public static $access_class = 'personal';
	public static $pkey_column = 'bbm_board_member_id';

	const ROLE_ADMIN = 'admin';
	const ROLE_EDITOR = 'editor';
	const ROLE_VIEWER = 'viewer';

	public static $field_specifications = array(
		'bbm_board_member_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'bbm_bbd_board_id'    => array('type' => 'int8', 'is_nullable' => false, 'required' => true, 'index' => true,
			'foreign_key' => array('table' => 'bbd_boards', 'column' => 'bbd_board_id', 'on_delete' => 'CASCADE')),
		'bbm_usr_user_id'     => array('type' => 'int8', 'is_nullable' => true, 'index' => true,
			'unique_with' => array('bbm_bbd_board_id')),
		'bbm_grp_group_id'    => array('type' => 'int8', 'is_nullable' => true, 'index' => true,
			'unique_with' => array('bbm_bbd_board_id')),
		'bbm_role'            => array('type' => 'varchar(16)', 'is_nullable' => false, 'default' => 'editor',
			'allowed_values' => array(self::ROLE_ADMIN, self::ROLE_EDITOR, self::ROLE_VIEWER)),
		'bbm_create_time'     => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
	);

	protected static $foreign_key_actions = array(
		'bbm_bbd_board_id' => array('action' => 'cascade'),
		'bbm_usr_user_id'  => array('action' => 'cascade'),
		'bbm_grp_group_id' => array('action' => 'cascade'),
	);

	function prepare() {
		$result = parent::prepare();
		$has_user = (bool)$this->get('bbm_usr_user_id');
		$has_group = (bool)$this->get('bbm_grp_group_id');
		if ($has_user === $has_group) {
			$result['messages'][] = 'A board member is either one person or one group.';
			$result['success'] = false;
		}
		return $result;
	}

	public function boardId(): int {
		return (int)$this->get('bbm_bbd_board_id');
	}

	function authenticate_read($data) {
		Boards::assertRoleForBoardId($this->boardId(), $data, 'viewer');
	}

	function authenticate_write($data) {
		Boards::assertRoleForBoardId($this->boardId(), $data, 'admin');
	}
}

class MultiBoardMember extends SystemMultiBase {
	protected static $model_class = 'BoardMember';

	protected function getMultiResults($only_count = false, $debug = false) {
		return $this->_get_resultsv2('bbm_board_members', array(), $this->order_by, $only_count, $debug);
	}
}
