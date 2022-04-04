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

class FormErrorException extends SystemClassException {}

class FormError extends SystemBase {

	public static $fields = array(
		'lfe_log_form_error_id' => 'ID of the lfe_log_form_error',
		'lfe_error' => 'error',
		'lfe_usr_user_id' => 'User this lfe_log_form_error is associated with',
		'lfe_log_time' => 'Time added',
		'lfe_user_agent' => 'User Agent string',
		'lfe_page' => 'The page this log form error occured on',
		'lfe_url' => 'The URL of the page this happened on',
		'lfe_form' => 'The full form',
		'lfe_context' => 'The DOM selector form the form (in case more than one form on the page)',
	);

	public static $required_fields = array();
	
	public static $field_constraints = array();
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
	'lfe_log_time'=> 'now()',);
	
	public static $public_actions = array(
		'logformerror' => array(
			'messages' => TRUE,
			'page' => TRUE,
			'url' => TRUE,
			'formfields' => TRUE,
			'context' => TRUE,
		)
	);

	function load($debug = false) {
		parent::load();
		$this->data = SingleRowFetch('lfe_log_form_errors', 'lfe_log_form_error_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($this->data === NULL) {
			throw new FormErrorException(
				'This lfe_log_form_error does not exist');
		}
	}

	function save() {
		parent::save();
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('lfe_log_form_error_id' => $this->key);
			// Editing an existing record
		} else {
			$p_keys = NULL;
			// Creating a new record
			unset($rowdata['lfe_log_form_error_id']);
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, 'lfe_log_form_errors', $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['lfe_log_form_error_id'];
	}
	
	function display_time($session) {
		return LibraryFunctions::convert_time(
			$this->get('lfe_log_time'), 'UTC', $session->get_timezone(), '%a, %d %b %Y %R:%S');
	}	

	public static function LogFormError($session, $request) { 
		$obj = new FormError(NULL);
		$obj->set('lfe_usr_user_id', $session->get_user_id());
		$obj->set('lfe_error', $request['messages']);
		$obj->set('lfe_log_time', 'NOW()');
		$obj->set('lfe_page', $request['page']);
		$obj->set('lfe_url', $request['url']);
		$obj->set('lfe_form', $request['formfields']);
		$obj->set('lfe_context', $request['context']);
		$obj->set('lfe_user_agent', $_SERVER['HTTP_USER_AGENT']);
		$obj->save();
	}

	public static function GetPublicActions() { 
		return self::$public_actions;
	}
	
	static function InitDB($mode='structure'){
	
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS lfe_log_form_errors_lfe_log_form_error_id_seq
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
			CREATE TABLE IF NOT EXISTS "public"."lfe_log_form_errors" (
			  "lfe_log_form_error_id" int4 NOT NULL DEFAULT nextval(\'lfe_log_form_errors_lfe_log_form_error_id_seq\'::regclass),
			  "lfe_usr_user_id" int4,
			  "lfe_error" text COLLATE "pg_catalog"."default",
			  "lfe_log_time" timestamp(6) NOT NULL DEFAULT now(),
			  "lfe_page" varchar(100) COLLATE "pg_catalog"."default",
			  "lfe_form" text COLLATE "pg_catalog"."default",
			  "lfe_url" varchar(1000) COLLATE "pg_catalog"."default",
			  "lfe_context" varchar(200) COLLATE "pg_catalog"."default",
			  "lfe_user_agent" varchar(1000) COLLATE "pg_catalog"."default"
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."lfe_log_form_errors" ADD CONSTRAINT "lfe_log_form_errors_pkey" PRIMARY KEY ("lfe_log_form_error_id");';
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

class MultiFormError extends SystemMultiBase {

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (isset($this->options['user_id'])) {
		 	$where_clauses[] = 'lfe_usr_user_id = ?';
		 	$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		} 
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM lfe_log_form_errors ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM lfe_log_form_errors
				' . $where_clause . '
				ORDER BY ';

			if (!$this->order_by) {
				$sql .= " lfe_log_form_error_id ASC ";
			}
			else {
				if (array_key_exists('log_form_error_id', $this->order_by)) {
					$sql .= ' lfe_log_form_error_id ' . $this->order_by['log_form_error_id'];
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
			$child = new FormError($row->lfe_log_form_error_id);
			$child->load_from_data($row, array_keys(FormError::$fields));
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
