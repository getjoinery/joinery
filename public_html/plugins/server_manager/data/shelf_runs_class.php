<?php
/**
 * ShelfRun — one backup run taken through the backup storage broker
 * (specs/services_phase2_platform.md §3, specs/storage_targets.md §3).
 *
 * Its owner is a customer of backup storage (svr_svt_service_tenant_id) or a
 * Managed node (svr_mgn_managed_node_id), never both. A customer opens its
 * run with shelf_begin_run; a node's run is opened by the management node
 * when the node's agent claims the job, and the node presents the run's
 * token (stored here as its hash) on every broker call until the token
 * expires. svr_kind says what the run is: a backup, or the re-upload of an
 * archive already taken.
 *
 * A run is opened by shelf_begin_run with what it means to write (the
 * profile, the chain, the artifacts with their sizes) and is what every later
 * signing request names. Its base key — {path_prefix}/{slug}/{profile}/ — is
 * the boundary every key the broker signs for it must sit inside, and the
 * declared bytes are what the allowance check counted before the run was
 * allowed. A run is spent once finished or aborted: a signing request against
 * it is refused.
 *
 * @version 1.2 - a Managed node owns a run as a customer does (svr_mgn_managed_node_id); svr_kind, the run's
 *                token hash and expiry, and svr_ledger_time, when its ledger file reached backup storage
 *                (specs/storage_targets.md WP5, F4); svr_ledger_problem and svr_ledger_tried_time, why it could not be and when, retried daily
 * @version 1.1 - svr_verified_time: when the site reported this run's chain verified restorable through it; the
 *                customer's newest verified chain and everything newer survive retention (specs/storage_targets.md F1)
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

	const KIND_BACKUP = 'backup';
	const KIND_UPLOAD = 'upload';

	protected static $foreign_key_actions = array(
		'svr_svt_service_tenant_id' => array('action' => 'cascade'),
		'svr_mgn_managed_node_id'   => array('action' => 'cascade'),
		'svr_sps_storage_space_id'  => array('action' => 'null'),
	);

	public static $test_fixture = array(
		// A new run is taken in a storage space; the generator only fills NOT NULL columns.
		'values'       => array('svr_profile' => 'site', 'svr_state' => 'open', 'svr_sps_storage_space_id' => 1, 'svr_svt_service_tenant_id' => 1),
		'update_field' => 'svr_chain',
	);

	public static $field_specifications = array(
		'svr_shelf_run_id'          => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		// The owner: a customer of backup storage, or a Managed node. One of the two.
		'svr_svt_service_tenant_id' => array('type'=>'int8'),
		'svr_mgn_managed_node_id'   => array('type'=>'int8'),
		'svr_kind'                  => array('type'=>'varchar(16)', 'is_nullable'=>false, 'default'=>'backup',
			'allowed_values'=>array('backup', 'upload')),
		// A node's run: the sha256 of the token its broker calls carry, and when
		// that token stops being accepted. Never the token itself.
		'svr_token_hash'            => array('type'=>'varchar(64)'),
		'svr_token_expires_time'    => array('type'=>'timestamp(6)'),
		// The space the run was taken in, and so the target that holds it.
		'svr_sps_storage_space_id'  => array('type'=>'int8'),
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
		// When the site reported a verify of its chain through this run passed
		// (shelf_verified_run). Retention keeps the newest verified chain and
		// everything newer.
		'svr_verified_time'         => array('type'=>'timestamp(6)'),
		// When the run's ledger file, {space base}ledger/{run id}.json, was
		// written to backup storage (specs/storage_targets.md F4).
		'svr_ledger_time'           => array('type'=>'timestamp(6)'),
		// Why the ledger file could not be written, while it could not, and
		// when that was: tried again a day after the last attempt.
		'svr_ledger_problem'        => array('type'=>'text'),
		'svr_ledger_tried_time'     => array('type'=>'timestamp(6)'),
		'svr_update_time'           => array('type'=>'timestamp(6)'),
		'svr_delete_time'           => array('type'=>'timestamp(6)'),
	);

	function prepare() {
		$this->set('svr_update_time', gmdate('Y-m-d H:i:s'));
	}

	function save($debug = false) {
		if (!(int)$this->get('svr_svt_service_tenant_id') === !(int)$this->get('svr_mgn_managed_node_id')) {
			throw new ShelfRunException('A backup storage run belongs to one customer or one node.');
		}
		if (!$this->key && !(int)$this->get('svr_sps_storage_space_id')) {
			throw new ShelfRunException('A backup storage run is taken in a storage space.');
		}
		if (trim((string)$this->get('svr_base_key')) === '') {
			throw new ShelfRunException('A backup storage run has a base key.');
		}
		$this->set('svr_update_time', gmdate('Y-m-d H:i:s'));
		return parent::save($debug);
	}

	/** The space the run was taken in. */
	public function space(): StorageSpace {
		$space = new StorageSpace((int)$this->get('svr_sps_storage_space_id'), TRUE);
		if (!$space->key) {
			throw new ShelfRunException('This backup storage run records no storage space.');
		}
		return $space;
	}

	/** Whether a Managed node owns this run. */
	public function isNodeRun(): bool {
		return (int)$this->get('svr_mgn_managed_node_id') > 0;
	}

	/**
	 * Whether $token is this run's live token: the hashes match and it has not
	 * expired. A run with no token (a customer's) matches nothing.
	 */
	public function tokenMatches(string $token, ?int $now = null): bool {
		$hash = (string)$this->get('svr_token_hash');
		if ($hash === '' || trim($token) === '') {
			return false;
		}
		$expires = strtotime((string)$this->get('svr_token_expires_time') . ' UTC');
		if ($expires === false || $expires <= ($now ?? time())) {
			return false;
		}
		return hash_equals($hash, hash('sha256', $token));
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
		if (isset($this->options['node_id'])) {
			$filters['svr_mgn_managed_node_id'] = array((int)$this->options['node_id'], PDO::PARAM_INT);
		}
		// Node runs whose token has expired while still open: the pass aborts them.
		if (!empty($this->options['token_expired'])) {
			$filters['svr_token_expires_time'] = '< now()';
		}
		// Finished runs whose ledger file is not yet in backup storage.
		if (isset($this->options['ledger_written'])) {
			$filters['svr_ledger_time'] = $this->options['ledger_written'] ? 'IS NOT NULL' : 'IS NULL';
		}
		// ...and due a try: never tried and failed, or last failed a day ago.
		if (!empty($this->options['ledger_due'])) {
			$filters['(svr_ledger_problem'] = "IS NULL OR svr_ledger_tried_time IS NULL OR svr_ledger_tried_time < now() - interval '1 day')";
		}
		if (isset($this->options['ledger_failed'])) {
			$filters['svr_ledger_problem'] = $this->options['ledger_failed'] ? 'IS NOT NULL' : 'IS NULL';
		}
		if (isset($this->options['space_id'])) {
			$filters['svr_sps_storage_space_id'] = array((int)$this->options['space_id'], PDO::PARAM_INT);
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
