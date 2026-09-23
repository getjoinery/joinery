<?php
/**
 * StagedRollout - one release applied across an ordered list of nodes, one
 * node at a time, stopping at the first node whose apply does not prove good.
 *
 * specs/agent_recipes_and_vocabulary.md, "staged_rollout {release, order}"
 * (tier 2, on the plane). Not a node word: each step is the node's own
 * apply_update. It runs for minutes per node, so it is a tracked record: it
 * survives a reload, shows where it is, and can be stopped between nodes.
 * StagedRolloutRunner moves it; this class only holds it.
 *
 * srl_steps is the ordered list, one entry per node:
 *   {node_id, name, job_id, verdict, reason}
 * verdict is one of pending | running | passed | failed | skipped.
 *
 * @version 1.0
 */

class StagedRolloutException extends SystemBaseException {}

class StagedRollout extends SystemBase {
	public static $prefix = 'srl';
	public static $tablename = 'srl_staged_rollouts';
	public static $pkey_column = 'srl_staged_rollout_id';

	public static $json_vars = array('srl_steps');

	const STATUS_RUNNING   = 'running';
	const STATUS_COMPLETED = 'completed';
	const STATUS_HALTED    = 'halted';
	const STATUS_STOPPED   = 'stopped';

	public static $field_specifications = array(
		'srl_staged_rollout_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'srl_release'           => array('type'=>'varchar(20)', 'required'=>true, 'is_nullable'=>false),
		'srl_steps'             => array('type'=>'jsonb', 'is_nullable'=>false),
		'srl_position'          => array('type'=>'int4', 'is_nullable'=>false, 'default'=>'0'),
		'srl_status'            => array('type'=>'varchar(16)', 'is_nullable'=>false, 'default'=>'running'),
		'srl_halt_reason'       => array('type'=>'text'),
		'srl_created_by'        => array('type'=>'int8'),
		'srl_stopped_by'        => array('type'=>'int8'),
		'srl_finish_time'       => array('type'=>'timestamp(6)'),
		'srl_create_time'       => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'srl_update_time'       => array('type'=>'timestamp(6)'),
		'srl_delete_time'       => array('type'=>'timestamp(6)'),
	);

	protected static $foreign_key_actions = [
		'srl_created_by' => ['action' => 'null', 'source_table' => 'usr_users'],
		'srl_stopped_by' => ['action' => 'null', 'source_table' => 'usr_users'],
	];

	/** The ordered steps as an array. */
	public function steps(): array {
		$steps = $this->get('srl_steps');
		if (is_string($steps)) {
			$steps = json_decode($steps, true);
		}
		return is_array($steps) ? array_values($steps) : array();
	}

	public function set_steps(array $steps): void {
		$this->set('srl_steps', array_values($steps));
	}

	public function is_running(): bool {
		return (string)$this->get('srl_status') === self::STATUS_RUNNING;
	}

	/** The rollout in progress, or null. There is at most one. */
	public static function running(): ?StagedRollout {
		foreach (new MultiStagedRollout(array('status' => self::STATUS_RUNNING, 'deleted' => false),
				array('srl_staged_rollout_id' => 'DESC'), 1) as $r) {
			return $r;
		}
		return null;
	}

	public function save($debug = false) {
		$this->set('srl_update_time', gmdate('Y-m-d H:i:s'));
		return parent::save($debug);
	}
}

class MultiStagedRollout extends SystemMultiBase {
	protected static $model_class = 'StagedRollout';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];
		if (isset($this->options['status'])) {
			$filters['srl_status'] = [$this->options['status'], PDO::PARAM_STR];
		}
		return $this->_get_resultsv2('srl_staged_rollouts', $filters, $this->order_by, $only_count, $debug);
	}
}
