<?php
	set_time_limit(3600); // Allow script to run for up to 1 hour
	
	$_SERVER['DOCUMENT_ROOT'] = __DIR__ . '/..';
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/PathHelper.php');

	// Determine if running from command line or web
	$is_cli = (php_sapi_name() === 'cli');

	// Permission check - require permission level 10 for web access
	if (!$is_cli) {
		require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
		$session = SessionControl::get_instance();

		// Check if user is logged in and has sufficient permissions
		if (!$session->is_logged_in()) {
			http_response_code(403);
			echo "<h1>403 Forbidden</h1>";
			echo "<p>Access denied. Please <a href='/login'>log in</a> to continue.</p>";
			exit;
		}

		// Check permission level
		if ($session->get_permission() < 10) {
			http_response_code(403);
			echo "<h1>403 Forbidden</h1>";
			echo "<p>Access denied. Administrator permissions (level 10) required to run database updates.</p>";
			exit;
		}
	}

	// Migrations are now loaded automatically by the Migration class

	require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
	require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
	require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/DatabaseUpdater.php'));
	require_once(PathHelper::getIncludePath('data/migrations_class.php'));

	// Clear the stat cache to ensure we see file changes
	clearstatcache();

	$globalvars = Globalvars::get_instance();
	$session = SessionControl::get_instance();
	
	// Set defaults
	$verbose = false;
	$upgrade = false;
	$cleanup = false;
	
	// Check URL parameters or command line arguments
	if (isset($_REQUEST['verbose']) && $_REQUEST['verbose']) {
		$verbose = $_REQUEST['verbose'];
	} elseif (isset($argv) && in_array('--verbose', $argv)) {
		$verbose = true;
	}
	
	if (isset($_REQUEST['upgrade']) && $_REQUEST['upgrade']) {
		$upgrade = $_REQUEST['upgrade'];
	} elseif (isset($argv) && in_array('--upgrade', $argv)) {
		$upgrade = true;
	}
	
	if (isset($_REQUEST['cleanup']) && $_REQUEST['cleanup']) {
		$cleanup = $_REQUEST['cleanup'];
	} elseif (isset($argv) && in_array('--cleanup', $argv)) {
		$cleanup = true;
	}
	
	// Validate Composer setup before proceeding
	echo "\n=== COMPOSER VALIDATION ===\n";
	require_once(PathHelper::getIncludePath('includes/ComposerValidator.php'));
	$composerValidator = new ComposerValidator();
	
	if (!$composerValidator->validate()) {
		echo $composerValidator->getFormattedOutput();
		echo "\n\033[31mERROR: Composer validation failed. Please fix the issues above before running database updates.\033[0m\n\n";
		exit(1);
	} else {
		echo $composerValidator->getFormattedOutput();
	}

	/*
	THIS WILL CHECK THE SPECS IN THE $fields and $field_specifications VARIABLES AND CREATE AND/OR UPDATE THE TABLES AS NEEDED
	
	SAFETY GUARANTEES:
	-IT WILL ONLY ADD COLUMNS BY DEFAULT. IT WILL NOT DELETE THEM.
	-PRIMARY KEY COLUMNS ARE PROTECTED - THEY CAN NEVER BE DROPPED EVEN IN CLEANUP MODE
	-IF THE DATA TYPES DO NOT MATCH YOU WILL GET A WARNING BUT IT WILL NOT FIX (unless --upgrade flag)
	-IF CHARACTER LENGTH DOES NOT MATCH YOU WILL GET A WARNING BUT IT WILL NOT FIX (unless --upgrade flag)
	
	CLEANUP MODE (--cleanup flag):
	-WILL DROP COLUMNS THAT DON'T EXIST IN SPECIFICATIONS
	-PRIMARY KEYS ARE STILL PROTECTED FROM DELETION
	-OTHER COLUMNS (INCLUDING THOSE WITH CONSTRAINTS) WILL BE DROPPED IF NOT IN SPECIFICATIONS
	*/

	function update_database($verbose=false, $upgrade=false, $cleanup=false){
		
		// Use DatabaseUpdater class for all table operations
		require_once(__DIR__ . '/../includes/DatabaseUpdater.php');
		$database_updater = new DatabaseUpdater($verbose, $upgrade, $cleanup);
		
		// Step 1: Run core table creation and basic column updates (excludes plugins)
		$table_result = $database_updater->runCoreTablesOnly();
		
		if (!$table_result['success']) {
			echo 'Table creation/update failed:<br>' . implode('<br>', $table_result['errors']) . "<br>\n";
			return false;
		}
		
		// Display table operation results (always show schema changes, not just in verbose mode)
		if (!empty($table_result['messages'])) {
			echo implode('<br>', $table_result['messages']) . "<br>\n";
		}
		
		// Display warnings from column validation (always show warnings)
		if (!empty($table_result['warnings'])) {
			foreach ($table_result['warnings'] as $warning) {
				echo 'WARNING: ' . $warning . "<br>\n";
			}
		}
		
		// Display errors from column validation (always show errors)
		if (!empty($table_result['errors'])) {
			foreach ($table_result['errors'] as $error) {
				echo 'ERROR: ' . $error . "<br>\n";
			}
		}
		
		// Load core classes for hash calculation and constraint management
		$classes = LibraryFunctions::discover_model_classes(array(
			'require_tablename' => true,
			'require_field_specifications' => true,
			'include_plugins' => false,
			'verbose' => $verbose
		));
		
		// Build db structure hash from all field specifications
		$db_structure_contents = '';
		foreach ($classes as $class) {
			$db_structure_contents .= serialize($class::$field_specifications);
		}

		if($verbose){
			echo 'Finished loading classes<br>';
		}

		$db_structure_hash = md5($db_structure_contents);
		echo 'DB Hash: '. $db_structure_hash."<br>\n";
		
		// Step 2: Process advanced column operations (modifications, cleanup) if requested
		if ($upgrade || $cleanup) {
			$advanced_result = $database_updater->processAdvancedColumnOperations($classes);
			
			if (!$advanced_result['success']) {
				echo 'Advanced column operations failed:<br>' . implode('<br>', $advanced_result['errors']) . "<br>\n";
			}
			
			// Display results
			if (!empty($advanced_result['messages'])) {
				echo implode('<br>', $advanced_result['messages']) . "<br>\n";
			}
			
			if (!empty($advanced_result['warnings'])) {
				foreach ($advanced_result['warnings'] as $warning) {
					echo 'WARNING: ' . $warning . "<br>\n";
				}
			}
			
			if (!empty($advanced_result['errors'])) {
				foreach ($advanced_result['errors'] as $error) {
					echo 'ERROR: ' . $error . "<br>\n";
				}
			}
		}
		
		// Step 2.5: Fix primary key constraints if needed
		if ($upgrade || $cleanup) {
			echo "-----PRIMARY KEY FIXES-----<br>\n";
			$primary_key_result = $database_updater->fixPrimaryKeys($classes);
			
			if (!$primary_key_result['success']) {
				echo 'Primary key fixes failed:<br>' . implode('<br>', $primary_key_result['errors']) . "<br>\n";
			}
			
			// Display results
			if (!empty($primary_key_result['messages'])) {
				echo implode('<br>', $primary_key_result['messages']) . "<br>\n";
			}
			
			if (!empty($primary_key_result['warnings'])) {
				foreach ($primary_key_result['warnings'] as $warning) {
					echo 'WARNING: ' . $warning . "<br>\n";
				}
			}
			
			if (!empty($primary_key_result['errors'])) {
				foreach ($primary_key_result['errors'] as $error) {
					echo 'ERROR: ' . $error . "<br>\n";
				}
			}
		}
		
		// Step 3: Manage unique constraints
		echo "-----UNIQUE CONSTRAINTS-----<br>\n";
		$constraint_result = $database_updater->manageUniqueConstraints($classes);
		
		if (!$constraint_result['success']) {
			echo 'Constraint management failed:<br>' . implode('<br>', $constraint_result['errors']) . "<br>\n";
		}
		
		// Display constraint results
		if (!empty($constraint_result['messages'])) {
			echo implode('<br>', $constraint_result['messages']) . "<br>\n";
		}

		if (!empty($constraint_result['warnings'])) {
			foreach ($constraint_result['warnings'] as $warning) {
				echo 'WARNING: ' . $warning . "<br>\n";
			}
		}

		if (!empty($constraint_result['errors'])) {
			foreach ($constraint_result['errors'] as $error) {
				echo 'ERROR: ' . $error . "<br>\n";
			}
		}

		// Step 3.5: Register deletion rules for core models only (plugins handled separately)
		echo "-----DELETION RULES (CORE)-----<br>\n";
		require_once(PathHelper::getIncludePath('data/deletion_rule_class.php'));

		try {
			// Register rules for core models only, without affecting plugin rules
			DeletionRule::registerModelsFromDiscovery([
				'include_plugins' => false,
				'verbose' => $verbose
			]);
			echo "✓ Core deletion rules registered successfully<br>\n";
		} catch (Exception $e) {
			echo "⚠ Warning: Failed to register deletion rules - " . $e->getMessage() . "<br>\n";
			// Don't fail the entire update if deletion rule registration fails
			// This allows the system to continue working even if the new deletion system isn't fully set up
		}

		// Check for any database schema errors before attempting migrations
		$schema_errors = array_merge(
			$table_result['errors'] ?? [],
			$advanced_result['errors'] ?? [],
			$primary_key_result['errors'] ?? [],
			$constraint_result['errors'] ?? []
		);
		
		// Remove duplicate error messages
		$schema_errors = array_unique($schema_errors);
		
		if (!empty($schema_errors)) {
			echo "<br>❌ DATABASE SCHEMA ERRORS DETECTED - STOPPING BEFORE MIGRATIONS<br>\n";
			echo "The following errors must be fixed before migrations can run:<br>\n";
			foreach ($schema_errors as $error) {
				echo "  • " . $error . "<br>\n";
			}
			echo "<br>Please fix these schema issues and run the script again.<br>\n";
			
			// Log the failed run
			$migration_log = new Migration(null);
			$migration_log->set('mig_hash', md5('SCHEMA_ERRORS'));
			$migration_log->set('mig_sql', '');
			$migration_log->set('mig_output', implode("\n", $schema_errors));
			$migration_log->set('mig_success', 0);
			$migration_log->prepare();
			$migration_log->save();
			
			return false;
		}
		
		// Step 4: Run database migrations
		echo "-----MIGRATIONS-----<br>\n";
		
		// Load migrations using Migration class
		$migrations = Migration::loadMigrations();
		
		// Validate migrations using Migration class
		$validation_result = Migration::validateMigrations($migrations);
		
		// Report validation results
		if (!$validation_result['valid']) {
			echo "<div style='color: red; background: #ffe6e6; padding: 10px; margin: 10px 0; border: 1px solid #ff9999;'>";
			echo "<strong>❌ MIGRATION VALIDATION ERRORS FOUND:</strong><br>\n";
			echo "The following migrations have configuration problems and must be fixed:<br><br>\n";
			foreach ($validation_result['errors'] as $error) {
				echo "• " . htmlspecialchars($error) . "<br>\n";
			}
			echo "<br><strong>How to fix:</strong><br>\n";
			echo "1. Edit /migrations/migrations.php<br>\n";
			echo "2. Either remove empty placeholder migrations or add proper migration_sql/migration_file<br>\n";
			echo "3. Ensure each migration has exactly one of: migration_sql OR migration_file (not both, not neither)<br>\n";
			echo "</div>";
			
			// Show count of valid vs invalid
			echo "<br><strong>Migration Summary:</strong><br>\n";
			echo "Total migrations found: " . $validation_result['total_count'] . "<br>\n";
			echo "Valid migrations: " . $validation_result['valid_count'] . "<br>\n";
			echo "Invalid migrations: " . $validation_result['error_count'] . "<br>\n";
			
			// Stop processing if there are validation errors
			echo "<br><strong>❌ Migration processing stopped due to validation errors above.</strong><br>\n";
			echo "Please fix the migration configuration errors and try again.<br>\n";
			return false;
		}
		
		// All migrations are valid, proceed with normal processing  
		echo "Total migrations found: " . $validation_result['total_count'] . "<br>\n";
		echo "Valid migrations: " . $validation_result['valid_count'] . "<br>\n";
		
		error_reporting(E_ERROR | E_PARSE);
		ini_set('display_errors', 1);
		ini_set('display_startup_errors', 1);
		
		$dbhelper = DbConnector::get_instance();
		
		$migclass = new Migration(null);
		$migration_run_count = 0;
		$migration_skip_count = 0;
		$migration_fail_count = 0;

		// Track last 5 migrations for summary display
		$last_migrations = [];

		foreach($validation_result['valid_migrations'] as $migration){
			$should_run_result = $migclass->shouldRunMigration($migration);

			// Show migration status (skip messages only shown in verbose mode)
			if($should_run_result['should_run']){
				echo "WILL RUN migration " . ($migration['database_version'] ?? 'UNKNOWN') . "<br>\n";
			} else {
				// Show skip message only in verbose mode
				if ($verbose) {
					echo "SKIPPING migration " . ($migration['database_version'] ?? 'UNKNOWN') . " - Reason: " . $should_run_result['reason'] . "<br>\n";
				}
			}

			// Show additional details in verbose mode
			if ($verbose && isset($should_run_result['hash'])) {
				echo "  Migration hash: " . $should_run_result['hash'] . "<br>\n";
			}

			if($should_run_result['should_run']){
				echo "  Running migration: ".$migration['database_version']."<br>\n";

				// Show SQL if it's an SQL migration
				if ($verbose && isset($migration['migration_sql']) && $migration['migration_sql']) {
					echo "  SQL: " . $migration['migration_sql'] . "<br>\n";
				}

				$result = $migclass->executeMigration($migration);

				if ($result['success']) {
					echo "  ✓ Successfully applied migration: " . $result['version'] . "<br>\n";
					$migration_run_count++;

					// Track for summary
					$last_migrations[] = [
						'version' => $migration['database_version'] ?? 'UNKNOWN',
						'status' => 'SUCCESS',
						'message' => 'Successfully applied'
					];
				} else {
					echo "  ✗ FAILED migration: " . $result['version'] . " - Error: " . $result['error'] . "<br>\n";
					$migration_fail_count++;

					// Track for summary
					$last_migrations[] = [
						'version' => $migration['database_version'] ?? 'UNKNOWN',
						'status' => 'FAILED',
						'message' => $result['error']
					];
					// Continue processing other migrations even if one fails
				}
			} else {
				// Check if this was an error vs a normal skip
				if (isset($should_run_result['error']) && $should_run_result['error']) {
					echo "  ⚠ ERROR checking migration " . $migration['database_version'] . ": " . $should_run_result['reason'] . "<br>\n";
					$migration_fail_count++;

					// Track for summary
					$last_migrations[] = [
						'version' => $migration['database_version'] ?? 'UNKNOWN',
						'status' => 'ERROR',
						'message' => $should_run_result['reason']
					];
				} else {
					// Track for summary
					$last_migrations[] = [
						'version' => $migration['database_version'] ?? 'UNKNOWN',
						'status' => 'SKIPPED',
						'message' => $should_run_result['reason']
					];
				}
				$migration_skip_count++;
			}
		}

		echo "Database migration complete.<br>\n";
		echo "#Run: ".$migration_run_count."<br>\n";
		echo "#Failed: ".$migration_fail_count."<br>\n";
		echo "#Skipped: ".$migration_skip_count."<br>\n";

		// Display last 5 migrations
		echo "<br>\n<strong>Last 5 Migrations:</strong><br>\n";
		$display_migrations = array_slice($last_migrations, -5);
		foreach ($display_migrations as $mig) {
			$status_symbol = '';
			switch ($mig['status']) {
				case 'SUCCESS':
					$status_symbol = '✓';
					break;
				case 'FAILED':
					$status_symbol = '✗';
					break;
				case 'ERROR':
					$status_symbol = '⚠';
					break;
				case 'SKIPPED':
					$status_symbol = '○';
					break;
			}
			echo sprintf(
				"  %s [%s] v%s - %s<br>\n",
				$status_symbol,
				$mig['status'],
				$mig['version'],
				$mig['message']
			);
		}
		echo "<br>\n";
		
		// Log the migration run
		$sql_output = ''; // Collect any error output
		$sql_commands = ''; // For backwards compatibility
		
		// Merge all operation results - ONLY actual errors, not warnings
		$all_errors = array_merge(
			$table_result['errors'] ?? [],
			$advanced_result['errors'] ?? [],
			$constraint_result['errors'] ?? []
		);
		
		// Remove duplicate error messages
		$all_errors = array_unique($all_errors);
		
		// Also collect warnings for informational purposes (but don't count as failures)
		$all_warnings = array_merge(
			$table_result['warnings'] ?? [],
			$advanced_result['warnings'] ?? [],
			$constraint_result['warnings'] ?? []
		);
		
		// Remove duplicate warning messages
		$all_warnings = array_unique($all_warnings);
		
		if (!empty($all_errors)) {
			$sql_output = implode("\n", $all_errors);
		}
		
		// Determine overall success - failed ONLY if we have failures OR actual errors (NOT warnings)
		$overall_success = ($migration_fail_count == 0 && empty($sql_output));
		
		// Show summary of warnings and errors
		if (!empty($all_warnings)) {
			echo "⚠️  " . count($all_warnings) . " warning(s) occurred (not blocking success)<br>\n";
		}
		if (!empty($all_errors)) {
			echo "❗ " . count($all_errors) . " error(s) occurred<br>\n";
		}
		
		$migration_log = new Migration(null);
		$migration_log->set('mig_hash', $db_structure_hash);
		$migration_log->set('mig_sql', $sql_commands);
		$migration_log->set('mig_output', $sql_output);
		$migration_log->set('mig_success', $overall_success ? 1 : 0);
		$migration_log->prepare();
		$migration_log->save();
		
		// Show final result
		if ($overall_success) {
			echo "✅ DATABASE UPDATE SUCCESSFUL";
			if (!empty($all_warnings)) {
				echo " (with " . count($all_warnings) . " warnings)";
			}
			echo "<br>\n";
		} else {
			echo "❌ DATABASE UPDATE HAD ERRORS<br>\n";
			if ($migration_fail_count > 0) {
				echo "  - " . $migration_fail_count . " migration(s) failed<br>\n";
			}
			if (!empty($sql_output)) {
				echo "  - Database operation errors occurred<br>\n";
			}
		}
		
		return $overall_success;
	}

	// Run the database update if not included from another script
	if(!isset($noautorun)){
		if(update_database($verbose, $upgrade, $cleanup)){
			echo 'Database update script successful'. "<br>\n";
			exit(1);  // RETURN 1 FOR THE DEPLOY SCRIPT
		} else {
			echo 'Database update script failed'. "<br>\n";
			exit(0);
		}
	}
?>