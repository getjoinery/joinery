<?php
	/*
	This script runs tests on all known Classes in the project.
	Classes are discovered dynamically from the data directories.
	*/
	
	require_once(__DIR__ . '/../../includes/PathHelper.php');
	
	PathHelper::requireOnce('includes/Globalvars.php');
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');

	// Discover all model classes using centralized method
	$classes = LibraryFunctions::discover_model_classes();
	echo 'Found ' . count($classes) . ' model classes<br>';
	
	$verbose = false;
	if(isset($_GET['verbose']) && $_GET['verbose']){
		$verbose = true;
	}

	// Run tests on each class
	$successful_classes = 0;
	$failed_classes = 0;
	
	foreach($classes as $class){
		if($class::test($verbose)){
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

