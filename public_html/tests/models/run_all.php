<?php
	/*
	This script runs SINGLE MODEL tests on all known Classes in the project.
	Classes are discovered dynamically from the data directories.
	For Multi class tests, use run_multi.php
	*/
	
	require_once(__DIR__ . '/../../includes/PathHelper.php');
	
	PathHelper::requireOnce('includes/Globalvars.php');
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');

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
			echo ' <span style="color: #007bff; font-weight: bold;">(TEST MODE)</span>';
		} else {
			echo ' <span style="color: #28a745; font-weight: bold;">(LIVE MODE)</span>';
		}
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
	
	// Check if user wants read-only mode (no insert/update/delete operations)
	$read_only_mode = false;
	if(isset($_GET['read_only']) && $_GET['read_only']){
		$read_only_mode = true;
	}
	
	// Always show read-only option for live database testing
	if (!$read_only_mode) {
		echo '<div style="background: #fff3cd; border: 1px solid #ffc107; padding: 10px; margin: 10px 0; border-radius: 5px;">';
		echo '<strong>💡 Live Database Option:</strong> For safe schema/configuration validation without any data changes:<br>';
		echo '<a href="?read_only=1' . ($verbose ? '&verbose=1' : '') . '" style="background: #28a745; color: white; padding: 5px 10px; text-decoration: none; border-radius: 3px; margin: 5px 0; display: inline-block;">🔍 Run Read-Only Validation on Live Database</a>';
		echo '<br><small>Read-only mode: Primary keys, field specs, configuration validation only (no CRUD operations)</small>';
		echo '</div>';
	}
	
	if ($read_only_mode) {
		echo '<div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 10px; margin: 10px 0; border-radius: 5px;">';
		echo '<strong>🔍 READ-ONLY MODE:</strong> Schema and configuration validation only (no insert/update/delete operations)';
		echo '</div>';
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

