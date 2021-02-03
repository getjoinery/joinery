<?php

	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	$usr_email = LibraryFunctions::fetch_variable('usr_email', NULL);
	$act_code = LibraryFunctions::fetch_variable('act_code', NULL);
	if ($act_code) {
		Activation::ActivateUser($act_code);
	} else if ($usr_email) {
		$user = User::GetByEmail($usr_email);
		Activation::email_activate_send($user);
		echo 'sent';
	}

?>
