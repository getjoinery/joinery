<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class FolderException extends SystemBaseException {}

/**
 * Folder — a node in a user's Drive tree (fol_folders).
 *
 * A folder is purely logical: it owns a name, an owner, and a parent (null =
 * drive root). Files reference their folder via fil_fol_folder_id; the drive
 * root is implicit (null parent), so there is no root row.
 *
 * Structural rules — sibling-name uniqueness among live rows, cycle rejection on
 * move, depth cap, and soft-delete cascade/selective-restore — are enforced in
 * the drive logic layer (logic/drive_*), not here; this class stays CRUD.
 *
 * @version 1.1.0
 */
class Folder extends SystemBase {
	public static $prefix = 'fol';
	public static $tablename = 'fol_folders';
	public static $pkey_column = 'fol_folder_id';

	// Not a REST/AI resource — all access flows through the drive_* actions.

	// When the owner is deleted, reassign to the deleted-user sentinel (matches
	// File's owner rule). fol_parent_folder_id is a self-reference with a
	// non-standard segment ('parent'), so it registers no auto-detected rule —
	// descendant lifecycle is handled in the logic layer.
	protected static $foreign_key_actions = array(
		'fol_usr_user_id' => array('action' => 'set_value', 'value' => User::USER_DELETED),
	);

	public static $field_specifications = array(
		'fol_folder_id'        => array('type' => 'int8', 'is_nullable' => false, 'serial' => true, 'is_primary_key' => true),
		'fol_usr_user_id'      => array('type' => 'int4', 'is_nullable' => false, 'required' => true, 'index' => true),
		'fol_parent_folder_id' => array('type' => 'int8', 'is_nullable' => true, 'index' => true),
		'fol_name'             => array('type' => 'varchar(255)', 'is_nullable' => false, 'required' => true),
		// How this subtree is protected (docs/drive.md), one of the platform
		// ladder's Drive rungs: standard (plaintext), private (server custody,
		// sealed to the owner's vault, opened in-window), fortress (client
		// custody, end-to-end encrypted — docs/drive_encryption.md). Protection
		// is a property of the subtree: a folder's level is the floor for
		// everything inside it, so a child never sits below its parent.
		'fol_protection_level' => array('type' => 'varchar(16)', 'is_nullable' => false, 'default' => 'standard'),
		'fol_create_time'      => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
		'fol_delete_time'      => array('type' => 'timestamp(6)', 'is_nullable' => true),
	);

	// Sibling-name uniqueness among live rows. NULL parents must collide, so
	// coalesce to 0. A plain UNIQUE cannot scope to non-deleted rows, so this is
	// a partial unique expression index.
	public static $index_specifications = array(
		array(
			'columns' => array('fol_usr_user_id', 'COALESCE(fol_parent_folder_id, 0)', 'fol_name'),
			'unique'  => true,
			'where'   => 'fol_delete_time IS NULL',
		),
	);

	function __construct($key, $and_load = FALSE) {
		parent::__construct($key, $and_load);
	}

	/** True when this folder belongs to $user_id. */
	public function is_owned_by($user_id) {
		return (int)$this->get('fol_usr_user_id') === (int)$user_id;
	}

	/** This folder's protection level, normalized (see ProtectionLevel). */
	public function protection_level() {
		require_once(PathHelper::getIncludePath('includes/ProtectionLevel.php'));
		return ProtectionLevel::normalize($this->get('fol_protection_level'));
	}
}

class MultiFolder extends SystemMultiBase {
	protected static $model_class = 'Folder';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['user_id'])) {
			$filters['fol_usr_user_id'] = array($this->options['user_id'], PDO::PARAM_INT);
		}

		// parent_id: an explicit NULL / 0 means "drive root" (IS NULL); a positive
		// id means that folder's direct children.
		if (array_key_exists('parent_id', $this->options)) {
			$pid = $this->options['parent_id'];
			if ($pid === null || $pid === '' || (int)$pid === 0) {
				$filters['fol_parent_folder_id'] = 'IS NULL';
			} else {
				$filters['fol_parent_folder_id'] = array((int)$pid, PDO::PARAM_INT);
			}
		}

		if (isset($this->options['name'])) {
			$filters['fol_name'] = array($this->options['name'], PDO::PARAM_STR);
		}

		if (isset($this->options['deleted'])) {
			$filters['fol_delete_time'] = $this->options['deleted'] ? 'IS NOT NULL' : 'IS NULL';
		}

		return $this->_get_resultsv2('fol_folders', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
