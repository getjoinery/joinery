<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');

class GeneralErrorException extends SystemClassException {}

class GeneralError extends SystemBase {
	public static $prefix = 'err';
	public static $tablename = 'err_general_errors';
	public static $pkey_column = 'err_general_error_id';
	public static $permanent_delete_actions = array(	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(		'err_error' => 'error',
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

	/**
	 * Field specifications define database column properties and schema constraints
	 * Available options:
	 *   'type' => 'varchar(255)' | 'int4' | 'int8' | 'text' | 'timestamp(6)' | 'numeric(10,2)' | 'bool' | etc.
	 *   'serial' => true/false - Auto-incrementing field
	 *   'is_nullable' => true/false - Whether NULL values are allowed
	 *   'unique' => true - Field must be unique (single field constraint)
	 *   'unique_with' => array('field1', 'field2') - Composite unique constraint with other fields
	 */
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

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
	
		$error_context = $e->getTraceAsString(). "\r\n \r\n REQUEST_URI: ". $_SERVER['REQUEST_URI']. "\r\n \r\n $_SESSION: " . print_r($session, true). ' $_REQUEST: '.print_r($request, true);
	

		$error = new GeneralError(NULL);
		if ($e instanceof PDOException) {
			$error->set('err_level', 'Database Error');
			$error_context .= 'POSTGRES DEBUG INFO:';;
			if(count($dbhelper->query_history)){
				$error_context .= print_r($dbhelper->query_history, true);
			}
			if(count($dbhelper->last_query_params)){
				$error_context .= print_r($dbhelper->last_query_params, true);
			}

		}
		else{
			$error->set('err_level', 'Exception');
		}

		$error_context = '<pre>'.htmlentities($error_context).'</pre>';
		
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

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['user_id'])) {
			$filters['err_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		}

		return $this->_get_resultsv2('err_general_errors', $filters, $this->order_by, $only_count, $debug);
	}

	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new GeneralError($row->err_general_error_id);
			$child->load_from_data($row, array_keys(GeneralError::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}

}


?>
