<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FieldConstraints.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SingleRowAccessor.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Validator.php');



class SettingException extends SystemClassException {}

class Setting extends SystemBase {


	public static $fields = array(
		'stg_setting_id' => 'ID of the setting',
		'stg_name' => 'Name',
		'stg_value' => 'Value of the setting',
		'stg_group_name' => 'String to group settings into bundles',
		'stg_usr_user_id' => 'User who created/updated last',
		'stg_create_time' => 'Created',
		'stg_update_time' => 'Updated',
	);
	
	public static $constants = array();

	public static $required = array(
		'stg_name');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $default_values = array(
		'stg_create_time' => 'now()', 
		'stg_update_time' => 'now()'
		);		
	
	private function _check_for_duplicate_setting() {
		
		$settings = Globalvars::get_instance();
		if($settings->get_setting($this->get('stg_name'))){
			return true;
		}
		
		$count = new MultiSetting(array(
			'setting_name' => $this->get('stg_name'),
		));
		
		if ($count->count_all() > 0) {
						echo 'duplicate';
			exit();
			$count->load();
			return $count->get(0);
		}
		return NULL;
	}		
	

	function prepare() {
		if ($this->data === NULL) {
			throw new SettingException('This has no data.');
		}
		
		//CHECK FOR DUPLICATES
		if(!$this->key){
			if($this->_check_for_duplicate_setting()){
				throw new SettingException(
				'This setting already exists');
			}
		}

		if ($this->key === NULL) {
			foreach (static::$zero_variables as $variable) {
				if ($this->key === NULL && $this->get($variable) === NULL) {
					echo $variable;
					$this->set($variable, 0);
				}
			}

		}
		
		if ($this->key === NULL) {
			foreach (static::$default_values as $variable=>$value) {
				if ($this->key === NULL && $this->get($variable) === NULL) { 
					$this->set($variable, $value);
				}
			}
		}		

		CheckRequiredFields($this, self::$required, self::$fields);

		foreach (self::$field_constraints as $field => $constraints) {
			foreach($constraints as $constraint) {
				if (gettype($constraint) == 'array') {
					$params = array();
					$params[] = self::$fields[$field];
					$params[] = $this->get($field);
					for($i=1;$i<count($constraint);$i++) {
						$params[] = $constraint[$i];
					}
					call_user_func_array($constraint[0], $params);
				} else {
					call_user_func($constraint, self::$fields[$field], $this->get($field));
				}
			}
		}

	}

	function load() {
		parent::load();
		$this->data = SingleRowFetch('stg_settings', 'stg_setting_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($this->data === NULL) {
			throw new SettingException(
				'This setting does not exist');
		}
	}
	
	
	function authenticate_write($session, $other_data=NULL) {
		$current_user = $session->get_user_id();
		if ($session->get_permission() < 10) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this setting.');
		}
	}

	function save() {
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('stg_setting_id' => $this->key);
			// Editing an existing record
		} else {
			$p_keys = NULL;
			// Creating a new record
			unset($rowdata['stg_setting_id']);
			//$rowdata['stg_create_time'] = 'now()';
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, 'stg_settings', $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['stg_setting_id'];
	}

	
	function permanent_delete(){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$this_transaction = false;
		/*
		if(!$dblink->inTransaction()){
			$dblink->beginTransaction();
			$this_transaction = true;
		}
		*/

		$sql = 'DELETE FROM stg_settings WHERE stg_setting_id=:stg_setting_id';
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':stg_setting_id', $this->key, PDO::PARAM_INT);
			$count = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}
		
		if($this_transaction){
			$dblink->commit();
		}
		
		$this->key = NULL;
		
		return true;		
	}
	

	static function InitDB($mode='structure'){
	
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS stg_settings_stg_setting_id_seq
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
			CREATE TABLE IF NOT EXISTS "public"."stg_settings" (
			  "stg_setting_id" int4 NOT NULL DEFAULT nextval(\'stg_settings_stg_setting_id_seq\'::regclass),
			  "stg_name" varchar(100) COLLATE "pg_catalog"."default" NOT NULL,
			  "stg_value" text COLLATE "pg_catalog"."default" NOT NULL,
			  "stg_group_name" varchar(100) COLLATE "pg_catalog"."default" NOT NULL,
			  "stg_usr_user_id" int4,
			  "stg_create_time" timestamp(6) NOT NULL,
			  "stg_update_time" timestamp(6),
			  "stg_delete_time" timestamp(6)
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."stg_settings" ADD CONSTRAINT "stg_settings_pkey" PRIMARY KEY ("stg_setting_id");';
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

class MultiSetting extends SystemMultiBase {

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $entry) {
			$option_display = $entry->get('stg_name'); 
			$items[$option_display] = $entry->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	private function _get_results($only_count=FALSE) { 
		$where_clauses = array();
		$bind_params = array();
		
		if (array_key_exists('setting_id', $this->options)) {
			$where_clauses[] = 'stg_setting_id = ?';
			$bind_params[] = array($this->options['setting_id'], PDO::PARAM_INT);
		}		

		if (array_key_exists('setting_name', $this->options)) {
			$where_clauses[] = 'stg_name = ?';
			$bind_params[] = array($this->options['setting_name'], PDO::PARAM_STR);
		}			
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM stg_settings ' . $where_clause;
		} else {
			$sql = 'SELECT * FROM stg_settings
				' . $where_clause . '
				ORDER BY ';
				
			if (!$this->order_by) {
				$sql .= " stg_setting_id ASC ";
			}
			else {
				if (array_key_exists('setting_id', $this->order_by)) {
					$sql .= ' stg_setting_id ' . $this->order_by['setting_id'];
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
			$child = new Setting($row->stg_setting_id);
			$child->load_from_data($row, array_keys(Setting::$fields));
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
