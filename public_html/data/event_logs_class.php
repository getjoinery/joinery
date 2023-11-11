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

class EventLogException extends SystemClassException {}

class EventLog extends SystemBase {
	public static $prefix = 'evl';
	public static $tablename = 'evl_event_logs';
	public static $pkey_column = 'evl_event_log_id';
	public static $permanent_delete_actions = array(
		'evl_event_log_id' => 'delete',		
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	

	public static $fields = array(
		'evl_event_log_id' => 'ID of the event_log',
		'evl_event' => 'see above',
		'evl_usr_user_id' => 'User this event_log is associated with',
		'evl_create_time' => 'Time added',
		'evl_was_success' => 'Did it run to completion?',
		'evl_note' => 'Any notes'
	);

	public static $field_specifications = array(
		'evl_event_log_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'evl_event' => array('type'=>'varchar(255)'),
		'evl_usr_user_id' => array('type'=>'int4'),
		'evl_create_time' => array('type'=>'timestamp(6)'),
		'evl_was_success' => array('type'=>'bool'),
		'evl_note' => array('type'=>'varchar(255)'),
	);
			
	public static $required_fields = array();
	
	public static $field_constraints = array();
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
	'evl_create_time'=> 'now()',);
	

	
}

class MultiEventLog extends SystemMultiBase {

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('user_id', $this->options)) {
		 	$where_clauses[] = 'evl_usr_user_id = ?';
		 	$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		} 

		if (array_key_exists('event', $this->options)) {
		 	$where_clauses[] = 'evl_event = ?';
		 	$bind_params[] = array($this->options['event'], PDO::PARAM_STR);
		}
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM evl_event_logs ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM evl_event_logs
				' . $where_clause . '
				ORDER BY ';

			if (empty($this->order_by)) {
				$sql .= " evl_event_log_id ASC ";
			}
			else {
				if (array_key_exists('event_log_id', $this->order_by)) {
					$sql .= ' evl_event_log_id ' . $this->order_by['event_log_id'];
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
		parent::load();
		$q = $this->_get_results(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new EventLog($row->evl_event_log_id);
			$child->load_from_data($row, array_keys(EventLog::$fields));
			$this->add($child);
		}
	}

}


?>