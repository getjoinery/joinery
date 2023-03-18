<?php
	require_once( __DIR__ . '/../includes/Globalvars.php');
	require_once( __DIR__ . '/../includes/DbConnector.php');
	require_once( __DIR__ . '/../includes/EmailTemplate.php');
	require_once( __DIR__ . '/../data/emails_class.php');

	header('Content-type: text/html');

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	
	$email = new Email($_GET['eml_email_id'], TRUE);
	$recipient = new User($session->get_user_id(), TRUE);
	
	//TODO NEED TO INTEGRATE THE MAILGUN CLASS WITH THE EMAIL CLASS
	//MAKE THE RECIPIENT THE CURRENT PERSON	
	$email_template = new EmailTemplate($email->get('eml_message_template_html'), $recipient, $email_outer_template, $email_footer_template);	
	$email_template->fill_template(array(
			'subject' => $email->get('eml_subject'),
			'preview_text' => $email->get('eml_preview_text'),
			'body' => $email->get('eml_message_html'),
			//'utm_source' => 'email', //use defaults
			//'utm_medium' => 'email', //use defaults
			'utm_campaign' => ContactType::ToReadable($email->get('eml_ctt_contact_type_id')), 
			'utm_content' => urlencode($email->get('eml_subject')), 
			'content_type' => $email->get('eml_ctt_contact_type_id'),
			'content_type_string' => ContactType::ToReadable($email->get('eml_ctt_contact_type_id')),
	));
	$email_template->email_subject = $email->get('eml_subject');
	$email_template->email_from = $email->get('eml_from_address');
	$email_template->email_from_name = $email->get('eml_from_name');
	

	echo $email_template->email_html;
			

?>
