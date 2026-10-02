<?php
/**
 * SiteCopy - one site copied onto a new server, and kept dormant there
 * (specs/site_copy.md WP8).
 *
 * The record of a copy from its start to its discard: which node is copied
 * (the source), the dormant copy's node row once it exists, the release the
 * copy was installed at, and the copy run in progress or the last one. A copy
 * run is several agent jobs over minutes or hours (the export waits for the
 * source owner's approval), so it is a tracked record like a staged rollout:
 * it survives a reload, shows where it is, and SiteCopyRunner moves it. This
 * class only holds it.
 *
 * scp_steps is the current or last run, in order, one entry per job:
 *   {op, on: source|copy, job_id, verdict, reason}
 * verdict is one of pending | running | passed | failed | skipped.
 *
 * scp_status:
 *   waiting    - the copy's server is being created, or the owner's own server
 *                has not joined yet; the first run starts once it has
 *   copying    - a run is in progress
 *   dormant    - the last run passed; the copy is quiet and current as of it
 *   halted     - the last run stopped, and why is in scp_halt_reason
 *   discarded  - the owner discarded the copy; its server is theirs to delete
 *
 * and the switch-over's (WP7a), each a run of steps like a copy run:
 *   freezing   - the source is frozen and the final copy runs
 *   ready      - the final copy matched exactly; the source is still frozen,
 *                waiting for the owner to move the address
 *   starting   - the address points at the copy; it takes the source's node
 *                id and starts as the site
 *   switched   - the copy is the site; the way back is still open
 *   returning  - going back: the source is being started again
 *   finished   - the owner kept the switch-over; the old server is theirs to
 *                delete
 *
 * scp_switch is the switch-over's record: the phase it is in or stopped in
 * (freeze, start, return), how the address moves, the zone and each record
 * moved (id, type, name, from, to), and when it was frozen, moved, proven and
 * moved back.
 *
 * @version 1.1 - the switch-over's statuses and scp_switch (site_copy.md WP7a)
 * @version 1.0
 */

class SiteCopyException extends SystemBaseException {}

class SiteCopy extends SystemBase {
	public static $prefix = 'scp';
	public static $tablename = 'scp_site_copies';
	public static $pkey_column = 'scp_site_copy_id';

	public static $json_vars = array('scp_steps', 'scp_census', 'scp_switch');

	const STATUS_WAITING   = 'waiting';
	const STATUS_COPYING   = 'copying';
	const STATUS_DORMANT   = 'dormant';
	const STATUS_HALTED    = 'halted';
	const STATUS_DISCARDED = 'discarded';
	const STATUS_FREEZING  = 'freezing';
	const STATUS_READY     = 'ready';
	const STATUS_STARTING  = 'starting';
	const STATUS_SWITCHED  = 'switched';
	const STATUS_RETURNING = 'returning';
	const STATUS_FINISHED  = 'finished';

	/** The statuses in which a run of steps is moving. */
	const MOVING_STATUSES = array(self::STATUS_COPYING, self::STATUS_FREEZING, self::STATUS_STARTING, self::STATUS_RETURNING);

	/** The words each status shows. */
	const STATUS_LABELS = array(
		self::STATUS_WAITING   => 'Waiting for the new server',
		self::STATUS_COPYING   => 'Copying',
		self::STATUS_DORMANT   => 'Dormant copy, current',
		self::STATUS_HALTED    => 'Stopped',
		self::STATUS_DISCARDED => 'Discarded',
		self::STATUS_FREEZING  => 'Switching over: the final copy',
		self::STATUS_READY     => 'Switching over: ready to move the address',
		self::STATUS_STARTING  => 'Switching over: starting the copy as the site',
		self::STATUS_SWITCHED  => 'Switched over',
		self::STATUS_RETURNING => 'Going back',
		self::STATUS_FINISHED  => 'Switch-over finished',
	);

	public static $field_specifications = array(
		'scp_site_copy_id'       => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'scp_source_node_id'     => array('type'=>'int8'),
		'scp_copy_node_id'       => array('type'=>'int8'),
		// The provision when the management node creates the copy's server;
		// empty when the owner brings their own.
		'scp_cvp_customer_cloud_provision_id' => array('type'=>'int8'),
		'scp_release'            => array('type'=>'varchar(20)', 'required'=>true, 'is_nullable'=>false),
		'scp_status'             => array('type'=>'varchar(16)', 'is_nullable'=>false, 'default'=>'waiting'),
		'scp_steps'              => array('type'=>'jsonb'),
		'scp_chain_id'           => array('type'=>'varchar(64)'),
		// The newest upload time of the chain the run applied: what the copy is
		// current as of.
		'scp_chain_time'         => array('type'=>'varchar(40)'),
		// The last run's census comparison (SiteCensus::compare's verdict).
		'scp_census'             => array('type'=>'jsonb'),
		// The path that sets the copy's look cookie, as its import reported it.
		'scp_look_path'          => array('type'=>'varchar(64)'),
		'scp_halt_reason'        => array('type'=>'text'),
		// The switch-over's record (see the header).
		'scp_switch'             => array('type'=>'jsonb'),
		'scp_run_started_time'   => array('type'=>'timestamp(6)'),
		'scp_last_copied_time'   => array('type'=>'timestamp(6)'),
		'scp_created_by'         => array('type'=>'int8'),
		'scp_create_time'        => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'scp_update_time'        => array('type'=>'timestamp(6)'),
		'scp_delete_time'        => array('type'=>'timestamp(6)'),
	);

	protected static $foreign_key_actions = [
		'scp_created_by'     => ['action' => 'null', 'source_table' => 'usr_users'],
		'scp_source_node_id' => ['action' => 'null', 'source_table' => 'mgn_managed_nodes'],
		'scp_copy_node_id'   => ['action' => 'null', 'source_table' => 'mgn_managed_nodes'],
		'scp_cvp_customer_cloud_provision_id' => ['action' => 'null', 'source_table' => 'cvp_customer_cloud_provisions'],
	];

	/** The run's steps as an array. */
	public function steps(): array {
		$steps = $this->get('scp_steps');
		if (is_string($steps)) {
			$steps = json_decode($steps, true);
		}
		return is_array($steps) ? array_values($steps) : array();
	}

	public function set_steps(array $steps): void {
		$this->set('scp_steps', array_values($steps));
	}

	/** The last run's census verdict, or null. */
	public function census(): ?array {
		$c = $this->get('scp_census');
		if (is_string($c)) {
			$c = json_decode($c, true);
		}
		return is_array($c) ? $c : null;
	}

	/** The switch-over's record, or an empty array before one starts. */
	public function switch_record(): array {
		$s = $this->get('scp_switch');
		if (is_string($s)) {
			$s = json_decode($s, true);
		}
		return is_array($s) ? $s : array();
	}

	public function set_switch_record(array $record): void {
		$this->set('scp_switch', $record);
	}

	public function status(): string {
		return (string)$this->get('scp_status');
	}

	public function status_label(): string {
		return self::STATUS_LABELS[$this->status()] ?? $this->status();
	}

	/** Whether this copy is still the source's copy (anything but discarded or finished). */
	public function is_live(): bool {
		return !in_array($this->status(), array(self::STATUS_DISCARDED, self::STATUS_FINISHED), true)
			&& !$this->get('scp_delete_time');
	}

	/** The source's live copy, or null. There is at most one. */
	public static function live_for_source(int $source_id): ?SiteCopy {
		foreach (new MultiSiteCopy(array('source_node_id' => $source_id, 'deleted' => false),
				array('scp_site_copy_id' => 'DESC')) as $c) {
			if ($c->is_live()) {
				return $c;
			}
		}
		return null;
	}

	/**
	 * The source's most recent copy that ended (discarded, or a switch-over
	 * kept) within $days, or null: its server is still the owner's to delete.
	 */
	public static function recently_ended_for_source(int $source_id, int $days = 7): ?SiteCopy {
		foreach (new MultiSiteCopy(array('source_node_id' => $source_id, 'deleted' => false),
				array('scp_update_time' => 'DESC'), 1) as $c) {
			$when = strtotime((string)$c->get('scp_update_time') . ' UTC');
			return (!$c->is_live() && $when && time() - $when < $days * 86400) ? $c : null;
		}
		return null;
	}

	/** The live copy whose copy row is this node, or null. */
	public static function live_for_copy_node(int $node_id): ?SiteCopy {
		foreach (new MultiSiteCopy(array('copy_node_id' => $node_id, 'deleted' => false),
				array('scp_site_copy_id' => 'DESC')) as $c) {
			if ($c->is_live()) {
				return $c;
			}
		}
		return null;
	}

	public function save($debug = false) {
		$this->set('scp_update_time', gmdate('Y-m-d H:i:s'));
		return parent::save($debug);
	}
}

class MultiSiteCopy extends SystemMultiBase {
	protected static $model_class = 'SiteCopy';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];
		if (isset($this->options['status'])) {
			$filters['scp_status'] = [$this->options['status'], PDO::PARAM_STR];
		}
		if (isset($this->options['source_node_id'])) {
			$filters['scp_source_node_id'] = [(int)$this->options['source_node_id'], PDO::PARAM_INT];
		}
		if (isset($this->options['copy_node_id'])) {
			$filters['scp_copy_node_id'] = [(int)$this->options['copy_node_id'], PDO::PARAM_INT];
		}
		return $this->_get_resultsv2('scp_site_copies', $filters, $this->order_by, $only_count, $debug);
	}
}
