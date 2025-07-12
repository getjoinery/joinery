<?php
	require_once( __DIR__ . '/../includes/Globalvars.php');
	
	$file_path = NULL;
	if (!empty($_GET['apache_error_log'])) {
		$file_path = $_GET['apache_error_log'];
	}

	if ($file_path === NULL || $file_path === '') {
		echo 'true'; // Empty is valid
		exit;
	}

	// Check if file exists and is readable
	if (file_exists($file_path) && is_readable($file_path)) {
		echo 'true';
	} else {
		echo 'false';
	}
?>