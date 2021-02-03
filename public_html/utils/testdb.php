    <?php


	$dbhelper = DbConnector::get_instance();
	$dblink = $dbhelper->get_db_link();


	$p_keys = NULL;
	$rowdata = array("test1key"=>3, "test2key"=>4, "data"=>"hello3");
	$inskey = LibraryFunctions::edit_table($dbhelper, $dblink, "testtable", $p_keys, $rowdata, 1);
	print_r( $inskey );