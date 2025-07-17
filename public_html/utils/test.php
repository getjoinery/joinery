<?php
	/*
	This script runs tests on all known Classes in the project.
	List of project classes is in class_list.php
	*/
	
	require_once( __DIR__ . '/class_list.php');
	require_once(__DIR__ . '/../includes/PathHelper.php');
	
	PathHelper::requireOnce('includes/Globalvars.php');
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');

 
	echo 'All classes loaded<br>';
	
	$verbose = false;
	if($_GET['verbose']){
		$verbose = true;
	}


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

