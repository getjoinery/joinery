<?php
	require_once( __DIR__ . '/../includes/Globalvars.php');
	require_once( __DIR__ . '/../includes/DbConnector.php');
	require_once( __DIR__ . '/../includes/FormattingFunctions.php');
	require_once( __DIR__ . '/../data/email_templates_class.php');


	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$template = new EmailTemplateStore($_GET['emt_email_template_id'], TRUE);


	echo $template->get('emt_body');
			

?>
