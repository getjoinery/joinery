<?php
require_once('Globalvars.php');

//class SystemDatabaseException extends PDOException {}

class DbConnector {
	private static $instance;
	private $dblink;
	private $dblink_test;
	private $test_mode;
	private $current_query;
	public $query_history = array();
	public $last_query_params = array();

	private function __construct() {
		$settings = Globalvars::get_instance();
		$this->test_mode = false;

		$this->dblink = new PDO('pgsql:host=localhost port=5432 dbname=' . $settings->get_setting('dbname') . ' user=' . $settings->get_setting('dbusername') . ' password=' . $settings->get_setting('dbpassword'));
		$this->dblink->setAttribute (PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);				

	}

	public static function get_instance() {
		if (!self::$instance instanceof self) {
			self::$instance = new self;
		}
		return(self::$instance);
	}

	// A bunch of helper functions taking advantage of the fact this database connection is a
	// singleton and we are always dealing with the one instance of it
	public static function GetPreparedStatement($sql) {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		return $dblink->prepare($sql);
	}

	public static function BeginTransaction() {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$dblink->beginTransaction();
	}

	public static function Commit() {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$dblink->commit();
	}
	
	public function prepare_query($sql){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();	
		$this->current_query = $dblink->prepare($sql);
	}
	
	public function bind_value($name, $value, $type){
		$q = $this->current_query;
		if(is_null($value)) {
			$this->last_query_params[$name] = 'NULL';
		}
		else if($value === '') {
			$this->last_query_params[$name] = "''";
		}
		else if($value === FALSE) {
			$this->last_query_params[$name] = "FALSE";
		}
		else if($value === TRUE) {
			$this->last_query_params[$name] = "TRUE";
		}
		else  {
			$this->last_query_params[$name] = "$value";
		}
		$q->bindValue($name, $value, $type);
		return true;
	}
	
	/** How many recent statements the error-context history keeps. */
	const QUERY_HISTORY_LIMIT = 50;

	public function execute_query() {
		$q = $this->current_query;
		// Record the SQL text, not the PDOStatement. A statement holds a
		// reference to the connection that prepared it, so keeping the objects
		// pinned every connection ever opened alive — a process that switches
		// test mode repeatedly (the model suite does it once per class) then
		// exhausted max_connections part-way through. The only consumer is
		// error context via print_r, which read the query string anyway.
		$sql = $q->queryString;
		if (!in_array($sql, $this->query_history, true)) {
			$this->query_history[] = $sql;
			if (count($this->query_history) > self::QUERY_HISTORY_LIMIT) {
				array_shift($this->query_history);
			}
		}
		$q->execute();
		return true;
	}

	public static function Rollback() {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$dblink->rollBack();
	}

	public function get_db_link() {
		if($this->test_mode){
			return $this->dblink_test;
		}
		else{
			return $this->dblink;
		}
	}

	public function set_test_mode() {
		$settings = Globalvars::get_instance();
		$this->dblink_test = new PDO('pgsql:host=localhost port=5432 dbname=' . $settings->get_setting('dbname_test') . ' user=' . $settings->get_setting('dbusername_test') . ' password=' . $settings->get_setting('dbpassword_test'));
		$this->dblink_test->setAttribute (PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->test_mode = true;
		return true; 
	}	

	public function close_test_mode() {
		$this->test_mode = false;
		// Release the connection set_test_mode() opened. Dropping the last
		// reference is what actually closes a PDO handle; leaving it set meant
		// every open/close cycle held a server connection until the process
		// ended, so a run that switches test mode many times (the model suite
		// does it once per class) exhausts max_connections part-way through.
		$this->dblink_test = NULL;
		return true;
	}

	function handle_query_error($e) {
		require_once(__DIR__ . '/ErrorClasses.php');
		
		$error_context = "DATABASE ERROR CONTEXT:\n";
		
		// Include last query parameters (already collected!)
		if(count($this->last_query_params)){
			$error_context .= "\nLast Query Parameters:\n";
			foreach($this->last_query_params as $param => $value) {
				$error_context .= "  $param => $value\n";
			}
		}
		
		// Create DatabaseException with context
		$dbException = new DatabaseException($e->getMessage(), (int)$e->getCode(), $e);
		$dbException->setContext(['query_params' => $this->last_query_params]);
		
		throw $dbException;
	}

	function _destruct() {
		$this->dblink = NULL;
	}
}

?>
