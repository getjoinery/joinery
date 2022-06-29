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

class GeneralErrorException extends SystemClassException {}

class GeneralError extends SystemBase {
	public static $prefix = 'err';
	public static $tablename = 'err_general_errors';
	public static $pkey_column = 'err_general_error_id';
	public static $permanent_delete_actions = array(
		'err_general_error_id' => 'delete',	
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'err_general_error_id' => 'ID of the err_general_error',
		'err_error' => 'error',
		'err_code' => 'error',
		'err_usr_user_id' => 'User this err_general_error is associated with',
		'err_description' => 'Time added',
		'err_file' => 'User Agent string',
		'err_line' => 'The page this log form error occured on',
		'err_context' => 'The URL of the page this happened on',
		'err_path' => 'The full form',
		'err_message' => 'The DOM selector form the form (in case more than one form on the page)',
		'err_level' => '',
		'err_create_time' => '',
	);

	public static $field_specifications = array(
		'err_general_error_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'err_error' => array('type'=>'varchar(255)'),
		'err_code' => array('type'=>'varchar(32)'),
		'err_usr_user_id' => array('type'=>'int4'),
		'err_description' => array('type'=>'varchar(255)'),
		'err_file' => array('type'=>'varchar(255)'),
		'err_line' => array('type'=>'varchar(32)'),
		'err_context' => array('type'=>'text'),
		'err_path' => array('type'=>'varchar(255)'),
		'err_message' => array('type'=>'varchar(255)'),
		'err_level' => array('type'=>'varchar(255)'),
		'err_create_time' => array('type'=>'timestamp(6)'),
	);
			
	public static $required_fields = array();
	
	public static $field_constraints = array();
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
	'err_create_time'=> 'now()',);

	
	function display_time($session) {
		return LibraryFunctions::convert_time(
			$this->get('err_log_time'), 'UTC', $session->get_timezone(), '%a, %d %b %Y %R:%S');
	}	


	public static function LogGeneralError($e, $session, $request) { 
		$session_obj = SessionControl::get_instance();
	
		$error_context = $e->getTraceAsString(). "\r\n \r\n REQUEST_URI: ". $_SERVER['REQUEST_URI']. "\r\n \r\n $_SESSION: " . print_r($session, true). ' $_REQUEST: '.print_r($request, true);
		$error_context = '<pre>'.htmlentities($error_context).'</pre>';
	

		$error = new GeneralError(NULL);
		if ($e instanceof PDOException) {
			$error->set('err_level', 'Database Error');
		}
		else{
			$error->set('err_level', 'Exception');
		}		
		$error->set('err_code', $e->getCode());
		$error->set('err_file', $e->getFile());
		$error->set('err_line', $e->getLine());
		$error->set('err_context', $error_context);
		$error->set('err_message', $e->getMessage());
		if($session_obj->get_user_id()){
			$error->set('err_usr_user_id', $session_obj->get_user_id());
		}
		$error->save();
	}
		
}

class MultiGeneralError extends SystemMultiBase {

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (isset($this->options['user_id'])) {
		 	$where_clauses[] = 'err_usr_user_id = ?';
		 	$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		} 
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM err_general_errors ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM err_general_errors
				' . $where_clause . '
				ORDER BY ';

			if (!$this->order_by) {
				$sql .= " err_general_error_id ASC ";
			}
			else {
				if (array_key_exists('general_error_id', $this->order_by)) {
					$sql .= ' err_general_error_id ' . $this->order_by['general_error_id'];
				}	
				if (array_key_exists('create_time', $this->order_by)) {
					$sql .= ' err_create_time ' . $this->order_by['create_time'];
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
			$child = new GeneralError($row->err_general_error_id);
			$child->load_from_data($row, array_keys(GeneralError::$fields));
			$this->add($child);
		}
	}

}


?>
