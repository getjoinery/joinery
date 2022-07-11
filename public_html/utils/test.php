<?php
	/*
	This script runs tests on all known Classes in the project.
	List of project classes is in class_list.php
	*/
	
	require_once( __DIR__ . '/class_list.php');
	require_once( __DIR__ . '/../includes/Globalvars.php');
	$settings = Globalvars::get_instance();
	$siteDir = $settings->get_setting('siteDir');

	require_once($siteDir.'/includes/SessionControl.php');
	require_once($siteDir.'/includes/LibraryFunctions.php');
 
	echo 'All classes loaded<br>';


	foreach($classes as $class){
		if($class::test(true)){
			echo $class .' success'. "<br>\n";
		}
		else{
			echo $class .' error'. "<br>\n";
		}
	}

	exit;

?>

