<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/DbConnector.php');
require_once($siteDir . '/includes/LibraryFunctions.php');
require_once($siteDir . '/includes/SystemClass.php');
	

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

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();
	
		
		if (array_key_exists('version', $this->options)) {
			$where_clauses[] = 'mig_version = ?';
			$bind_params[] = array($this->options['version'], PDO::PARAM_INT);
		}	

		if (array_key_exists('hash', $this->options)) {
			$where_clauses[] = 'mig_hash = ?';
			$bind_params[] = array($this->options['hash'], PDO::PARAM_STR);
		}	

		if (array_key_exists('successful', $this->options)) {
			$where_clauses[] = 'mig_success = ?';
			$bind_params[] = array($this->options['successful'], PDO::PARAM_BOOL);
		}		
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}


		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM mig_migrations ' . $where_clause;
		} else {
			$sql = 'SELECT * FROM mig_migrations
				' . $where_clause . '
				ORDER BY ';
			
			if (empty($this->order_by)) {
				$sql .= " mig_migration_id ASC ";
			}
			else {
				if (array_key_exists('migration_id', $this->order_by)) {
					$sql .= ' mig_migration_id ' . $this->order_by['migration_id'];
				}
				

				if (array_key_exists('version', $this->order_by)) {
					$sql .= ' mig_version ' . $this->order_by['version'];
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
		parent::load();
		$q = $this->_get_results(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new Migration($row->mig_migration_id);
			$child->load_from_data($row, array_keys(Migration::$fields));
			$this->add($child);
		}
	}

}



?>
