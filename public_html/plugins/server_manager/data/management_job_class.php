<?php
/**
 * ManagementJob - A queued, running, or completed server management operation.
 *
 * @version 1.1
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
		'mjb_status'            => array('type'=>'varchar(20)', 'is_nullable'=>false, 'default'=>"'pending'"),
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
		'mjb_mgn_node_id' => ['table' => 'mgn_managed_nodes', 'column' => 'mgn_id', 'action' => 'set_null'],
		'mjb_created_by'  => ['table' => 'usr_users', 'column' => 'usr_user_id', 'action' => 'set_null'],
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
		$job->set('mjb_total_steps', count($steps));
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

		if (isset($this->options['deleted'])) {
			$filters['mjb_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('mjb_management_jobs', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
