<?php
/** @joinery-admin level=9 */

	$session = SessionControl::get_instance();

	// Deleting is the Delete button's POST; opening the URL deletes nothing.
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
		header('Location: /admin/admin_errors');
		exit();
	}

	$dbhelper = DbConnector::get_instance();
	$dblink = $dbhelper->get_db_link();

	$sql = "DELETE FROM err_general_errors WHERE err_message=? AND err_file=? AND err_line=?";

	try{
		$q = $dblink->prepare($sql);
		$q->bindValue(1, base64_decode($_POST['message'] ?? ''), PDO::PARAM_STR);
		$q->bindValue(2, $_POST['file'] ?? '', PDO::PARAM_STR);
		$q->bindValue(3, $_POST['line'] ?? null, PDO::PARAM_INT);
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