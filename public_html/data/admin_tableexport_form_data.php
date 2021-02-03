<?php

	$dbhelper = DbConnector::get_instance();
	$dblink = $dbhelper->get_db_link();

	//GET COLUMN METADATA
	$columnsql = "SELECT * FROM information_schema.tables WHERE table_type='BASE TABLE' AND table_schema='public'";
	$results = $dblink->query($columnsql);
	$tables = array();
	while ($row = $results->fetch(PDO::FETCH_OBJ)){
		$tables[$row->table_name] = $row->table_name;
	}

?>