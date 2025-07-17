<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	
	PathHelper::requireOnce('includes/Globalvars.php');
	
	$file_path = NULL;
	if (!empty($_GET['apache_error_log'])) {
		$file_path = $_GET['apache_error_log'];
	} elseif (!empty($_GET['preview_image'])) {
		$file_path = $_GET['preview_image'];
	} elseif (!empty($_GET['logo_link'])) {
		$file_path = $_GET['logo_link'];
	}

	if ($file_path === NULL || $file_path === '') {
		echo 'true'; // Empty is valid
		exit;
	}

	// Handle logo_link field - must be relative and start with /
	if (!empty($_GET['logo_link'])) {
		if ($file_path[0] !== '/') {
			echo 'false'; // Must start with / (relative URL required)
			exit;
		}
		// Convert relative URL to filesystem path
		$file_path = PathHelper::getRootDir() . $file_path;
	}
	
	// Check if file exists and is readable
	if (file_exists($file_path) && is_readable($file_path)) {
		echo 'true';
	} else {
		echo 'false';
	}
?>