<?php

	$session = SessionControl::get_instance();
	$session->check_permission(9);

	$dbhelper = DbConnector::get_instance();
	$dblink = $dbhelper->get_db_link();

	$sql = "DELETE FROM err_general_errors WHERE err_message=? AND err_file=? AND err_line=?";

	try{
		$q = $dblink->prepare($sql);
		$q->bindValue(1, base64_decode($_POST['message']), PDO::PARAM_STR);
		$q->bindValue(2, $_POST['file'], PDO::PARAM_STR);
		$q->bindValue(3, $_POST['line'], PDO::PARAM_INT);
		$q->execute();
	}
	catch(PDOException $e){
		$dbhelper->handle_query_error($e);
	}

	//NOW REDIRECT
	$session = SessionControl::get_instance();
	$returnurl = $session->get_return();
	header("Location: $returnurl");
	exit();

?>