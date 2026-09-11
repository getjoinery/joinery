<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	$dbhelper = DbConnector::get_instance();
	$dblink = $dbhelper->get_db_link();

	// Use consolidated method
	$tables_and_columns = LibraryFunctions::get_tables_and_columns();
	$tables = array();
	foreach(array_keys($tables_and_columns) as $table_name) {
		$tables[$table_name] = $table_name;
	}

?>