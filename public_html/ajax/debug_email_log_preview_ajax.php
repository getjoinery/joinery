<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/debug_email_logs_class.php');


	$session = SessionControl::get_instance();
	$session->check_permission(8);

	$del_debug_email_log_id = new DebugEmailLog($_GET['del_debug_email_log_id'], TRUE);

	
	echo $del_debug_email_log_id->get('del_body');
			

?>
