<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FieldConstraints.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SingleRowAccessor.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Validator.php');

class EventLogException extends SystemClassException {}

class EventLog extends SystemBase {

	const SHOW_PHONE = 1;
	const ACCESS_SERVICE_FLYER = 2;


	public static $valid_events = array(
		self::SHOW_PHONE,
		self::ACCESS_SERVICE_FLYER,
	);
	
	public static $event_descriptions = array(
		self::SURVEY_COMPLETED => 'Survey completion',
	);	

	public static $fields = array(
		'evl_event_log_id' => 'ID of the event_log',
		'evl_event' => 'see above',
		'evl_usr_user_id' => 'User this event_log is associated with',
		'evl_create_time' => 'Time added',
	);


	//DEPRECATED
	static function StoreEventLog($event, $user_id, $event_id=NULL) {
		$event_log = new EventLog(NULL);
		$event_log->set('evl_event', $event);
		$event_log->set('evl_usr_user_id', $user_id);
		$event_log->save();
	}

	function load() {
		parent::load();
		$this->data = SingleRowFetch('evl_event_logs', 'evl_event_log_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($this->data === NULL) {
			throw new EventLogException(
				'This event_log does not exist');
		}
	}
	
	function save() {
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('evl_event_log_id' => $this->key);
			// Editing an existing record
		} else {
			$p_keys = NULL;
			// Creating a new record
			unset($rowdata['evl_event_log_id']);
			$rowdata['evl_create_time'] = 'now()';
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, 'evl_event_logs', $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['evl_event_log_id'];
	}
	
	static function InitDB($mode='structure'){
	
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS evl_events_log_evl_events_log_id_seq
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
			CREATE TABLE IF NOT EXISTS "public"."evl_event_logs" (
			  "evl_event_log_id" int4 NOT NULL DEFAULT nextval(\'evl_events_log_evl_events_log_id_seq\'::regclass),
			  "evl_event" int2,
			  "evl_usr_user_id" int4,
			  "evl_create_time" timestamp(6) NOT NULL DEFAULT now()
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."evl_event_logs" ADD CONSTRAINT "evl_event_logs_pkey" PRIMARY KEY ("evl_event_log_id");';
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

class MultiEventLog extends SystemMultiBase {

	private function _get_results($only_count=FALSE) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('user_id', $this->options)) {
		 	$where_clauses[] = 'evl_usr_user_id = ?';
		 	$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		} 

		if (array_key_exists('event', $this->options)) {
		 	$where_clauses[] = 'evl_event = ?';
		 	$bind_params[] = array($this->options['event'], PDO::PARAM_INT);
		}
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM evl_event_logs ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM evl_event_logs
				' . $where_clause . '
				ORDER BY ';

			if (!$this->order_by) {
				$sql .= " evl_event_log_id ASC ";
			}
			else {
				if (array_key_exists('event_log_id', $this->order_by)) {
					$sql .= ' evl_event_log_id ' . $this->order_by['event_log_id'];
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
			$child = new EventLog($row->evl_event_log_id);
			$child->load_from_data($row, array_keys(EventLog::$fields));
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