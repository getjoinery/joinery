<?php
	require_once( __DIR__ . '/../includes/Globalvars.php');
	require_once( __DIR__ . '/../includes/DbConnector.php');
	require_once( __DIR__ . '/../data/emails_class.php');


	$session = SessionControl::get_instance();
	$session->check_permission(5);

	$email = new Email($_GET['eml_email_id'], TRUE);

	
	echo $email->get('eml_message_html');
			

?>
