<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/Globalvars.php');
$settings = Globalvars::get_instance();
PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SystemClass.php');

class MigrationException extends SystemClassException {}
class MigrationNotSentException extends MigrationException {};

class Migration extends SystemBase {	public static $prefix = 'mig';
	public static $tablename = 'mig_migrations';
	public static $pkey_column = 'mig_migration_id';
	public static $permanent_delete_actions = array(	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	

		/**
	 * Field specifications define database column properties and validation rules
	 * 
	 * Database schema properties (used by update_database):
	 *   'type' => 'varchar(255)' | 'int4' | 'int8' | 'text' | 'timestamp' | 'bool' | etc.
	 *   'is_nullable' => true/false - Whether NULL values are allowed
	 *   'serial' => true/false - Auto-incrementing field
	 * 
	 * Validation and behavior properties (used by SystemBase):
	 *   'required' => true/false - Field must have non-empty value on save
	 *   'default' => mixed - Default value for new records (applied on INSERT only)
	 *   'zero_on_create' => true/false - Set to 0 when creating if NULL (INSERT only)
	 * 
	 * Note: Timestamp fields are auto-detected based on type for smart_get() and export_as_array()
	 */
	public static $field_specifications = array(
	    'mig_migration_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'mig_version' => array('type'=>'numeric(6,2)'),
	    'mig_name' => array('type'=>'varchar(64)'),
	    'mig_release_notes' => array('type'=>'text'),
	    'mig_sql' => array('type'=>'text'),
	    'mig_file' => array('type'=>'varchar(128)'),
	    'mig_output' => array('type'=>'text'),
	    'mig_hash' => array('type'=>'varchar(33)'),
	    'mig_db_hash' => array('type'=>'varchar(33)'),
	    'mig_success' => array('type'=>'bool'),
	    'mig_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	public static $field_constraints = array();	

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
	 * Load migrations from migrations.php file
	 * 
	 * @return array Array of migration definitions
	 */
	public static function loadMigrations() {
		$migrations = array();
		
		// Load migrations file - use direct require_once to preserve variable scope
		require_once(PathHelper::getIncludePath('migrations/migrations.php'));
		
		return $migrations;
	}
	
	/**
	 * Validate migrations array and return validation results
	 * 
	 * @param array $migrations Array of migration definitions
	 * @return array Validation result with 'valid' boolean, 'errors' array, 'valid_migrations' array
	 */
	public static function validateMigrations($migrations) {
		$migration_errors = [];
		$valid_migrations = [];
		
		foreach($migrations as $i => $migration) {
			$version = $migration['database_version'] ?? 'UNKNOWN';
			$has_sql = isset($migration['migration_sql']) && !empty($migration['migration_sql']);
			$has_file = isset($migration['migration_file']) && !empty($migration['migration_file']);
			
			// Check for required fields
			if (!isset($migration['database_version'])) {
				$migration_errors[] = "Migration #$i: Missing database_version";
				continue;
			}
			
			// Check for empty migrations (both SQL and file are NULL/empty)
			if (!$has_sql && !$has_file) {
				$migration_errors[] = "Migration #$i (version $version): Empty migration - both migration_sql and migration_file are empty. This migration should be removed or completed.";
				continue;
			}
			
			// Check for conflicting definitions
			if ($has_sql && $has_file) {
				$migration_errors[] = "Migration #$i (version $version): Conflicting migration - both migration_sql and migration_file are defined. Only one should be used.";
				continue;
			}
			
			// Migration passed validation
			$valid_migrations[] = $migration;
		}
		
		return [
			'valid' => empty($migration_errors),
			'errors' => $migration_errors,
			'valid_migrations' => $valid_migrations,
			'total_count' => count($migrations),
			'valid_count' => count($valid_migrations),
			'error_count' => count($migration_errors)
		];
	}
	
	/**
	 * Check if migration should be run (without verbose output)
	 * 
	 * @param array $migration Migration definition
	 * @return array Result with 'should_run' boolean and 'reason' string
	 */
	public function shouldRunMigration($migration) {
		try {
			$migration_hash = null;
			
			// Generate hash based on migration type
			if (isset($migration['migration_sql']) && $migration['migration_sql']) {
				// SQL-based migration - normalize SQL before hashing
				$normalized_sql = $this->normalize_sql($migration['migration_sql']);
				$migration_hash = md5($normalized_sql);
				
			} elseif (isset($migration['migration_file']) && $migration['migration_file']) {
				// File-based migration - check file exists first
				$migration_file_path = __DIR__ . '/../migrations/' . $migration['migration_file'];
				
				if (!file_exists($migration_file_path)) {
					return [
						'should_run' => false,
						'reason' => 'Migration file not found: ' . $migration['migration_file'],
						'error' => true
					];
				}
				
				$migration_hash = md5_file($migration_file_path);
				
			} else {
				return [
					'should_run' => false,
					'reason' => 'Migration must have either migration_sql or migration_file defined',
					'error' => true
				];
			}
			
			// Check if migration already exists and was successful
			$existing_migrations = new MultiMigration([
				'hash' => $migration_hash,
				'successful' => true
			]);
			$existing_migrations->load();
			
			if ($existing_migrations->count() > 0) {
				return [
					'should_run' => false,
					'reason' => 'already applied',
					'hash' => $migration_hash
				];
			}
			
			// Check the test condition if provided (optional)
			if (isset($migration['test']) && $migration['test'] && trim($migration['test']) !== '') {
				$dbconnector = DbConnector::get_instance();
				$dblink = $dbconnector->get_db_link();
				
				$q = $dblink->prepare($migration['test']);
				$q->execute();
				$result = $q->fetch(PDO::FETCH_ASSOC);
				
				if ($result && isset($result['count']) && $result['count'] > 0) {
					return [
						'should_run' => false,
						'reason' => 'test condition failed: count = ' . $result['count'],
						'hash' => $migration_hash
					];
				}
			}
			// Note: If test condition is empty/null, we rely solely on hash-based protection
			
			return [
				'should_run' => true,
				'reason' => 'ready to run',
				'hash' => $migration_hash
			];
			
		} catch (Exception $e) {
			return [
				'should_run' => false,
				'reason' => 'error checking migration: ' . $e->getMessage(),
				'error' => true
			];
		}
	}
	
	/**
	 * Execute a migration and return results (no output)
	 * 
	 * @param array $migration Migration definition
	 * @return array Result with 'success' boolean, 'error' string, 'sql' string, 'output' string
	 */
	public function executeMigration($migration) {
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
			$sql_executed = '';
			
			if (isset($migration['migration_sql']) && $migration['migration_sql']) {
				// SQL-based migration
				$normalized_sql = $this->normalize_sql($migration['migration_sql']);
				$migration_hash = md5($normalized_sql);
				$sql_executed = $migration['migration_sql'];
				
				$migration_record->set('mig_sql', $migration['migration_sql']);
				$migration_record->set('mig_hash', $migration_hash);
				
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
			
			return [
				'success' => true,
				'sql' => $sql_executed,
				'output' => $output,
				'version' => $migration['database_version']
			];
			
		} catch (Exception $e) {
			// Rollback transaction
			if ($dblink->inTransaction()) {
				$dblink->rollback();
			}
			
			// Record failed migration
			$error_message = "Migration failed: " . $e->getMessage();
			
			try {
				$migration_record->set('mig_output', $error_message);
				$migration_record->set('mig_success', false);
				if ($migration_hash) {
					$migration_record->set('mig_hash', $migration_hash);
				}
				$migration_record->save();
			} catch (Exception $save_error) {
				$error_message .= " (Could not save migration record: " . $save_error->getMessage() . ")";
			}
			
			return [
				'success' => false,
				'error' => $error_message,
				'sql' => $sql_executed ?? '',
				'version' => $migration['database_version']
			];
		}
	}
	
}

class MultiMigration extends SystemMultiBase {
	protected static $model_class = 'Migration';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['version'])) {
			$filters['mig_version'] = [floatval($this->options['version']), PDO::PARAM_STR];
		}

		if (isset($this->options['hash'])) {
			$filters['mig_hash'] = [$this->options['hash'], PDO::PARAM_STR];
		}

		if (isset($this->options['successful'])) {
			$filters['mig_success'] = [$this->options['successful'], PDO::PARAM_BOOL];
		}

		return $this->_get_resultsv2('mig_migrations', $filters, $this->order_by, $only_count, $debug);
	}

}

?>
