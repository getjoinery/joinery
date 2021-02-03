<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
		require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');

	
	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$usr_user_id = LibraryFunctions::fetch_variable('usr_user_id', NULL, 1, 'You must provide a user here.');
	$user = new User($usr_user_id, TRUE);
	
	$_SESSION['usr_user_id'] = $usr_user_id;
	$_SESSION['permission'] = $user->get('usr_permission');
	
	
	//NOW REDIRECT
	$returnurl = $session->get_return();
	header("Location: /");
	exit();

?>
