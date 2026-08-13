<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

/**
 * FileAccessGrant — a self-service share of a Drive file or folder to another
 * member (fga_file_access_grants).
 *
 * One grant per (entity, user). A grant on a folder reaches every descendant.
 * Roles: 'viewer' (read) and 'editor' (read + rename / new version / upload into
 * the folder). Delete and share stay owner-only. Revocation is a hard delete
 * (no delete_time column) — the ieg_ precedent. Authorization for who may sync
 * lives in the logic layer (drive_share_sync); this class stays CRUD.
 *
 * @version 1.0.0
 */
class FileAccessGrant extends SystemBase {
	public static $prefix = 'fga';
	public static $tablename = 'fga_file_access_grants';
	public static $pkey_column = 'fga_file_access_grant_id';

	// The grantee reference cascades on user delete. fga_granted_by_user_id is a
	// non-standard name ('granted' is no model prefix), so it registers no rule.
	protected static $foreign_key_actions = array(
		'fga_usr_user_id' => array('action' => 'cascade'),
	);

	public static $field_specifications = array(
		'fga_file_access_grant_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true, 'is_primary_key' => true),
		'fga_entity_type'          => array('type' => 'varchar(16)', 'is_nullable' => false, 'required' => true),
		'fga_entity_id'            => array('type' => 'int8', 'is_nullable' => false, 'required' => true),
		'fga_usr_user_id'          => array('type' => 'int4', 'is_nullable' => false, 'required' => true, 'unique_with' => array('fga_entity_type', 'fga_entity_id')),
		'fga_role'                 => array('type' => 'varchar(16)', 'is_nullable' => false, 'required' => true),
		'fga_granted_by_user_id'   => array('type' => 'int4', 'is_nullable' => false, 'required' => true),
		'fga_create_time'          => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
	);

	/** Entity ids of a given type that are shared to $user_id. */
	public static function entity_ids_for_user($user_id, $entity_type) {
		$grants = new MultiFileAccessGrant(array('user_id' => (int)$user_id, 'entity_type' => $entity_type));
		$grants->load();
		$ids = array();
		foreach ($grants as $g) {
			$ids[] = (int)$g->get('fga_entity_id');
		}
		return $ids;
	}

	/** User ids granted access to a given entity. */
	public static function user_ids_for_entity($entity_type, $entity_id) {
		$grants = new MultiFileAccessGrant(array('entity_type' => $entity_type, 'entity_id' => (int)$entity_id));
		$grants->load();
		$ids = array();
		foreach ($grants as $g) {
			$ids[] = (int)$g->get('fga_usr_user_id');
		}
		return $ids;
	}

	/** The role a user holds on an entity ('viewer'|'editor'), or null. */
	public static function role_for($entity_type, $entity_id, $user_id) {
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare(
			"SELECT fga_role FROM fga_file_access_grants
			  WHERE fga_entity_type = ? AND fga_entity_id = ? AND fga_usr_user_id = ? LIMIT 1");
		$q->execute(array($entity_type, (int)$entity_id, (int)$user_id));
		$role = $q->fetchColumn();
		return $role === false ? null : $role;
	}

	/**
	 * Reconcile the full grant set for an entity to $grants (user_id => role):
	 * delete grants no longer wanted, insert new ones, update changed roles, leave
	 * the rest. Returns the list of newly-granted user ids (for notifications).
	 */
	public static function sync_for_entity($entity_type, $entity_id, array $grants, $granted_by_user_id) {
		$desired = array();
		foreach ($grants as $uid => $role) {
			$uid = (int)$uid;
			$role = ($role === 'editor') ? 'editor' : 'viewer';
			if ($uid > 0 && $uid !== (int)$granted_by_user_id) {
				$desired[$uid] = $role;
			}
		}

		$existing = new MultiFileAccessGrant(array('entity_type' => $entity_type, 'entity_id' => (int)$entity_id));
		$existing->load();
		$current = array();
		foreach ($existing as $g) {
			$current[(int)$g->get('fga_usr_user_id')] = $g;
		}

		$newly_granted = array();

		// Remove grants no longer wanted (hard delete).
		foreach ($current as $uid => $grant) {
			if (!isset($desired[$uid])) {
				$grant->permanent_delete();
			}
		}

		// Insert new grants; update changed roles.
		foreach ($desired as $uid => $role) {
			if (!isset($current[$uid])) {
				$grant = new FileAccessGrant(NULL);
				$grant->set('fga_entity_type', $entity_type);
				$grant->set('fga_entity_id', (int)$entity_id);
				$grant->set('fga_usr_user_id', $uid);
				$grant->set('fga_role', $role);
				$grant->set('fga_granted_by_user_id', (int)$granted_by_user_id);
				$grant->save();
				$newly_granted[] = $uid;
			} elseif ($current[$uid]->get('fga_role') !== $role) {
				$current[$uid]->set('fga_role', $role);
				$current[$uid]->save();
			}
		}

		return $newly_granted;
	}
}

class MultiFileAccessGrant extends SystemMultiBase {
	protected static $model_class = 'FileAccessGrant';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['user_id'])) {
			$filters['fga_usr_user_id'] = array($this->options['user_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['entity_type'])) {
			$filters['fga_entity_type'] = array($this->options['entity_type'], PDO::PARAM_STR);
		}
		if (isset($this->options['entity_id'])) {
			$filters['fga_entity_id'] = array($this->options['entity_id'], PDO::PARAM_INT);
		}

		return $this->_get_resultsv2('fga_file_access_grants', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
