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
	
	public function execute_query() {
		$q = $this->current_query;
		if (!in_array($q, $this->query_history)){
			$this->query_history[] = $q; 
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
		return true;
	}				

	function handle_query_error($e) {
		require_once(__DIR__ . '/Exceptions/DatabaseException.php');
		
		$error_context = "DATABASE ERROR CONTEXT:\n";
		
		// Include last query parameters (already collected!)
		if(count($this->last_query_params)){
			$error_context .= "\nLast Query Parameters:\n";
			foreach($this->last_query_params as $param => $value) {
				$error_context .= "  $param => $value\n";
			}
		}
		
		// Create DatabaseException with context
		$dbException = new DatabaseException($e->getMessage(), $e->getCode(), $e);
		$dbException->setContext(['query_params' => $this->last_query_params]);
		
		throw $dbException;
	}

	function _destruct() {
		$this->dblink = NULL;
	}
}

?>
