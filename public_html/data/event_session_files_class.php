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

class EventSessionFileException extends SystemClassException {}

class EventSessionFile extends SystemBase {
	public static $prefix = 'esf';
	public static $tablename = 'esf_event_session_files';
	public static $pkey_column = 'esf_event_session_file_id';
	public static $permanent_delete_actions = array(
		'esf_event_session_file_id' => 'delete',		
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	

	public static $fields = array(
		'esf_event_session_file_id' => 'ID of the event_session_file',
		'esf_evs_event_session_id' => 'see above',
		'esf_fil_file_id' => 'User this event_session_file is associated with',
	);

	public static $field_specifications = array(
		'esf_event_session_file_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'esf_evs_event_session_id' => array('type'=>'int4'),
		'esf_fil_file_id' => array('type'=>'int4'),
	);
			
	public static $required_fields = array('esf_evs_event_session_id', 'esf_fil_file_id');
	
	public static $field_constraints = array();
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array();
	

	
}

class MultiEventSessionFile extends SystemMultiBase {

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('file_id', $this->options)) {
		 	$where_clauses[] = 'esf_fil_file_id = ?';
		 	$bind_params[] = array($this->options['file_id'], PDO::PARAM_INT);
		} 
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM esf_event_session_files ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM esf_event_session_files
				' . $where_clause . '
				ORDER BY ';

			if (!$this->order_by) {
				$sql .= " esf_event_session_file_id ASC ";
			}
			else {
				if (array_key_exists('event_session_file_id', $this->order_by)) {
					$sql .= ' esf_event_session_file_id ' . $this->order_by['event_session_file_id'];
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
		$q = $this->_get_results();
		foreach($q->fetchAll() as $row) {
			$child = new EventSessionFile($row->esf_event_session_file_id);
			$child->load_from_data($row, array_keys(EventSessionFile::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->_get_results(TRUE);
		$counter = $q->fetch();
		return $counter->count;
	}

}


?>