<?php

	$tablename = LibraryFunctions::fetch_variable('tablename', NULL, 0, '');
	$tablename = preg_replace('/[^A-Za-z0-9_]/', '', $tablename);

	$settings = Globalvars::get_instance();
	$sitedir = $settings->get_setting('siteDir');

	$exports_dir = $sitedir . '/admin/exports/';

	foreach (glob($exports_dir.'*.*') as $v) {
		unlink($v);
	}



	if($tablename){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$rnum = LibraryFunctions::str_rand(12);
		$filen = $exports_dir . $rnum .'.csv';
		$wfilen = '/admin/exports/'. $rnum .'.csv';

		$fp = fopen($filen, 'w');

		// Use consolidated method with single table parameter (also fixes SQL injection)
		$tables_and_columns = LibraryFunctions::get_tables_and_columns($tablename);
		$chead = isset($tables_and_columns[$tablename]) ? array_keys($tables_and_columns[$tablename]) : array();
		if (empty($chead)) {
			die("Invalid table name or table has no columns");
		}
		fputcsv($fp, $chead);

		// GET TABLE DATA - Use validated table name (already sanitized on line 4)
		// Note: Table names cannot be parameterized in FROM clause, but $tablename is pre-validated
		$columnsql = "SELECT * FROM " . $tablename;
		$results = $dblink->query($columnsql);

		while($row = $results->fetch(PDO::FETCH_NUM)){

			fputcsv($fp, $row);
		}

		fclose($fp);
	}


?>
