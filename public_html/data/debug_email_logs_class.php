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

class DebugEmailLogException extends SystemClassException {}

class DebugEmailLog extends SystemBase {
	public $prefix = 'del';
	public $tablename = 'del_debug_email_logs';
	public $pkey_column = 'del_debug_email_log_id';
	public static $permanent_delete_actions = array(
		'del_debug_email_log_id' => 'delete',
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'del_debug_email_log_id' => 'ID of the debug_email_log',
		'del_subject' => 'subject of the email',
		'del_recipient_email' => 'recipient email',
		'del_body' => 'Body of the email',
		'del_create_time' => 'Time added',
	);

	public static $required_fields = array();
	
	public static $field_constraints = array();
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
	'del_create_time'=> 'now()',);
	
	
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

	function _get_results($only_count=FALSE, $debug = false) { 
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
			$child = new DebugEmailLog($row->del_debug_email_log_id);
			$child->load_from_data($row, array_keys(DebugEmailLog::$fields));
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