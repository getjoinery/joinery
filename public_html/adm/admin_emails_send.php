<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/emails_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/email_recipients_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/messages_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/EmailTemplate.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/emails_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/groups_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/group_members_class.php');
	
	$session = SessionControl::get_instance();
	//$session->set_return();
	$session->check_permission(8);

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 11,
		'breadcrumbs' => array(
			'Emails'=>'/admin/admin_emails',
		),
		'session' => $session,
	)
	);	
	
	$pageoptions['title'] = "Send Emails";
	$page->begin_box($pageoptions);
	
	$emails = new MultiEmail(array('scheduleddate' => MultiEmail::SCHEDULED_PAST, 'status' => Email::EMAIL_QUEUED));
	$emails->load();	 


	$settings = Globalvars::get_instance();
	$email_outer_template = $settings->get_setting('bulk_outer_template');
	$email_footer_template = $settings->get_setting('bulk_footer');	

	foreach($emails as $email){


		$recipients = new MultiEmailRecipient(
			array('email_id' => $email->key, 'sent' => FALSE));
		$recipients->load();
		$numrecipients = $recipients->count_all();
		echo $numrecipients . ' recipients queued<br>';
		echo 'Sending email: '. $email->get('eml_subject').' to '.$numrecipients.' recipients.<br>';
			
		$count = 0;
		foreach ($recipients as $recipient){
			$user = new User($recipient->get('erc_usr_user_id'), TRUE);
			//TODO MORE GRANULAR OPT OUT PREFERENCES
			if($user->get('usr_contact_preferences') == 0 && $email->get('eml_type') == Email::TYPE_MARKETING){
				$recipient->set('erc_status', EmailRecipient::UNSUBSCRIBED);
				$recipient->save();	
				echo 'Skipping '. $user->display_name(). ', not subscribed<br>';
				continue;
			}
			
					
			//TODO NEED TO INTEGRATE THE MAILGUN CLASS WITH THE EMAIL CLASS
			$email_template = new EmailTemplate($email->get('eml_message_template_html'), $user, $email_outer_template, $email_footer_template);	
			$email_template->fill_template(array(
					'subject' => $email->get('eml_subject'),
					'preview_text' => $email->get('eml_preview_text'),
					'body' => $email->get('eml_message_html'),
					'utm_source' => urlencode($email->get('eml_subject')), 
			));
			$email_template->email_subject = $email->get('eml_subject');
			$email_template->email_from = $email->get('eml_from_address');
			$email_template->email_from_name = $email->get('eml_from_name');
			
			//MAKE SURE WE DON'T SEND IF ANOTHER THREAD HAS ALREADY DONE IT
			$recipient_check = new EmailRecipient($recipient->key, TRUE);
			if(!$recipient_check->is_sent()){
				$result = $email_template->send(FALSE);
				if($result){
					$recipient->set('erc_sent_time', 'now()');
					$recipient->set('erc_status', EmailRecipient::EMAIL_SENT);
					$recipient->save();	
					echo 'Sent to : '. $user->display_name().'<br>';
					$count++;				
				}
				else{	
					$recipient->set('erc_status', EmailRecipient::SEND_FAILURE);
					$recipient->save();	
				}				
			}
			


		}
		echo 'Sent to '.$count.' recipients<br>';

		$email->set('eml_sent_time', 'now()');
		$email->set('eml_status', Email::EMAIL_SENT);
		$email->save();
		
	

		$sender = new User($email->get('eml_usr_user_id'), TRUE);		
		//TODO NEED TO INTEGRATE THE MAILGUN CLASS WITH THE EMAIL CLASS
		$email_template = new EmailTemplate($email->get('eml_message_template_html'), $sender, $email_outer_template, $email_footer_template);	
		$email_template->fill_template(array(
				'subject' => 'COPY: '.$email->get('eml_subject'),
				'preview_text' => $email->get('eml_preview_text'),
				'body' => $email->get('eml_message_html'),
		));
		$email_template->email_subject = 'COPY: '.$email->get('eml_subject');
		$email_template->email_from = $email->get('eml_from_address');
		$email_template->email_from_name = $email->get('eml_from_name');
		$result = $email_template->send(FALSE);
	
		//SEND ONE TO SENDER
		/*
		$sender = new User($email->get('eml_usr_user_id'), TRUE);
		$email_template = new EmailTemplate($email->get('eml_message_template_html'), $sender);
			$email_template->fill_template(array( 
					'preview_text' => $email->get('eml_preview_text'),
					'body' => $email->get('eml_message_html'),
			));
		$email_template->email_subject = 'COPY: '.$email->get('eml_subject');
		$email_template->email_from = $email->get('eml_from_address');
		$email_template->email_from_name = $email->get('eml_from_name');
		$result = $email_template->send(FALSE);		
*/		
		
	}




	echo '<br /><p><strong>All mail was successfully sent.  <a href="/admin/admin_emails">Return to the emails page</a></strong></p>';

	$page->end_box();
	$page->admin_footer();
	exit();		
	
	
?>