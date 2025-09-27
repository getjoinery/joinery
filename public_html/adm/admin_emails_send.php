<?php
	
	PathHelper::requireOnce('includes/AdminPage.php');
	PathHelper::requireOnce('data/emails_class.php');
	PathHelper::requireOnce('data/email_recipients_class.php');
	PathHelper::requireOnce('data/messages_class.php');
	PathHelper::requireOnce('includes/EmailTemplate.php');
	PathHelper::requireOnce('includes/EmailMessage.php');
	PathHelper::requireOnce('includes/EmailSender.php');
	
	PathHelper::requireOnce('data/emails_class.php');
	PathHelper::requireOnce('data/groups_class.php');
	PathHelper::requireOnce('data/group_members_class.php');
	PathHelper::requireOnce('data/mailing_lists_class.php');
	PathHelper::requireOnce('data/mailing_list_registrants_class.php');
	
	$session = SessionControl::get_instance();
	//$session->set_return();
	$session->check_permission(8);

	//IF IT IS A TEST EMAIL
	$send_test = LibraryFunctions::fetch_variable('send_test', false, 0, '');
	if($send_test){
		$email_id = LibraryFunctions::fetch_variable('eml_email_id', NULL, 1, 'To send a test email, you must pass an email.');
		$test_email = new Email($email_id, TRUE);
		$emails = array();
		$emails[] = $test_email;
	}
	else{
		$emails = new MultiEmail(array('scheduleddate' => MultiEmail::SCHEDULED_PAST, 'status' => Email::EMAIL_QUEUED));
		$emails->load();
	}

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'emails-list',
		'breadcrumbs' => array(
			'Emails'=>'/admin/admin_emails',
		),
		'session' => $session,
	)
	);	
	
	$pageoptions['title'] = "Send Emails";
	$page->begin_box($pageoptions);

	$settings = Globalvars::get_instance();

	foreach($emails as $email){
		if($email->get('eml_mlt_mailing_list_id')){
			$mailing_list_id = $email->get('eml_mlt_mailing_list_id');
			$mailing_list = new MailingList($mailing_list_id, TRUE);
			$mailing_list_string = $mailing_list->get('mlt_name');
		}
		else{
			$mailing_list_id = NULL;
			$mailing_list_string = NULL;			
		}

		if(!$send_test){
			$recipients = new MultiEmailRecipient(
			array('email_id' => $email->key, 'sent' => FALSE));
			$recipients->load();
			$numrecipients = $recipients->count_all();
			echo $numrecipients . ' recipients queued<br>';
			echo 'Sending email: '. $email->get('eml_subject').' to '.$numrecipients.' recipients.<br>';

			$count = 0;
			foreach ($recipients as $recipient){
				$user = new User($recipient->get('erc_usr_user_id'), TRUE);
				//CHECK UNSUBSCRIBE AGAIN
				/*
				if($user->is_unsubscribed_to_contact_type($email->get('eml_ctt_contact_type_id'))){
					$recipient->set('erc_status', EmailRecipient::UNSUBSCRIBED);
					$recipient->save();	
					echo 'Skipping '. $user->display_name(). ', not subscribed<br>';
					continue;
				}
				*/

				$message = EmailMessage::fromTemplate($email->get('eml_message_template_html'), [
					'subject' => $email->get('eml_subject'),
					'preview_text' => $email->get('eml_preview_text'),
					'body' => $email->get('eml_message_html'),
					'utm_medium' => 'email',
					'utm_campaign' => $mailing_list_string,
					'utm_content' => urlencode($email->get('eml_subject')),
					'mailing_list_id' => $mailing_list_id,
					'mailing_list_string' => $mailing_list_string,
					'recipient' => $user->export_as_array()
				]);

				$message->subject($email->get('eml_subject'))
						->to($user->get('usr_email'), $user->display_name());

				// Only set custom from if different from defaults
				$settings = Globalvars::get_instance();
				if ($email->get('eml_from_address') != $settings->get_setting('defaultemail')) {
					$message->from($email->get('eml_from_address'), $email->get('eml_from_name'));
				}

				//MAKE SURE WE DON'T SEND IF ANOTHER THREAD HAS ALREADY DONE IT
				$recipient_check = new EmailRecipient($recipient->key, TRUE);
				if(!$recipient_check->is_sent()){
					$sender = new EmailSender();
					$result = $sender->send($message);
					if($result){
						$recipient->set('erc_sent_time', 'now()');
						$recipient->set('erc_status', EmailRecipient::EMAIL_SENT);
						$recipient->save();	
						echo 'Sent to : '. $user->display_name().'<br>';
						$count++;				
					}
					else{	
						$recipient->set('erc_status', EmailRecipient::ERROR);
						$recipient->save();	
						echo '<b>Failed to send to : '. $user->display_name().'</b><br>';
					}				
				}

			}
			echo 'Sent to '.$count.' recipients<br>';

			$email->set('eml_sent_time', 'now()');
			$email->set('eml_status', Email::EMAIL_SENT);
			$email->save();
		}

		$sender = new User($email->get('eml_usr_user_id'), TRUE);		
		//TODO NEED TO INTEGRATE THE MAILGUN CLASS WITH THE EMAIL CLASS
		$test_message = EmailMessage::fromTemplate($email->get('eml_message_template_html'), array(
				'subject' => 'COPY: '.$email->get('eml_subject'),
				'preview_text' => $email->get('eml_preview_text'),
				'body' => $email->get('eml_message_html'),
				'utm_medium' => 'email',
				'utm_campaign' => $mailing_list_string, 
				'utm_content' => urlencode($email->get('eml_subject')), 
				'mailing_list_id' => $mailing_list_id,
				'mailing_list_string' => $mailing_list_string,
				'recipient' => $sender->export_as_array()
		));
		$test_message->subject('COPY: '.$email->get('eml_subject'))
			->from($email->get('eml_from_address'), $email->get('eml_from_name'));

		if($send_test){		
			if(!$session->send_emails()){
				echo '<p><b>Email sending is disabled, so the email is available <a href="/ajax/email_preview_ajax?eml_email_id='.$test_email->key.'">on the preview page</a></b></p>';
			}
			else{
				echo '<p><b>Sending test email to '.$sender->display_name().'</b></p>';
				
				$message = EmailMessage::fromTemplate($test_email->get('eml_message_template_html'), [
					'subject' => $test_email->get('eml_subject'),
					'preview_text' => $test_email->get('eml_preview_text'),
					'body' => $test_email->get('eml_message_html'),
					'utm_medium' => 'email',
					'utm_content' => urlencode($test_email->get('eml_subject')),
					'recipient' => $sender->export_as_array()
				]);

				$message->subject($test_email->get('eml_subject'))
						->to($sender->get('usr_email'), $sender->display_name());

				// Only set custom from if different from defaults
				$settings = Globalvars::get_instance();
				if ($test_email->get('eml_from_address') != $settings->get_setting('defaultemail')) {
					$message->from($test_email->get('eml_from_address'), $test_email->get('eml_from_name'));
				}

				$emailSender = new EmailSender();
				$result = $emailSender->send($message);
				if($result){
					echo '<p><b>Send succeeded.</b></p>';
				}
				else{
					 echo '<p><b>Send failed.</b></p>';
				}
			}
		}
		
	}

	echo '<br /><p><strong>All mail was successfully sent.  <a href="/admin/admin_emails">Return to the emails page</a></strong></p>';

	$page->end_box();
	$page->admin_footer();
	exit();		

?>