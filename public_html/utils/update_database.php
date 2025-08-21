<?php
	set_time_limit(3600); // Allow script to run for up to 1 hour
	
	$_SERVER['DOCUMENT_ROOT'] = __DIR__ . '/..';
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/PathHelper.php');
	
	// Load migrations first to test loading - use direct require_once to preserve variable scope
	require_once(PathHelper::getIncludePath('migrations/migrations.php'));
	
	PathHelper::requireOnce('includes/Globalvars.php');
	PathHelper::requireOnce('includes/DbConnector.php');
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	PathHelper::requireOnce('includes/DatabaseUpdater.php');
	PathHelper::requireOnce('data/migrations_class.php');
	
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
	PathHelper::requireOnce('includes/ComposerValidator.php');
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

	function update_database($migrations, $verbose=false, $upgrade=false, $cleanup=false){
		
		// Use DatabaseUpdater class for all table operations
		require_once(__DIR__ . '/../includes/DatabaseUpdater.php');
		$database_updater = new DatabaseUpdater($verbose, $upgrade, $cleanup);
		
		// Step 1: Run core table creation and basic column updates (excludes plugins)
		$table_result = $database_updater->runCoreTablesOnly();
		
		if (!$table_result['success']) {
			echo 'Table creation/update failed:<br>' . implode('<br>', $table_result['errors']) . "<br>\n";
			return false;
		}
		
		// Display table operation results
		if ($verbose && !empty($table_result['messages'])) {
			echo implode('<br>', $table_result['messages']) . "<br>\n";
		}
		
		// Display warnings from column validation (only in verbose mode)
		if ($verbose && !empty($table_result['warnings'])) {
			foreach ($table_result['warnings'] as $warning) {
				echo 'NOTICE: ' . $warning . "<br>\n";
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
		
		// Step 4: Run database migrations
		echo "-----MIGRATIONS-----<br>\n";
		echo "Migrations loaded successfully: " . count($migrations) . " migrations found<br>\n";
		
		// Validate migrations before processing
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
		
		// Report validation results
		if (!empty($migration_errors)) {
			echo "<div style='color: red; background: #ffe6e6; padding: 10px; margin: 10px 0; border: 1px solid #ff9999;'>";
			echo "<strong>❌ MIGRATION VALIDATION ERRORS FOUND:</strong><br>\n";
			echo "The following migrations have configuration problems and must be fixed:<br><br>\n";
			foreach ($migration_errors as $error) {
				echo "• " . htmlspecialchars($error) . "<br>\n";
			}
			echo "<br><strong>How to fix:</strong><br>\n";
			echo "1. Edit /migrations/migrations.php<br>\n";
			echo "2. Either remove empty placeholder migrations or add proper migration_sql/migration_file<br>\n";
			echo "3. Ensure each migration has exactly one of: migration_sql OR migration_file (not both, not neither)<br>\n";
			echo "</div>";
			
			// Show count of valid vs invalid
			echo "<br><strong>Migration Summary:</strong><br>\n";
			echo "Total migrations found: " . count($migrations) . "<br>\n";
			echo "Valid migrations: " . count($valid_migrations) . "<br>\n";
			echo "Invalid migrations: " . count($migration_errors) . "<br>\n";
			
			// Stop processing if there are validation errors
			echo "<br><strong>❌ Migration processing stopped due to validation errors above.</strong><br>\n";
			echo "Please fix the migration configuration errors and try again.<br>\n";
			return false;
		}
		
		// All migrations are valid, proceed with normal processing  
		echo "Total migrations found: " . count($migrations) . "<br>\n";
		echo "Valid migrations: " . count($valid_migrations) . "<br>\n";
		
		error_reporting(E_ERROR | E_PARSE);
		ini_set('display_errors', 1);
		ini_set('display_startup_errors', 1);
		
		$dbhelper = DbConnector::get_instance();
		
		$migclass = new Migration(null);
		$migration_run_count = 0;
		$migration_skip_count = 0;
		
		foreach($valid_migrations as $migration){
			$should_run = $migclass->check_migration($migration);
			if ($verbose) {
				echo "Checking migration " . ($migration['database_version'] ?? 'UNKNOWN') . ": " . ($should_run ? 'WILL RUN' : 'SKIPPING') . "<br>\n";
			}
			
			if($should_run){
				echo "Running migration: ".$migration['database_version']."<br>\n";
				$migclass->run_migration($migration);
				$migration_run_count++;
			} else {
				$migration_skip_count++;
			}
		}
		
		echo "Database migration complete.<br>\n";
		echo "#Run: ".$migration_run_count.",<br>\n";
		echo "#Skipped: ".$migration_skip_count."<br>\n";
		
		// Log the migration run
		$sql_output = ''; // Collect any error output
		$sql_commands = ''; // For backwards compatibility
		
		// Merge all operation results
		$all_errors = array_merge(
			$table_result['errors'] ?? [],
			$advanced_result['errors'] ?? [],
			$constraint_result['errors'] ?? []
		);
		
		if (!empty($all_errors)) {
			$sql_output = implode("\n", $all_errors);
		}
		
		$migration_log = new Migration(null);
		$migration_log->set('mig_hash', $db_structure_hash);
		$migration_log->set('mig_sql', $sql_commands);
		$migration_log->set('mig_output', $sql_output);
		$migration_log->set('mig_success', empty($sql_output) ? 1 : 0);
		$migration_log->prepare();
		$migration_log->save();
		
		return true;
	}

	// Run the database update if not included from another script
	if(!isset($noautorun)){
		// Ensure $migrations is defined
		if (!isset($migrations)) {
			$migrations = array();
		}
		
		if(update_database($migrations, $verbose, $upgrade, $cleanup)){
			echo 'Database update script successful'. "<br>\n";
			exit(1);  // RETURN 1 FOR THE DEPLOY SCRIPT
		} else {
			echo 'Database update script failed'. "<br>\n";
			exit(0);
		}
	}
?>