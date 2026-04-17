<?php
/**
 * AgentHeartbeat - Tracks agent liveness for the server manager.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class AgentHeartbeat extends SystemBase {
	public static $prefix = 'ahb';
	public static $tablename = 'ahb_agent_heartbeats';
	public static $pkey_column = 'ahb_id';

	public static $field_specifications = array(
		'ahb_id'              => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'ahb_agent_name'      => array('type'=>'varchar(100)', 'is_nullable'=>false, 'unique'=>true),
		'ahb_last_heartbeat'  => array('type'=>'timestamp(6)'),
		'ahb_agent_version'   => array('type'=>'varchar(20)'),
		'ahb_status'          => array('type'=>'varchar(20)'),
		'ahb_create_time'     => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'ahb_update_time'     => array('type'=>'timestamp(6)'),
	);

	/**
	 * Check if agent is online (heartbeat within last 60 seconds).
	 */
	function is_online() {
		$last = $this->get('ahb_last_heartbeat');
		if (!$last) return false;
		$now = gmdate('Y-m-d H:i:s');
		$diff = strtotime($now) - strtotime($last);
		return $diff < 60;
	}

	/**
	 * Get the most recent agent heartbeat.
	 */
	static function getLatest() {
		$agents = new MultiAgentHeartbeat(array(), array('ahb_last_heartbeat' => 'DESC'), 1);
		$agents->load();
		if (count($agents) > 0) {
			return $agents->get(0);
		}
		return null;
	}
}

class MultiAgentHeartbeat extends SystemMultiBase {
	protected static $model_class = 'AgentHeartbeat';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['agent_name'])) {
			$filters['ahb_agent_name'] = [$this->options['agent_name'], PDO::PARAM_STR];
		}

		return $this->_get_resultsv2('ahb_agent_heartbeats', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
