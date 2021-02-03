<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FieldConstraints.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SingleRowAccessor.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Validator.php');



class EmailTemplateStoreException extends SystemClassException {}

class EmailTemplateStore extends SystemBase {

	const TEMPLATE_TYPE_OUTER = 1;
	const TEMPLATE_TYPE_INNER = 2;
	const TEMPLATE_TYPE_FOOTER = 3;

	public static $fields = array(
		'emt_email_template_id' => 'ID of the email_template',
		'emt_name' => 'Name',
		'emt_type' => 'Type of template - outer, inner, footer',
		'emt_body' => 'Body of the template',
		'emt_create_time' => 'Created',
		'emt_update_time' => 'Updated',
		'emt_delete_time' => 'Is this email_template deleted?',
	);
	
	public static $constants = array();

	public static $required = array(
		'emt_name');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $default_values = array(
		'emt_create_time' => 'now()', 
		'emt_update_time' => 'now()'
		);		
	
	
	private function _check_for_duplicate_email_template() {
		$count = new MultiEmailTemplateStore(array(
			'email_template_name' => $this->get('emt_name'),
		));
		
		if ($count->count_all() > 0) {
			$count->load();
			return $count->get(0);
		}
		return NULL;
	}		
	

	function prepare() {
		if ($this->data === NULL) {
			throw new EmailTemplateStoreException('This has no data.');
		}
		
		//CHECK FOR DUPLICATES
		if(!$this->key){
			if($this->_check_for_duplicate_email_template()){
				throw new EmailTemplateStoreException(
				'This email_template already exists');
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
		$this->data = SingleRowFetch('emt_email_templates', 'emt_email_template_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($this->data === NULL) {
			throw new EmailTemplateStoreException(
				'This email_template does not exist');
		}
	}
	
	
	function authenticate_write($session, $other_data=NULL) {
		$current_user = $session->get_user_id();
		if ($session->get_permission() < 10) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this email_template.');
		}
	}

	function save() {
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('emt_email_template_id' => $this->key);
			// Editing an existing record
			
			//SAVE THE OLD VERSION IN THE CONTENT_VERSION TABLE
			ContentVersion::NewVersion(ContentVersion::TYPE_EMAIL_TEMPLATE, $this->key, $this->get('emt_body'), $this->get('emt_name'), $this->get('emt_name'));
		} else {
			$p_keys = NULL;
			// Creating a new record
			unset($rowdata['emt_email_template_id']);
			//$rowdata['emt_create_time'] = 'now()';
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, 'emt_email_templates', $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['emt_email_template_id'];
	}


	function soft_delete(){
		$this->set('emt_delete_time', 'now()');
		$this->save();
		return true;
	}
	
	function undelete(){
		$this->set('emt_delete_time', NULL);
		$this->save();	
		return true;
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

		$sql = 'DELETE FROM emt_email_templates WHERE emt_email_template_id=:emt_email_template_id';
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':emt_email_template_id', $this->key, PDO::PARAM_INT);
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
				CREATE SEQUENCE IF NOT EXISTS emt_email_templates_emt_email_template_id_seq
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
			CREATE TABLE IF NOT EXISTS "public"."emt_email_templates" (
			  "emt_email_template_id" int4 NOT NULL DEFAULT nextval(\'emt_email_templates_emt_email_template_id_seq\'::regclass),
			  "emt_name" varchar(100) COLLATE "pg_catalog"."default" NOT NULL,
			  "emt_type" int2,
			  "emt_body" text COLLATE "pg_catalog"."default",
			  "emt_usr_user_id_created" int4,
			  "emt_create_time" timestamp(6) NOT NULL,
			  "emt_update_time" timestamp(6),
			  "emt_delete_time" timestamp(6)
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."emt_email_templates" ADD CONSTRAINT "emt_email_templates_pkey" PRIMARY KEY ("emt_email_template_id");';
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

class MultiEmailTemplateStore extends SystemMultiBase {

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $entry) {
			$option_display = $entry->get('emt_name'); 
			$items[$option_display] = $entry->get('emt_name');
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	private function _get_results($only_count=FALSE) { 
		$where_clauses = array();
		$bind_params = array();
		
		if (array_key_exists('email_template_id', $this->options)) {
			$where_clauses[] = 'emt_email_template_id = ?';
			$bind_params[] = array($this->options['email_template_id'], PDO::PARAM_INT);
		}		

		if (array_key_exists('email_template_name', $this->options)) {
			$where_clauses[] = 'emt_name = ?';
			$bind_params[] = array($this->options['email_template_name'], PDO::PARAM_STR);
		}	
	
		if (array_key_exists('template_type', $this->options)) {
			$where_clauses[] = 'emt_type = ?';
			$bind_params[] = array($this->options['template_type'], PDO::PARAM_STR);
		}
	
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM emt_email_templates ' . $where_clause;
		} else {
			$sql = 'SELECT * FROM emt_email_templates
				' . $where_clause . '
				ORDER BY ';
				
			if (!$this->order_by) {
				$sql .= " emt_email_template_id ASC ";
			}
			else {
				if (array_key_exists('email_template_id', $this->order_by)) {
					$sql .= ' emt_email_template_id ' . $this->order_by['email_template_id'];
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
			$child = new EmailTemplateStore($row->emt_email_template_id);
			$child->load_from_data($row, array_keys(EmailTemplateStore::$fields));
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
