<?php
require_once('Globalvars.php');

//class SystemDatabaseException extends PDOException {}

class DbConnector {
	private static $instance;
	private $dblink;

	private function __construct() {
		$settings = Globalvars::get_instance();
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

	public static function HandleQueryError($e) {
		throw $e;
	}

	public static function Commit() {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$dblink->commit();
	}

	public static function Rollback() {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$dblink->rollBack();
	}

	public function get_db_link() {
		return $this->dblink;
	}

	function handle_query_error($e) {
		throw $e;
	}

	function _destruct() {
		$this->dblink = NULL;
	}
}

?>
