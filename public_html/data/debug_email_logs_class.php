<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FieldConstraints.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SingleRowAccessor.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Validator.php'); 

class DebugEmailLogException extends SystemClassException {}

class DebugEmailLog extends SystemBase {

	public static $fields = array(
		'del_debug_email_log_id' => 'ID of the debug_email_log',
		'del_subject' => 'subject of the email',
		'del_recipient_email' => 'recipient email',
		'del_body' => 'Body of the email',
		'del_create_time' => 'Time added',
	);

	function load() {
		parent::load();
		$this->data = SingleRowFetch('del_debug_email_logs', 'del_debug_email_log_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($this->data === NULL) {
			throw new DebugEmailLogException(
				'This debug_email_log does not exist');
		}
	}
	
	function save() {
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('del_debug_email_log_id' => $this->key);
			// Editing an existing record
		} else {
			$p_keys = NULL;
			// Creating a new record
			unset($rowdata['del_debug_email_log_id']);
			$rowdata['del_create_time'] = 'now()';
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, 'del_debug_email_logs', $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['del_debug_email_log_id'];
	}
	
	static function InitDB($mode='structure'){
	
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS del_debug_email_log_del_debug_email_log_id_seq
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
			CREATE TABLE IF NOT EXISTS "public"."del_debug_email_logs" (
			  "del_debug_email_log_id" int4 NOT NULL DEFAULT nextval(\'del_debug_email_log_del_debug_email_log_id_seq\'::regclass),
			  "del_subject" varchar(255),
			  "del_recipient_email" varchar(255),
			  "del_body" text,
			  "del_create_time" timestamp(6) NOT NULL DEFAULT now()
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."del_debug_email_logs" ADD CONSTRAINT "del_debug_email_logs_pkey" PRIMARY KEY ("del_debug_email_log_id");';
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

class MultiDebugEmailLog extends SystemMultiBase {

	private function _get_results($only_count=FALSE) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('user_id', $this->options)) {
		 	$where_clauses[] = 'del_usr_user_id = ?';
		 	$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		} 

		if (array_key_exists('event', $this->options)) {
		 	$where_clauses[] = 'del_event = ?';
		 	$bind_params[] = array($this->options['event'], PDO::PARAM_INT);
		}
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM del_debug_email_logs ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM del_debug_email_logs
				' . $where_clause . '
				ORDER BY ';

			if (!$this->order_by) {
				$sql .= " del_debug_email_log_id ASC ";
			}
			else {
				if (array_key_exists('debug_email_log_id', $this->order_by)) {
					$sql .= ' del_debug_email_log_id ' . $this->order_by['debug_email_log_id'];
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
			$child = new DebugEmailLog($row->del_debug_email_log_id);
			$child->load_from_data($row, array_keys(DebugEmailLog::$fields));
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