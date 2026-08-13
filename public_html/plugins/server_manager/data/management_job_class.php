<?php
/**
 * ManagementJob - A queued, running, or completed server management operation.
 *
 * @version 1.5 - run_command joins filterTypes (node console)
 * @version 1.4
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class ManagementJobException extends SystemBaseException {}

class ManagementJob extends SystemBase {
	public static $prefix = 'mjb';
	public static $tablename = 'mjb_management_jobs';
	public static $pkey_column = 'mjb_id';

	public static $json_vars = array('mjb_commands', 'mjb_parameters', 'mjb_result');

	public static $field_specifications = array(
		'mjb_id'                => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'mjb_mgn_node_id'      => array('type'=>'int8'),
		'mjb_job_type'          => array('type'=>'varchar(50)', 'required'=>true, 'is_nullable'=>false),
		'mjb_status'            => array('type'=>'varchar(20)', 'is_nullable'=>false, 'default'=>'pending'),
		'mjb_commands'          => array('type'=>'jsonb', 'is_nullable'=>false),
		'mjb_parameters'        => array('type'=>'jsonb'),
		'mjb_output'            => array('type'=>'text'),
		'mjb_result'            => array('type'=>'jsonb'),
		'mjb_current_step'      => array('type'=>'int4', 'default'=>'0'),
		'mjb_total_steps'       => array('type'=>'int4'),
		'mjb_error_message'     => array('type'=>'text'),
		'mjb_external_order_item_id' => array('type'=>'int8'),
		'mjb_created_by'        => array('type'=>'int8'),
		'mjb_started_time'      => array('type'=>'timestamp(6)'),
		'mjb_completed_time'    => array('type'=>'timestamp(6)'),
		'mjb_create_time'       => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'mjb_update_time'       => array('type'=>'timestamp(6)'),
		'mjb_delete_time'       => array('type'=>'timestamp(6)'),
	);

	protected static $foreign_key_actions = [
		'mjb_mgn_node_id' => ['action' => 'null'],
		'mjb_created_by'  => ['action' => 'null', 'source_table' => 'usr_users'],
	];

	/**
	 * Create a new job from a command builder result.
	 */
	static function createJob($node_id, $job_type, $steps, $parameters, $created_by) {
		$job = new ManagementJob(NULL);
		$job->set('mjb_mgn_node_id', $node_id);
		$job->set('mjb_job_type', $job_type);
		$job->set('mjb_status', 'pending');
		$job->set('mjb_commands', json_encode(['steps' => $steps]));
		$job->set('mjb_parameters', $parameters ? json_encode($parameters) : null);
		// Progress counts the main phase only: teardown appends never advance
		// mjb_current_step, so counting teardown steps would leave every job
		// looking short of its total.
		$main_steps = array_filter($steps, function ($s) { return empty($s['teardown']); });
		$job->set('mjb_total_steps', count($main_steps));
		$job->set('mjb_current_step', 0);
		$job->set('mjb_created_by', $created_by);
		$job->save();
		return $job;
	}

	function prepare() {
		if (empty($this->get('mjb_job_type'))) {
			throw new ManagementJobException('Job type is required.');
		}
		$this->set('mjb_update_time', gmdate('Y-m-d H:i:s'));
	}

	/**
	 * Canonical job-type list for the filter dropdowns. One source so the node
	 * detail Jobs tab and the all-jobs page cannot drift apart (U-5). The
	 * all-jobs page also offers publish_upgrade (which is not node-scoped).
	 *
	 * @param bool $include_publish Append 'publish_upgrade' (all-jobs page).
	 * @return string[]
	 */
	static function filterTypes($include_publish = false) {
		$types = [
			'check_status', 'backup_database', 'backup_project',
			'copy_database', 'copy_database_local', 'restore_database',
			'restore_project', 'restore_chain', 'apply_update', 'decommission_node',
			'backup_run', 'run_command',
		];
		if ($include_publish) {
			$types[] = 'publish_upgrade';
		}
		return $types;
	}

	/**
	 * The subset of job types that count as "database operations" — used to
	 * filter the Recent Database Operations table. Derived from filterTypes()
	 * so it stays a genuine subset of the canonical list (U-5).
	 *
	 * @return string[]
	 */
	static function databaseOpTypes() {
		$db_ops = ['copy_database', 'copy_database_local', 'restore_database'];
		return array_values(array_intersect(self::filterTypes(), $db_ops));
	}

	/**
	 * The newest non-deleted job of a given type for a node, or null if none.
	 *
	 * Replaces the copy-pasted "SELECT mjb_id ... ORDER BY mjb_id DESC LIMIT 1"
	 * query the node detail page repeated for install banners, SSL status, the
	 * last check, backup-scan state, and the install-retry param carry-forward.
	 * Returns a fully loaded model so callers can read whatever field they need
	 * (->key for a link, ->get('mjb_status'), ->get('mjb_parameters'), ...).
	 *
	 * @param int    $node_id
	 * @param string $type
	 * @return ManagementJob|null
	 */
	static function latestForNode($node_id, $type) {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT mjb_id FROM mjb_management_jobs
			 WHERE mjb_mgn_node_id = ? AND mjb_job_type = ? AND mjb_delete_time IS NULL
			 ORDER BY mjb_id DESC LIMIT 1"
		);
		$q->execute([(int)$node_id, (string)$type]);
		$row = $q->fetch(PDO::FETCH_ASSOC);
		if (!$row) {
			return null;
		}
		return new ManagementJob($row['mjb_id'], TRUE);
	}
}

class MultiManagementJob extends SystemMultiBase {
	protected static $model_class = 'ManagementJob';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['node_id'])) {
			$filters['mjb_mgn_node_id'] = [$this->options['node_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['job_type'])) {
			$filters['mjb_job_type'] = [$this->options['job_type'], PDO::PARAM_STR];
		}

		if (isset($this->options['status'])) {
			$filters['mjb_status'] = [$this->options['status'], PDO::PARAM_STR];
		}

		if (isset($this->options['external_order_item_id'])) {
			$filters['mjb_external_order_item_id'] = [$this->options['external_order_item_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['created_by'])) {
			$filters['mjb_created_by'] = [$this->options['created_by'], PDO::PARAM_INT];
		}


		return $this->_get_resultsv2('mjb_management_jobs', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
