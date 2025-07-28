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
	foreach($classes as $class){
		if($class::test($verbose)){
			echo $class .' success'. "<br>\n";
		}
		else{
			echo $class .' error'. "<br>\n";
		}
	}

	exit;

?>

