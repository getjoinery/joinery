<?php
/**
 * ShelfRun — one backup run a tenant asked the backup storage broker to take
 * (specs/services_phase2_platform.md §3).
 *
 * A run is opened by shelf_begin_run with what it means to write (the
 * profile, the chain, the artifacts with their sizes) and is what every later
 * signing request names. Its base key — {path_prefix}/{slug}/{profile}/ — is
 * the boundary every key the broker signs for it must sit inside, and the
 * declared bytes are what the allowance check counted before the run was
 * allowed. A run is spent once finished or aborted: a signing request against
 * it is refused.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class ShelfRunException extends SystemBaseException {}

class ShelfRun extends SystemBase {
	public static $prefix = 'svr';
	public static $tablename = 'svr_shelf_runs';
	public static $pkey_column = 'svr_shelf_run_id';

	const STATE_OPEN     = 'open';
	const STATE_FINISHED = 'finished';
	const STATE_ABORTED  = 'aborted';

	protected static $foreign_key_actions = array(
		'svr_svt_service_tenant_id' => array('action' => 'cascade'),
	);

	public static $test_fixture = array(
		'values'       => array('svr_profile' => 'site', 'svr_state' => 'open'),
		'update_field' => 'svr_chain',
	);

	public static $field_specifications = array(
		'svr_shelf_run_id'          => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'svr_svt_service_tenant_id' => array('type'=>'int8', 'is_nullable'=>false),
		'svr_profile'               => array('type'=>'varchar(16)', 'is_nullable'=>false, 'default'=>'site'),
		'svr_chain'                 => array('type'=>'varchar(64)'),
		// The boundary: every key signed for this run starts with it.
		'svr_base_key'              => array('type'=>'varchar(512)', 'is_nullable'=>false),
		// What the site declared, and what the allowance check counted.
		'svr_declared_bytes'        => array('type'=>'int8', 'is_nullable'=>false, 'default'=>0),
		'svr_declared_names'        => array('type'=>'text'),
		'svr_state'                 => array('type'=>'varchar(16)', 'is_nullable'=>false, 'default'=>'open',
			'allowed_values'=>array('open', 'finished', 'aborted')),
		'svr_cause'                 => array('type'=>'text'),
		'svr_create_time'           => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'svr_finish_time'           => array('type'=>'timestamp(6)'),
		'svr_update_time'           => array('type'=>'timestamp(6)'),
		'svr_delete_time'           => array('type'=>'timestamp(6)'),
	);

	function prepare() {
		$this->set('svr_update_time', gmdate('Y-m-d H:i:s'));
	}

	function save($debug = false) {
		if (!(int)$this->get('svr_svt_service_tenant_id')) {
			throw new ShelfRunException('A backup storage run belongs to a tenant.');
		}
		if (trim((string)$this->get('svr_base_key')) === '') {
			throw new ShelfRunException('A backup storage run has a base key.');
		}
		$this->set('svr_update_time', gmdate('Y-m-d H:i:s'));
		return parent::save($debug);
	}

	/** The artifact names the run declared. */
	public function declaredNames(): array {
		$raw = $this->get('svr_declared_names');
		if (is_string($raw)) { $raw = json_decode($raw, true); }
		return is_array($raw) ? array_values(array_map('strval', $raw)) : array();
	}
}

class MultiShelfRun extends SystemMultiBase {
	protected static $model_class = 'ShelfRun';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['tenant_id'])) {
			$filters['svr_svt_service_tenant_id'] = array((int)$this->options['tenant_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['state'])) {
			$filters['svr_state'] = array((string)$this->options['state'], PDO::PARAM_STR);
		}
		// Runs left open longer than this many hours: the prune pass aborts them.
		if (isset($this->options['stale_hours'])) {
			$filters['svr_create_time'] = "< now() - interval '" . max(1, (int)$this->options['stale_hours']) . " hours'";
		}
		return $this->_get_resultsv2('svr_shelf_runs', $filters, $this->order_by, $only_count, $debug);
	}
}
