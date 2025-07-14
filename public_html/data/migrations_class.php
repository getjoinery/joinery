<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/Globalvars.php');
$settings = Globalvars::get_instance();
PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SystemClass.php');
	

class MigrationException extends SystemClassException {}
class MigrationNotSentException extends MigrationException {};

class Migration extends SystemBase {
	public static $prefix = 'mig';
	public static $tablename = 'mig_migrations';
	public static $pkey_column = 'mig_migration_id';
	public static $permanent_delete_actions = array(
		'mig_migration_id' => 'delete',	
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	

	public static $fields = array(
		'mig_migration_id' => 'Migration id',
		'mig_version' => 'Minor Version',
		'mig_name' => 'Name of this release',
		'mig_release_notes' => 'Release notes',
		'mig_sql' => 'Sql of the migration',
		'mig_file' => 'The file that was run',
		'mig_output' => 'Output of the migration',
		'mig_hash' => 'The hash of the migration_sql or migration_file',
		'mig_db_hash' => 'The hash of all of the database structure',
		'mig_success' => 'Was it successful?',
		'mig_create_time' => 'Time run',
	);

	public static $field_specifications = array(
		'mig_migration_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'mig_version' => array('type'=>'int4'),
		'mig_name' => array('type'=>'varchar(64)'),
		'mig_release_notes' => array('type'=>'text'),
		'mig_sql' => array('type'=>'text'),
		'mig_file' => array('type'=>'varchar(128)'),
		'mig_output' => array('type'=>'text'),
		'mig_hash' => array('type'=>'varchar(33)'),
		'mig_db_hash' => array('type'=>'varchar(33)'),
		'mig_success' => array('type'=>'bool'),
		'mig_create_time' => array('type'=>'timestamp(6)'),
	);

	public static $required_fields = array();

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array('mig_create_time'=>'now()');	

	
	
	function authenticate_write($data) {
		if ($data['current_user_permission'] < 8) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}

	
}

class MultiMigration extends SystemMultiBase {

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['version'])) {
			$filters['mig_version'] = [$this->options['version'], PDO::PARAM_INT];
		}

		if (isset($this->options['hash'])) {
			$filters['mig_hash'] = [$this->options['hash'], PDO::PARAM_STR];
		}

		if (isset($this->options['successful'])) {
			$filters['mig_success'] = [$this->options['successful'], PDO::PARAM_BOOL];
		}

		return $this->_get_resultsv2('mig_migrations', $filters, $this->order_by, $only_count, $debug);
	}

	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new Migration($row->mig_migration_id);
			$child->load_from_data($row, array_keys(Migration::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}

}



?>
