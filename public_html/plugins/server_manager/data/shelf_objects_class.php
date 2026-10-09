<?php
/**
 * ShelfObject — the ledger: one row per object the backup storage broker signed
 * (specs/services_phase2_platform.md §3, §6).
 *
 * The plane records every key it signs a write for — tenant, run, key,
 * bytes, chain, when it was signed and when the site said it completed — so
 * the meter is exact and immediate: the figure in a tenant's backup storage row is the
 * sum of its completed rows, no listing pass needed, and a run that would
 * cross the allowance is refused before a byte moves. The prune pass
 * reconciles this against a real listing: an object signed but never
 * completed is aborted on the plane's side and dropped here; an object the
 * listing does not know is dropped too.
 *
 * A multipart upload's id is kept while it is open so the plane can abort it
 * itself — the plane's own call, not a delete the box could make.
 *
 * Every row is in one storage space (svo_sps_storage_space_id), the run's,
 * and so on one target. A row whose object is gone is KEPT, not deleted: it
 * gains svo_pruned_time and the cause (retention, lapse, abort, reconcile), so
 * the ledger answers "what happened to this object" for as long as the space
 * is remembered (specs/storage_targets.md §4). Every count and every lookup of
 * a live object reads unpruned rows only.
 *
 * @version 1.2 - a Managed node's objects are in the ledger too (no tenant; the space names the owner); svo_sha256,
 *                the hash the writer reported at finish, which nothing in backup storage can change
 *                (specs/storage_targets.md §6); a completed key is never signed again; preferredManifestName(),
 *                a chain's newest recorded manifest before a newer one nothing recorded
 * @version 1.1 - svo_sps_storage_space_id; pruned rows are kept with their time and cause
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class ShelfObjectException extends SystemBaseException {}

class ShelfObject extends SystemBase {
	public static $prefix = 'svo';
	public static $tablename = 'svo_shelf_objects';
	public static $pkey_column = 'svo_shelf_object_id';

	protected static $foreign_key_actions = array(
		'svo_svt_service_tenant_id' => array('action' => 'cascade'),
		'svo_svr_shelf_run_id'      => array('action' => 'null'),
		'svo_sps_storage_space_id'  => array('action' => 'null'),
	);

	/** Why an object left the ledger's live count. */
	const PRUNED_RETENTION = 'retention';
	const PRUNED_LAPSE     = 'lapse';
	const PRUNED_ABORT     = 'abort';
	const PRUNED_RECONCILE = 'reconcile';

	public static $test_fixture = array(
		// A new ledger row is in a storage space; the generator only fills NOT NULL columns.
		'values'       => array('svo_key' => 'harness/key', 'svo_sps_storage_space_id' => 1),
		'update_field' => 'svo_chain',
	);

	public static $field_specifications = array(
		'svo_shelf_object_id'       => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		// A customer's object names its tenant; a node's names none — its space says whose it is.
		'svo_svt_service_tenant_id' => array('type'=>'int8'),
		'svo_svr_shelf_run_id'      => array('type'=>'int8'),
		// The space the object is in, and so the target that holds it.
		'svo_sps_storage_space_id'  => array('type'=>'int8'),
		'svo_key'                   => array('type'=>'varchar(1024)', 'is_nullable'=>false),
		'svo_bytes'                 => array('type'=>'int8', 'is_nullable'=>false, 'default'=>0),
		// The sha256 the writer reported when the run finished: the management
		// node's record of what these bytes are.
		'svo_sha256'                => array('type'=>'varchar(64)'),
		'svo_chain'                 => array('type'=>'varchar(64)'),
		// Held while a multipart upload is open; cleared on completion.
		'svo_upload_id'             => array('type'=>'varchar(255)'),
		'svo_signed_time'           => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'svo_completed_time'        => array('type'=>'timestamp(6)'),
		// Gone from backup storage, and why. The row stays.
		'svo_pruned_time'           => array('type'=>'timestamp(6)'),
		'svo_pruned_cause'          => array('type'=>'varchar(16)',
			'allowed_values'=>array('retention', 'lapse', 'abort', 'reconcile')),
		'svo_create_time'           => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'svo_update_time'           => array('type'=>'timestamp(6)'),
		'svo_delete_time'           => array('type'=>'timestamp(6)'),
	);

	function prepare() {
		$this->set('svo_update_time', gmdate('Y-m-d H:i:s'));
	}

	function save($debug = false) {
		if (trim((string)$this->get('svo_key')) === '') {
			throw new ShelfObjectException('A ledger row names a key.');
		}
		if (!$this->key && !(int)$this->get('svo_sps_storage_space_id')) {
			throw new ShelfObjectException('A ledger row is in a storage space.');
		}
		$this->set('svo_update_time', gmdate('Y-m-d H:i:s'));
		return parent::save($debug);
	}

	/** The tenant's completed bytes across its spaces: the figure in its backup storage row. */
	public static function completedBytes(int $tenant_id): int {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT COALESCE(SUM(svo_bytes), 0) FROM svo_shelf_objects
			WHERE svo_svt_service_tenant_id = ? AND svo_completed_time IS NOT NULL
			  AND svo_pruned_time IS NULL AND svo_delete_time IS NULL");
		$q->execute(array($tenant_id));
		return (int)$q->fetchColumn();
	}

	/** The live (unpruned) row for one key in one space, newest first, or null. */
	public static function forKey(int $space_id, string $key): ?ShelfObject {
		$rows = new MultiShelfObject(array('space_id' => $space_id, 'key' => $key, 'pruned' => false, 'deleted' => false),
			array('svo_shelf_object_id' => 'DESC'), 1);
		foreach ($rows as $row) {
			return $row;
		}
		return null;
	}

	/**
	 * Whether a chain has live objects in another space of $space's owner: a
	 * chain belongs to one space, and one stored where its owner was moved
	 * away from is not extended in the new one.
	 */
	public static function chainLivesElsewhere(StorageSpace $space, string $chain): bool {
		if ($chain === '') {
			return false;
		}
		$column = ($space->owner_kind() === StorageSpace::OWNER_NODE) ? 'sps_mgn_managed_node_id' : 'sps_svt_service_tenant_id';
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT 1 FROM svo_shelf_objects o JOIN sps_storage_spaces s ON s.sps_storage_space_id = o.svo_sps_storage_space_id
			WHERE o.svo_chain = ? AND o.svo_pruned_time IS NULL AND o.svo_delete_time IS NULL
			  AND s.{$column} = ? AND s.sps_storage_space_id <> ? LIMIT 1");
		$q->execute(array($chain, $space->owner_id(), (int)$space->key));
		return (bool)$q->fetchColumn();
	}

	/**
	 * The manifest of a chain to read among a chain folder's bare names: the
	 * newest one a finished run recorded with its hash, else the newest there.
	 * A run that failed between its upload and its finish can leave a newer
	 * manifest nothing recorded; it is not read while a recorded one is there.
	 *
	 * @param string $dir_key the chain folder's key, ending in '/'
	 */
	public static function preferredManifestName(int $space_id, string $dir_key, array $names): string {
		$recorded = array();
		if ($space_id > 0) {
			$db = DbConnector::get_instance()->get_db_link();
			$q = $db->prepare("SELECT svo_key FROM svo_shelf_objects WHERE svo_sps_storage_space_id = ? AND svo_key LIKE ?
				AND svo_completed_time IS NOT NULL AND svo_sha256 IS NOT NULL AND svo_sha256 <> ''
				AND svo_pruned_time IS NULL AND svo_delete_time IS NULL");
			$q->execute(array($space_id, str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $dir_key) . '%'));
			$present = array_flip(array_map('strval', $names));
			foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $key) {
				$name = substr((string)$key, strlen($dir_key));
				if (isset($present[$name]) && BackupChain::is_manifest_name($name)) {
					$recorded[] = $name;
				}
			}
		}
		$name = BackupChain::newest_manifest_name($recorded);
		return $name !== '' ? $name : BackupChain::newest_manifest_name($names);
	}

	/** Gone from backup storage: the row is kept with when and why. */
	public function markPruned(string $cause, ?string $now = null): void {
		$this->set('svo_pruned_time', $now ?? gmdate('Y-m-d H:i:s'));
		$this->set('svo_pruned_cause', $cause);
		$this->set('svo_upload_id', null);
		$this->save();
	}
}

class MultiShelfObject extends SystemMultiBase {
	protected static $model_class = 'ShelfObject';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['tenant_id'])) {
			$filters['svo_svt_service_tenant_id'] = array((int)$this->options['tenant_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['space_id'])) {
			$filters['svo_sps_storage_space_id'] = array((int)$this->options['space_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['pruned'])) {
			$filters['svo_pruned_time'] = $this->options['pruned'] ? 'IS NOT NULL' : 'IS NULL';
		}
		if (isset($this->options['run_id'])) {
			$filters['svo_svr_shelf_run_id'] = array((int)$this->options['run_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['key'])) {
			$filters['svo_key'] = array((string)$this->options['key'], PDO::PARAM_STR);
		}
		if (isset($this->options['chain'])) {
			$filters['svo_chain'] = array((string)$this->options['chain'], PDO::PARAM_STR);
		}
		if (isset($this->options['completed'])) {
			$filters['svo_completed_time'] = $this->options['completed'] ? 'IS NOT NULL' : 'IS NULL';
		}
		return $this->_get_resultsv2('svo_shelf_objects', $filters, $this->order_by, $only_count, $debug);
	}
}
