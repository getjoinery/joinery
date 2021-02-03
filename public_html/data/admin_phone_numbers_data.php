<?php

	$session = SessionControl::get_instance();




	if(isset($_REQUEST['usr_user_id'])){
		$session->check_permission(5);
		$usr_user_id = (int)$_REQUEST['usr_user_id'];
	}
	else{
		$session->check_permission(0);
		$usr_user_id=$_SESSION['usr_user_id'];
	}



	$numperpage = 30;
	$phnoffset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$phnsort = 'phn_phone_number_id';
	$phnsdirection = 'ASC';


	$dbhelper = DbConnector::get_instance();
	$dblink = $dbhelper->get_db_link();

	//GET THE COUNT
	$sql = "SELECT count(*) as totalcount FROM phn_phone_numbers WHERE phn_usr_user_id=:phn_usr_user_id";

	try{
		$q = $dblink->prepare($sql);
		$q->bindParam(':phn_usr_user_id', $usr_user_id, PDO::PARAM_INT);

		$success = $q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);
	}
	catch(PDOException $e){
		$dbhelper->handle_query_error($e);
	}

	$count = $q->fetch();

	$numphonerecords = $count->totalcount;


	//GET THE DATA
	$sql = "SELECT * FROM phn_phone_numbers WHERE phn_usr_user_id=:phn_usr_user_id ORDER BY $phnsort $phnsdirection LIMIT :numperpage OFFSET :offset";

	try{
		$q = $dblink->prepare($sql);
		$q->bindParam(':phn_usr_user_id', $usr_user_id, PDO::PARAM_INT);
		$q->bindParam(':numperpage', $numperpage, PDO::PARAM_INT);
		$q->bindParam(':offset', $phnoffset, PDO::PARAM_INT);


		$count = $q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);
	}
	catch(PDOException $e){
		$dbhelper->handle_query_error($e);
	}

	$phones = $q->fetchAll();


	//GET THE COUNT
	$sql = "SELECT count(*) as totalcount FROM phn_phone_numbers WHERE phn_usr_user_id=:phn_usr_user_id AND phn_is_verified=TRUE";

	try{
		$q = $dblink->prepare($sql);
		$q->bindParam(':phn_usr_user_id', $usr_user_id, PDO::PARAM_INT);

		$success = $q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);
	}
	catch(PDOException $e){
		$dbhelper->handle_query_error($e);
	}

	$count = $q->fetch();

	$numphoneverified = $count->totalcount;



?>