<?php
	require_once( __DIR__ . '/../includes/PathHelper.php');
	
	require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
	require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
	require_once(PathHelper::getIncludePath('includes/EmailTemplate.php'));
	require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));
	require_once(PathHelper::getIncludePath('data/emails_class.php'));
	require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));

	header('Content-type: text/html');

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	
	$email = new Email($_GET['eml_email_id'], TRUE);
	
	if($email->get('eml_status') == Email::EMAIL_SENT){
		echo $email->get('eml_message_html');
	}
	else{
		$recipient = new User($session->get_user_id(), TRUE);
		
		if($email->get('eml_mlt_mailing_list_id')){
			$mailing_list_id = $email->get('eml_mlt_mailing_list_id');
			$mailing_list = new MailingList($mailing_list_id, TRUE);
			$mailing_list_string = $mailing_list->get('mlt_name');
		}
		else{
			$mailing_list_id = NULL;
			$mailing_list_string = NULL;			
		}
		
		$message = EmailMessage::fromTemplate($email->get('eml_message_template_html'), array(
			'subject' => 'COPY: '.$email->get('eml_subject'),
			'preview_text' => $email->get('eml_preview_text'),
			'body' => $email->get('eml_message_html'),
			'utm_medium' => 'email',
			'utm_campaign' => $mailing_list_string, 
			'utm_content' => urlencode($email->get('eml_subject')), 
			'mailing_list_id' => $mailing_list_id,
			'mailing_list_string' => $mailing_list_string,
			'recipient' => $recipient->export_as_array()
		));

		echo $message->getHtmlBody();
	}
			

?>
