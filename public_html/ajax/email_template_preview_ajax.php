<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/email_templates_class.php');


	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$template = new EmailTemplateStore($_GET['emt_email_template_id'], TRUE);


	echo $template->get('emt_body');
			

?>
