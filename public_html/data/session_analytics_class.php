<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/DbConnector.php');
require_once($siteDir . '/includes/FieldConstraints.php');
require_once($siteDir . '/includes/Globalvars.php');
require_once($siteDir . '/includes/LibraryFunctions.php');
require_once($siteDir . '/includes/SingleRowAccessor.php');
require_once($siteDir . '/includes/SystemClass.php');
require_once($siteDir . '/includes/Validator.php');

class SessionAnalyticException extends SystemClassException {}

class SessionAnalytic extends SystemBase {
	public static $prefix = 'sev';
	public static $tablename = 'sev_session_analytics';
	public static $pkey_column = 'sev_session_analytic_id';
	public static $permanent_delete_actions = array(
		'sev_session_analytic_id' => 'delete',		
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	

	public static $fields = array(
		'sev_session_analytic_id' => 'ID of the session_analytic',
		'sev_usr_user_id' => '',
		'sev_evt_event_id' => '',
		'sev_evs_event_session_id' => '',
		'sev_type' => '',
		'sev_time' => '',
	);

	public static $field_specifications = array(
		'sev_session_analytic_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'sev_usr_user_id' => array('type'=>'int4'),
		'sev_evt_event_id' => array('type'=>'int4'),
		'sev_evs_event_session_id' => array('type'=>'int4'),
		'sev_type' => array('type'=>'int2'),
		'sev_time' => array('type'=>'timestamp(6)'),
	);
			
	public static $required_fields = array();
	
	public static $field_constraints = array();
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array('sev_time' => 'now()');
	

	
}

class MultiSessionAnalytic extends SystemMultiBase {

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('session_id', $this->options)) {
		 	$where_clauses[] = 'sev_evs_event_session_id = ?';
		 	$bind_params[] = array($this->options['session_id'], PDO::PARAM_INT);
		} 
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM sev_session_analytics ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM sev_session_analytics
				' . $where_clause . '
				ORDER BY ';

			if (!$this->order_by) {
				$sql .= " sev_session_analytic_id ASC ";
			}
			else {
				if (array_key_exists('session_analytic_id', $this->order_by)) {
					$sql .= ' sev_session_analytic_id ' . $this->order_by['session_analytic_id'];
				}			
			}
			
			$sql .= ' '.$this->generate_limit_and_offset();	
		}

		$q = DbConnector::GetPreparedStatement($sql);

		if($debug){
			echo $sql. "<br>\n";
			print_r($this->options);
		}

		$total_params = count($bind_params);
		for ($i=0; $i<$total_params; $i++) {
			list($param, $type) = $bind_params[$i];
			$q->bindValue($i+1, $param, $type);
		}
		$q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);

		return $q;
	}

	function load($debug = false) {
		$q = $this->_get_results(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new SessionAnalytic($row->sev_session_analytic_id);
			$child->load_from_data($row, array_keys(SessionAnalytic::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->_get_results(TRUE, $debug);
		$counter = $q->fetch();
		return $counter->count;
	}

}


?>