<?php
	/*
	This script runs SINGLE MODEL tests on all known Classes in the project.
	Classes are discovered dynamically from the data directories.
	For Multi class tests, use run_multi.php
	*/
	
	require_once(__DIR__ . '/../../includes/PathHelper.php');
	
	require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
	require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	// SAFETY: Set hard time limit for test execution (15 seconds)
	set_time_limit(15);

	// Explicitly disable Multi testing for this script
	define('TEST_MULTI', false);
	define('SINGLE_TESTS_ONLY', true);

	// Display database information
	echo '<h2>Single Model Testing</h2>';
	
	// Show which database is being used
	try {
		$dbconnector = DbConnector::get_instance();
		$dblink = $dbconnector->get_db_link();
		$sql = "SELECT current_database() as db_name";
		$q = $dblink->prepare($sql);
		$q->execute();
		$db_info = $q->fetch(PDO::FETCH_ASSOC);
		
		$test_mode = false;
		if (method_exists($dbconnector, 'is_test_mode')) {
			$test_mode = $dbconnector->is_test_mode();
		}
		
		echo '<div style="background: #f0f8ff; border: 1px solid #007bff; padding: 10px; margin: 10px 0; border-radius: 5px;">';
		echo '<strong>🗄️ Database:</strong> ' . htmlspecialchars($db_info['db_name']);
		if ($test_mode) {
			echo ' <span style="color: #007bff; font-weight: bold;">(TEST DATABASE)</span>';
		} else {
			echo ' <span style="color: #dc3545; font-weight: bold;">(⚠️ LIVE DATABASE)</span>';
		}
		echo '<br><small><strong>Note:</strong> ';
		if ($test_mode) {
			echo 'Running against test database - data changes are safe and isolated from live data.';
		} else {
			echo 'Running against LIVE PRODUCTION database - all changes will affect real data!';
		}
		echo '</small>';
		echo '</div>';
		
	} catch (Exception $e) {
		echo '<div style="background: #fff3cd; border: 1px solid #ffc107; padding: 10px; margin: 10px 0; border-radius: 5px;">';
		echo '<strong>⚠️ Warning:</strong> Could not determine database: ' . htmlspecialchars($e->getMessage());
		echo '</div>';
	}
	
	// Discover all model classes using centralized method
	$classes = LibraryFunctions::discover_model_classes();
	echo 'Found ' . count($classes) . ' model classes<br>';
	echo '<p><em>Running single model tests (CRUD, validation, constraints). For Multi class tests, use <a href="run_multi">run_multi</a></em></p><br>';
	
	$verbose = false;
	if(isset($_GET['verbose']) && $_GET['verbose']){
		$verbose = true;
	}
	
	// Determine if we're on live database
	$is_live_database = !$test_mode;
	
	// Check test mode preference
	$read_only_mode = true; // Default to read-only for safety
	$force_crud_mode = false;
	
	if(isset($_GET['read_only']) && $_GET['read_only']){
		$read_only_mode = true;
	} elseif(isset($_GET['force_crud']) && $_GET['force_crud']){
		$force_crud_mode = true;
		$read_only_mode = false;
	} elseif($is_live_database) {
		// On live database, default to read-only unless explicitly forced
		$read_only_mode = true;
	} else {
		// On test database, allow CRUD by default
		$read_only_mode = false;
	}
	
	// Show appropriate options based on database type
	if ($is_live_database) {
		if (!$force_crud_mode) {
			// Default read-only mode on live database
			echo '<div style="background: #d1ecf1; border: 1px solid #bee5eb; padding: 15px; margin: 10px 0; border-radius: 5px;">';
			echo '<strong>🛡️ LIVE DATABASE PROTECTION:</strong> Running in read-only mode by default<br>';
			echo '<small>Schema and configuration validation only (no insert/update/delete operations)</small><br><br>';
			echo '<strong>⚠️ DANGER ZONE:</strong> Run full CRUD tests on live database?<br>';
			echo '<a href="?force_crud=1' . ($verbose ? '&verbose=1' : '') . '" style="background: #dc3545; color: white; padding: 8px 12px; text-decoration: none; border-radius: 3px; margin: 5px 0; display: inline-block;" onclick="return confirm(\'🚨 WARNING: This will run INSERT/UPDATE/DELETE operations on the LIVE database.\\n\\nThis may:\\n• Create test data that needs to be cleaned up\\n• Modify existing data\\n• Potentially cause data corruption\\n\\nAre you absolutely sure you want to continue?\')">💥 FORCE Full CRUD Tests (DANGEROUS)</a>';
			echo '</div>';
		} else {
			// CRUD mode explicitly requested on live database
			echo '<div style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; margin: 10px 0; border-radius: 5px;">';
			echo '<strong>🚨 DANGER: CRUD MODE ON LIVE DATABASE</strong><br>';
			echo 'You have explicitly requested to run INSERT/UPDATE/DELETE operations on the live database.<br>';
			echo '<a href="?read_only=1' . ($verbose ? '&verbose=1' : '') . '" style="background: #28a745; color: white; padding: 5px 10px; text-decoration: none; border-radius: 3px; margin: 5px 0; display: inline-block;">🛡️ Switch to Safe Read-Only Mode</a>';
			echo '</div>';
		}
	} else {
		// Test database - show both options
		if ($read_only_mode) {
			echo '<div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 10px; margin: 10px 0; border-radius: 5px;">';
			echo '<strong>🔍 READ-ONLY MODE:</strong> Schema and configuration validation only<br>';
			echo '<a href="?' . ($verbose ? 'verbose=1' : '') . '" style="background: #007bff; color: white; padding: 5px 10px; text-decoration: none; border-radius: 3px; margin: 5px 0; display: inline-block;">🧪 Run Full CRUD Tests</a>';
			echo '</div>';
		} else {
			echo '<div style="background: #cce5ff; border: 1px solid #b3d9ff; padding: 10px; margin: 10px 0; border-radius: 5px;">';
			echo '<strong>🧪 FULL TEST MODE:</strong> Running all tests including CRUD operations<br>';
			echo '<a href="?read_only=1' . ($verbose ? '&verbose=1' : '') . '" style="background: #28a745; color: white; padding: 5px 10px; text-decoration: none; border-radius: 3px; margin: 5px 0; display: inline-block;">🔍 Switch to Read-Only Mode</a>';
			echo '</div>';
		}
	}
	
	// Display time limit safety notice
	echo '<div style="background: #fff3cd; border: 1px solid #ffc107; padding: 5px 10px; margin: 5px 0; border-radius: 3px; font-size: 0.9em;">';
	echo '⏱️ <strong>Safety Time Limit:</strong> Test execution will automatically stop after 15 seconds';
	echo '</div>';

	// Run tests on each class
	$successful_classes = 0;
	$failed_classes = 0;
	
	foreach($classes as $class){
		if($class::test($verbose, false, $read_only_mode)){
			$successful_classes++;
		}
		else{
			echo $class .' error'. "<br>\n";
			$failed_classes++;
		}
	}

	// Display summary
	echo "<hr><br>\n";
	echo "<h3>Test Summary</h3>\n";
	echo "<strong>Classes tested:</strong> " . count($classes) . "<br>\n";
	echo "<strong>Successful classes:</strong> " . $successful_classes . "<br>\n";
	echo "<strong>Failed classes:</strong> " . $failed_classes . "<br>\n";
	
	// Get detailed test statistics from ModelTester
	$stats = ModelTester::get_test_stats();
	echo "<br><strong>Detailed Test Results:</strong><br>\n";
	echo "Passed tests: " . $stats['passed'] . "<br>\n";
	echo "Failed tests: " . $stats['failed'] . "<br>\n";
	echo "Warning tests: " . $stats['warned'] . "<br>\n";
	echo "Total individual tests: " . ($stats['passed'] + $stats['failed'] + $stats['warned']) . "<br>\n";
	
	exit;

?>

