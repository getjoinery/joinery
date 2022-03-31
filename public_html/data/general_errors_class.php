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

	public static $required_fields = array();
	
	public static $field_constraints = array();
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
	'err_create_time'=> 'now()',);
	
	/*
	public static $public_actions = array(
		'logformerror' => array(
			'messages' => TRUE,
			'page' => TRUE,
			'url' => TRUE,
			'formfields' => TRUE,
			'context' => TRUE,
		)
	);
	*/

	function load() {
		parent::load();
		$this->data = SingleRowFetch('err_general_errors', 'err_general_error_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($this->data === NULL) {
			throw new GeneralErrorException(
				'This err_general_error does not exist');
		}
	}

	function save() {
		parent::save();
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('err_general_error_id' => $this->key);
			// Editing an existing record
		} else {
			$p_keys = NULL;
			// Creating a new record
			unset($rowdata['err_general_error_id']);
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, 'err_general_errors', $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['err_general_error_id'];
	}
	
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
	

	/*
	public static function GetPublicActions() { 
		return self::$public_actions;
	}
	*/
	
	static function InitDB($mode='structure'){
	
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS err_general_errors_err_general_error_id_seq
				INCREMENT BY 1
				NO MAXVALUE
				NO MINVALUE
				CACHE 1;';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}			

		$sql = '
			CREATE TABLE IF NOT EXISTS "public"."err_general_errors" (
			  "err_general_error_id" int4 DEFAULT nextval(\'err_general_errors_err_general_error_id_seq\'::regclass),
			  "err_usr_user_id" int4,
			  "err_create_time" timestamp(6) NOT NULL DEFAULT now(),
			  "err_code" varchar(32) COLLATE "pg_catalog"."default",
			  "err_error" varchar COLLATE "pg_catalog"."default",
			  "err_description" varchar COLLATE "pg_catalog"."default",
			  "err_file" varchar COLLATE "pg_catalog"."default",
			  "err_line" varchar(32) COLLATE "pg_catalog"."default",
			  "err_context" text COLLATE "pg_catalog"."default",
			  "err_path" varchar COLLATE "pg_catalog"."default",
			  "err_message" varchar COLLATE "pg_catalog"."default",
			  "err_level" varchar(255) COLLATE "pg_catalog"."default"
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();

		
		try{		
			$sql = 'ALTER TABLE "public"."err_general_errors" ADD CONSTRAINT "err_general_errors_pkey" PRIMARY KEY ("err_general_error_id");';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}

		//FOR FUTURE
		//ALTER TABLE table_name ADD COLUMN IF NOT EXISTS column_name INTEGER;
	}		
}

class MultiGeneralError extends SystemMultiBase {

	private function _get_results($only_count=FALSE) { 
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

		$total_params = count($bind_params);
		for ($i=0; $i<$total_params; $i++) {
			list($param, $type) = $bind_params[$i];
			$q->bindValue($i+1, $param, $type);
		}
		$q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);

		return $q;
	}

	function load() {
		$q = $this->_get_results();
		foreach($q->fetchAll() as $row) {
			$child = new GeneralError($row->err_general_error_id);
			$child->load_from_data($row, array_keys(GeneralError::$fields));
			$this->add($child);
		}
	}

	function count_all() {
		$q = $this->_get_results(TRUE);
		$counter = $q->fetch();
		return $counter->count;
	}

}


?>
