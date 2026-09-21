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
	);

	public static $test_fixture = array(
		'values'       => array('svo_key' => 'harness/key'),
		'update_field' => 'svo_chain',
	);

	public static $field_specifications = array(
		'svo_shelf_object_id'       => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'svo_svt_service_tenant_id' => array('type'=>'int8', 'is_nullable'=>false),
		'svo_svr_shelf_run_id'      => array('type'=>'int8'),
		'svo_key'                   => array('type'=>'varchar(1024)', 'is_nullable'=>false),
		'svo_bytes'                 => array('type'=>'int8', 'is_nullable'=>false, 'default'=>0),
		'svo_chain'                 => array('type'=>'varchar(64)'),
		// Held while a multipart upload is open; cleared on completion.
		'svo_upload_id'             => array('type'=>'varchar(255)'),
		'svo_signed_time'           => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'svo_completed_time'        => array('type'=>'timestamp(6)'),
		'svo_create_time'           => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'svo_update_time'           => array('type'=>'timestamp(6)'),
		'svo_delete_time'           => array('type'=>'timestamp(6)'),
	);

	function prepare() {
		$this->set('svo_update_time', gmdate('Y-m-d H:i:s'));
	}

	function save($debug = false) {
		if (!(int)$this->get('svo_svt_service_tenant_id')) {
			throw new ShelfObjectException('A ledger row belongs to a tenant.');
		}
		if (trim((string)$this->get('svo_key')) === '') {
			throw new ShelfObjectException('A ledger row names a key.');
		}
		$this->set('svo_update_time', gmdate('Y-m-d H:i:s'));
		return parent::save($debug);
	}

	/** The tenant's completed bytes: the figure in its backup storage row. */
	public static function completedBytes(int $tenant_id): int {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT COALESCE(SUM(svo_bytes), 0) FROM svo_shelf_objects
			WHERE svo_svt_service_tenant_id = ? AND svo_completed_time IS NOT NULL AND svo_delete_time IS NULL");
		$q->execute(array($tenant_id));
		return (int)$q->fetchColumn();
	}

	/** The row for one key of one tenant, newest first, or null. */
	public static function forKey(int $tenant_id, string $key): ?ShelfObject {
		$rows = new MultiShelfObject(array('tenant_id' => $tenant_id, 'key' => $key, 'deleted' => false),
			array('svo_shelf_object_id' => 'DESC'), 1);
		foreach ($rows as $row) {
			return $row;
		}
		return null;
	}
}

class MultiShelfObject extends SystemMultiBase {
	protected static $model_class = 'ShelfObject';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['tenant_id'])) {
			$filters['svo_svt_service_tenant_id'] = array((int)$this->options['tenant_id'], PDO::PARAM_INT);
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
