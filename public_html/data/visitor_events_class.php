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

class VisitorEventException extends SystemClassException {}

class VisitorEvent extends SystemBase {
	public static $prefix = 'vse';
	public static $tablename = 'vse_visitor_events';
	public static $pkey_column = 'vse_visitor_event_id';
	public static $permanent_delete_actions = array(
		'vse_visitor_event_id' => 'delete',		
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	

	public static $fields = array(
		'vse_visitor_event_id' => 'ID of the visitor_event',
		'vse_visitor_id' => 'Visitor id',
		'vse_usr_user_id' => 'The user id',
		'vse_type' => 'Type of record',
		'vse_ip' => 'User ip',
		'vse_page' => 'The page',
		'vse_referrer' => 'Referring site',
		'vse_source' => 'For tracking',
		'vse_campaign' => 'For tracking',
		'vse_timestamp' => 'Timestamp',
		'vse_medium' => 'For tracking',
		'vse_content' => 'For tracking',
		'vse_is_404' => 'Is this a 404?',
	);

	public static $field_specifications = array(
		'vse_visitor_event_id' => array('type'=>'int8', 'serial'=>true),
		'vse_visitor_id' => array('type'=>'varchar(20)'),
		'vse_usr_user_id' => array('type'=>'int4'),
		'vse_type' => array('type'=>'int2'),
		'vse_ip' => array('type'=>'varchar(64)'),
		'vse_page' => array('type'=>'varchar(255)'),
		'vse_referrer' => array('type'=>'varchar(255)'),
		'vse_source' => array('type'=>'varchar(255)'),
		'vse_campaign' => array('type'=>'varchar(255)'),
		'vse_timestamp' => array('type'=>'timestamp(6)'),
		'vse_medium' => array('type'=>'varchar(255)'),
		'vse_content' => array('type'=>'varchar(255)'),
		'vse_is_404' => array('type'=>'bool'),
	);

	public static $required_fields = array();
	
	public static $field_constraints = array();
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array('vse_timestamp' => 'now()');
	

	
}

class MultiVisitorEvent extends SystemMultiBase {

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('code', $this->options)) {
		 	$where_clauses[] = 'vse_code = ?';
		 	$bind_params[] = array($this->options['code'], PDO::PARAM_STR);
		} 
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM vse_visitor_events ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM vse_visitor_events
				' . $where_clause . '
				ORDER BY ';

			if (!$this->order_by) {
				$sql .= " vse_visitor_event_id ASC ";
			}
			else {
				if (array_key_exists('visitor_event_id', $this->order_by)) {
					$sql .= ' vse_visitor_event_id ' . $this->order_by['visitor_event_id'];
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
			$child = new VisitorEvent($row->vse_visitor_event_id);
			$child->load_from_data($row, array_keys(VisitorEvent::$fields));
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