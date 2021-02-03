<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/emails_class.php');


	$session = SessionControl::get_instance();
	$session->check_permission(5);

	$email = new Email($_GET['eml_email_id'], TRUE);

	
	echo $email->get('eml_message_html');
			

?>
