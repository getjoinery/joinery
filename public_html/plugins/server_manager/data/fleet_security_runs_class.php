<?php
/**
 * FleetSecurityRun - the fleet's security updates installed, one machine or
 * site container at a time (specs/fleet_security_patching.md WP5).
 *
 * Not a node word: each step is a node's own install_security_updates, or a
 * container host's refresh_site_containers, which replaces its site
 * containers (Q7). It runs for minutes per node, so it is a tracked record: it
 * survives a reload, shows where it is, and can be stopped between nodes.
 * FleetSecurityRunner moves it; this class only holds it.
 *
 * fsr_steps is the ordered list, one entry per step:
 *   {node_id, name, word, covers, job_id, verdict, outcome, reason, changed,
 *    reloaded, still_on_old_code, reboot_required}
 * verdict is one of pending | running | done | failed | skipped. A host's
 * replacement names its site containers in covers, and carries
 * first_asked_at, ask_again_at and replacing_since while it waits.
 *
 * @version 1.1 - a step names its word, and a host's replacement its site containers (Q7)
 * @version 1.0
 */

class FleetSecurityRunException extends SystemBaseException {}

class FleetSecurityRun extends SystemBase {
	public static $prefix = 'fsr';
	public static $tablename = 'fsr_fleet_security_runs';
	public static $access_class = 'config';
	public static $pkey_column = 'fsr_fleet_security_run_id';

	public static $json_vars = array('fsr_steps');

	const STATUS_RUNNING   = 'running';
	const STATUS_COMPLETED = 'completed';
	const STATUS_HALTED    = 'halted';
	const STATUS_STOPPED   = 'stopped';

	public static $field_specifications = array(
		'fsr_fleet_security_run_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'fsr_steps'                 => array('type'=>'jsonb', 'is_nullable'=>false),
		'fsr_position'              => array('type'=>'int4', 'is_nullable'=>false, 'default'=>'0'),
		'fsr_status'                => array('type'=>'varchar(16)', 'is_nullable'=>false, 'default'=>'running'),
		'fsr_halt_reason'           => array('type'=>'text'),
		'fsr_created_by'            => array('type'=>'int8'),
		'fsr_stopped_by'            => array('type'=>'int8'),
		'fsr_finish_time'           => array('type'=>'timestamp(6)'),
		'fsr_create_time'           => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'fsr_update_time'           => array('type'=>'timestamp(6)'),
		'fsr_delete_time'           => array('type'=>'timestamp(6)'),
	);

	protected static $foreign_key_actions = [
		'fsr_created_by' => ['action' => 'null', 'source_table' => 'usr_users'],
		'fsr_stopped_by' => ['action' => 'null', 'source_table' => 'usr_users'],
	];

	/** The ordered steps as an array. */
	public function steps(): array {
		$steps = $this->get('fsr_steps');
		if (is_string($steps)) {
			$steps = json_decode($steps, true);
		}
		return is_array($steps) ? array_values($steps) : array();
	}

	public function set_steps(array $steps): void {
		$this->set('fsr_steps', array_values($steps));
	}

	public function is_running(): bool {
		return (string)$this->get('fsr_status') === self::STATUS_RUNNING;
	}

	/** The run in progress, or null. There is at most one. */
	public static function running(): ?FleetSecurityRun {
		foreach (new MultiFleetSecurityRun(array('status' => self::STATUS_RUNNING, 'deleted' => false),
				array('fsr_fleet_security_run_id' => 'DESC'), 1) as $r) {
			return $r;
		}
		return null;
	}

	/** The newest run, finished or not, or null. */
	public static function latest(): ?FleetSecurityRun {
		foreach (new MultiFleetSecurityRun(array('deleted' => false), array('fsr_fleet_security_run_id' => 'DESC'), 1) as $r) {
			return $r;
		}
		return null;
	}

	public function save($debug = false) {
		$this->set('fsr_update_time', gmdate('Y-m-d H:i:s'));
		return parent::save($debug);
	}
}

class MultiFleetSecurityRun extends SystemMultiBase {
	protected static $model_class = 'FleetSecurityRun';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];
		if (isset($this->options['status'])) {
			$filters['fsr_status'] = [$this->options['status'], PDO::PARAM_STR];
		}
		return $this->_get_resultsv2('fsr_fleet_security_runs', $filters, $this->order_by, $only_count, $debug);
	}
}
