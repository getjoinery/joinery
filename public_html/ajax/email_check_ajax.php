<?php

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');

	$dbhelper = DbConnector::get_instance();
	$dblink = $dbhelper->get_db_link();

	$email = NULL;
	$possible_email_names = array('usr_email', 'lbx_reg_usr_email', 'lbx_email');
	foreach($possible_email_names as $email_name) {
		if (!empty($_GET[$email_name])) {
			$email = $_GET[$email_name];
			break;
		}
	}

	if ($email === NULL) {
		echo 'true';
		exit;
	}

	if (User::GetByEmail($email)) {
		echo 'false';
	} else {
		echo 'true';
	}

?>
