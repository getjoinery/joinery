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
	public static $permanent_delete_actions = array(	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	

	public static $fields = array(		'mig_migration_id' => 'Primary key - Migration ID',
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

	/**
	 * Field specifications define database column properties and schema constraints
	 * Available options:
	 *   'type' => 'varchar(255)' | 'int4' | 'int8' | 'text' | 'timestamp(6)' | 'numeric(10,2)' | 'bool' | etc.
	 *   'serial' => true/false - Auto-incrementing field
	 *   'is_nullable' => true/false - Whether NULL values are allowed
	 *   'unique' => true - Field must be unique (single field constraint)
	 *   'unique_with' => array('field1', 'field2') - Composite unique constraint with other fields
	 */
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

	
	/**
	 * Normalize SQL before hashing to handle formatting variations
	 * Prevents duplicate migrations from whitespace and formatting changes
	 * 
	 * @param string $sql SQL statement to normalize
	 * @return string Normalized SQL
	 */
	private function normalize_sql($sql) {
		// Normalize whitespace (safest approach)
		$sql = preg_replace('/\s+/', ' ', $sql);
		$sql = trim($sql);
		
		// Remove trailing semicolons
		$sql = rtrim($sql, ';');
		
		return $sql;
	}
	
	/**
	 * Check if migration should be run
	 * Uses hash comparison to prevent duplicate execution
	 * 
	 * @param array $migration Migration definition
	 * @return bool True if migration should run
	 */
	public function check_migration($migration) {
		try {
			$migration_hash = null;
			
			// Generate hash based on migration type
			if (isset($migration['migration_sql']) && $migration['migration_sql']) {
				// SQL-based migration - normalize SQL before hashing
				$normalized_sql = $this->normalize_sql($migration['migration_sql']);
				$migration_hash = md5($normalized_sql);
				
				echo "Processing SQL migration: " . $migration['database_version'] . "\n";
				if (isset($_REQUEST['verbose']) || (isset($GLOBALS['argv']) && in_array('--verbose', $GLOBALS['argv']))) {
					echo "Migration hash: " . $migration_hash . "\n";
					echo "Normalized SQL: " . substr($normalized_sql, 0, 100) . "...\n";
				}
				
			} elseif (isset($migration['migration_file']) && $migration['migration_file']) {
				// File-based migration - check file exists first
				$migration_file_path = __DIR__ . '/../migrations/' . $migration['migration_file'];
				
				if (!file_exists($migration_file_path)) {
					throw new MigrationException("Migration file not found: " . $migration['migration_file']);
				}
				
				$migration_hash = md5_file($migration_file_path);
				
				echo "Processing file migration: " . $migration['database_version'] . " (file: " . $migration['migration_file'] . ")\n";
				if (isset($_REQUEST['verbose']) || (isset($GLOBALS['argv']) && in_array('--verbose', $GLOBALS['argv']))) {
					echo "Migration hash: " . $migration_hash . "\n";
					echo "File path: " . $migration_file_path . "\n";
				}
				
			} else {
				throw new MigrationException("Migration must have either migration_sql or migration_file defined");
			}
			
			// Check if migration already exists and was successful
			$existing_migrations = new MultiMigration([
				'hash' => $migration_hash,
				'successful' => true
			]);
			$existing_migrations->load();
			
			if ($existing_migrations->count() > 0) {
				echo "Skipping migration " . $migration['database_version'] . " (already applied)\n";
				return false;
			}
			
			// Check the test condition if provided
			if (isset($migration['test']) && $migration['test']) {
				$dbconnector = DbConnector::get_instance();
				$dblink = $dbconnector->get_db_link();
				
				$q = $dblink->prepare($migration['test']);
				$q->execute();
				$result = $q->fetch(PDO::FETCH_ASSOC);
				
				if ($result && isset($result['count']) && $result['count'] > 0) {
					echo "Skipping migration " . $migration['database_version'] . " (test condition failed: count = " . $result['count'] . ")\n";
					return false;
				}
			}
			
			return true;
			
		} catch (Exception $e) {
			echo "ERROR: Failed to check migration " . $migration['database_version'] . ": " . $e->getMessage() . "\n";
			throw $e;
		}
	}
	
	/**
	 * Run a migration
	 * Executes the migration and records the result
	 * 
	 * @param array $migration Migration definition
	 * @return bool True if successful
	 */
	public function run_migration($migration) {
		$migration_record = new Migration(null);
		$migration_record->set('mig_version', floatval($migration['database_version']));
		$migration_record->set('mig_name', 'Migration ' . $migration['database_version']);
		$migration_record->set('mig_create_time', date('Y-m-d H:i:s'));
		
		try {
			$dbconnector = DbConnector::get_instance();
			$dblink = $dbconnector->get_db_link();
			
			// Start transaction
			$dblink->beginTransaction();
			
			$migration_hash = null;
			$output = '';
			
			if (isset($migration['migration_sql']) && $migration['migration_sql']) {
				// SQL-based migration
				$normalized_sql = $this->normalize_sql($migration['migration_sql']);
				$migration_hash = md5($normalized_sql);
				
				$migration_record->set('mig_sql', $migration['migration_sql']);
				$migration_record->set('mig_hash', $migration_hash);
				
				echo "Executing SQL migration: " . $migration['database_version'] . "\n";
				
				// Execute the SQL
				$q = $dblink->prepare($migration['migration_sql']);
				$result = $q->execute();
				
				if (!$result) {
					throw new MigrationException("SQL execution failed");
				}
				
				$output = "SQL migration executed successfully";
				
			} elseif (isset($migration['migration_file']) && $migration['migration_file']) {
				// File-based migration
				$migration_file_path = __DIR__ . '/../migrations/' . $migration['migration_file'];
				
				if (!file_exists($migration_file_path)) {
					throw new MigrationException("Migration file not found: " . $migration['migration_file']);
				}
				
				$migration_hash = md5_file($migration_file_path);
				
				$migration_record->set('mig_file', $migration['migration_file']);
				$migration_record->set('mig_hash', $migration_hash);
				
				echo "Executing file migration: " . $migration['database_version'] . " (file: " . $migration['migration_file'] . ")\n";
				
				// Include and execute the migration file
				ob_start();
				include($migration_file_path);
				$file_output = ob_get_clean();
				
				// Call the migration function if it exists
				$function_name = pathinfo($migration['migration_file'], PATHINFO_FILENAME);
				if (function_exists($function_name)) {
					$function_result = call_user_func($function_name);
					if ($function_result === false) {
						throw new MigrationException("Migration function returned false");
					}
					$output = "File migration executed successfully: " . $function_name;
				} else {
					$output = "File migration included successfully";
				}
				
				if ($file_output) {
					$output .= "\nOutput: " . $file_output;
				}
				
			} else {
				throw new MigrationException("Migration must have either migration_sql or migration_file defined");
			}
			
			// Record successful migration
			$migration_record->set('mig_output', $output);
			$migration_record->set('mig_success', true);
			$migration_record->save();
			
			// Commit transaction
			$dblink->commit();
			
			echo "Successfully applied migration: " . $migration['database_version'] . "\n";
			return true;
			
		} catch (Exception $e) {
			// Rollback transaction
			if ($dblink->inTransaction()) {
				$dblink->rollback();
			}
			
			// Record failed migration
			$error_message = "Migration failed: " . $e->getMessage();
			echo "ERROR: " . $error_message . "\n";
			
			try {
				$migration_record->set('mig_output', $error_message);
				$migration_record->set('mig_success', false);
				if ($migration_hash) {
					$migration_record->set('mig_hash', $migration_hash);
				}
				$migration_record->save();
			} catch (Exception $save_error) {
				echo "ERROR: Could not save migration record: " . $save_error->getMessage() . "\n";
			}
			
			return false;
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
