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

		//GET COLUMN METADATA
		$columnsql = "SELECT column_name FROM information_schema.columns WHERE table_name ='$tablename'";
		$results = $dblink->query($columnsql);
		$chead = array();
			while($row = $results->fetch(PDO::FETCH_NUM)){
			array_push($chead,$row[0]);
			}
		fputcsv($fp, $chead);

		//GET COLUMN METADATA
		$columnsql = "SELECT * FROM $tablename";
		$results = $dblink->query($columnsql);

		while($row = $results->fetch(PDO::FETCH_NUM)){

			fputcsv($fp, $row);
		}

		fclose($fp);
	}


?>
