<?php
	require_once( __DIR__ . '/../includes/Globalvars.php');
	require_once( __DIR__ . '/../includes/DbConnector.php');
	require_once( __DIR__ . '/../includes/EmailTemplate.php');
	require_once( __DIR__ . '/../data/emails_class.php');
	require_once( __DIR__ . '/../data/mailing_lists_class.php');

	header('Content-type: text/html');

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	
	$email = new Email($_GET['eml_email_id'], TRUE);
	
	if($email->get('eml_status') == Email::EMAIL_SENT){
		echo $email->get('eml_message_html');
	}
	else{
		$recipient = new User($session->get_user_id(), TRUE);
		
		//TODO NEED TO INTEGRATE THE MAILGUN CLASS WITH THE EMAIL CLASS
		//MAKE THE RECIPIENT THE CURRENT PERSON	
		
		if($email->get('eml_mlt_mailing_list_id')){
			$mailing_list_id = $email->get('eml_mlt_mailing_list_id');
			$mailing_list = new MailingList($mailing_list_id, TRUE);
			$mailing_list_string = $mailing_list->get('mlt_name');
		}
		else{
			$mailing_list_id = NULL;
			$mailing_list_string = NULL;			
		}
		
		$email_template = new EmailTemplate($email->get('eml_message_template_html'), $recipient);	
		$email_template->fill_template(array(
			'subject' => 'COPY: '.$email->get('eml_subject'),
			'preview_text' => $email->get('eml_preview_text'),
			'body' => $email->get('eml_message_html'),
			//'utm_source' => 'email', //use defaults
			'utm_medium' => 'email', //use defaults
			'utm_campaign' => $mailing_list_string, 
			'utm_content' => urlencode($email->get('eml_subject')), 
			'mailing_list_id' => $mailing_list_id,
			'mailing_list_string' => $mailing_list_string,
		));
		$email_template->email_subject = $email->get('eml_subject');
		$email_template->email_from = $email->get('eml_from_address');
		$email_template->email_from_name = $email->get('eml_from_name');
		

		echo $email_template->email_html;
	}
			

?>
