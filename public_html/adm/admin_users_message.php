<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/emails_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/email_recipients_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/groups_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/messages_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_registrants_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/EmailTemplate.php');
	

	$session = SessionControl::get_instance();
	//$session->set_return();
	$session->check_permission(8);
	
	$evt_event_id = LibraryFunctions::fetch_variable('evt_event_id', 0, FALSE, '');
	$grp_group_id = LibraryFunctions::fetch_variable('grp_group_id', 0, FALSE, '');
	$usr_user_id = LibraryFunctions::fetch_variable('usr_user_id', 0, FALSE, '');
	
	if(!$evt_event_id && !$usr_user_id && !$grp_group_id){
		throw new SystemDisplayableError("You must pass an event or a group or a user.");
		exit();				
	}
	else if($evt_event_id && $usr_user_id && $grp_group_id){
		throw new SystemDisplayableError("You cannot pass both an event and a user and a group.");
		exit();			
	}

	$sender = new User($session->get_user_id(), TRUE);

	
	if($evt_event_id){
		$event = new Event($evt_event_id, TRUE);
	}
	else if($grp_group_id){
		$group = new Group($grp_group_id, TRUE);
	}	
	else if($usr_user_id){
		$recipient = new User($usr_user_id, TRUE);
	}
	else{
		throw new SystemDisplayableError("You must pass an event or a user.");
		exit();				
	}
	
	$settings = Globalvars::get_instance();
	$email_inner_template = $settings->get_setting('event_email_inner_template');
	$email_outer_template = $settings->get_setting('event_email_outer_template');
	$email_footer_template = $settings->get_setting('event_email_footer_template');
	
	$numrecipients = 0;
	
	if($_POST){
		
		$_POST['eml_message'] = nl2br($_POST['eml_message']); 
		
		$settings = Globalvars::get_instance();
		$sitename = $settings->get_setting('site_name');
		$fromname = $settings->get_setting('defaultemailname');
		$fromaddress = $settings->get_setting('defaultemail');	
		
			
		$email_record = new Email(NULL);
		$email_record->set('eml_usr_user_id', $sender->key);
		$email_record->set('eml_from_address', $fromaddress);
		$email_record->set('eml_from_name', $fromname);
		$email_record->set('eml_subject', $_POST['eml_subject']);
		$email_record->set('eml_reply_to', $fromaddress);
		//$email_record->set('eml_message_template_plain', NULL); 
		$email_record->set('eml_message_html', $_POST['eml_message']);
		$email_record->set('eml_message_plain', LibraryFunctions::htmlToText($_POST['eml_message']));
		$email_record->set('eml_scheduled_time', 'now()');
		$email_record->set('eml_sent_time', 'now()');
		$email_record->set('eml_status', 5);
		$email_record->save();	
		$email_record->load();	

		
		if($event){
			$event_registrants = NULL;
			//EVENT-ONLY ENTRY, THIS IS SO WE CAN KEEP A RECORD OF THE EVENT MESSAGE
			$message = new Message(NULL);
			$message->set('msg_usr_user_id_sender', $sender->key); 
			$message->set('msg_usr_user_id_recipient', NULL);
			$message->set('msg_evt_event_id', $event->key);
			$message->set('msg_body', $_POST['eml_message']);
			$message->set('msg_sent_time', 'now()');
			$message->save();			
			
			//REGISTRANTS
			$event_registrants = new MultiEventRegistrant(array('event_id' => $event->key), NULL);
			//$numregistrants = $event_registrants->count_all();
			$event_registrants->load();
			
			$settings = Globalvars::get_instance();
			$email_inner_template = $settings->get_setting('event_email_inner_template');
			$email_outer_template = $settings->get_setting('event_email_outer_template');
			$email_footer_template = $settings->get_setting('event_email_footer_template');

			//SAVE THE TEMPLATE
			$email_record->set('eml_message_template_html', $email_inner_template);
			$email_record->save();
			
			foreach ($event_registrants as $event_registrant){
				$email = new EmailTemplate($email_inner_template, NULL, $email_outer_template, $email_footer_template);	
				$email->fill_template(array(
					'subject' => $_POST['eml_subject'],
					'body' => $_POST['eml_message'],
					//'utm_source' => 'email', //use defaults
					//'utm_medium' => 'email', //use defaults
					//'utm_campaign' => ContactType::ToReadable(User::TRANSACTIONAL), 
					'utm_content' => urlencode($_POST['eml_subject']),
					'evr_event_registrant_id' => $event_registrant->key,			
				));
				
				$recipient = new User($event_registrant->get('evr_usr_user_id'), TRUE);
						
				//TODO NEED TO INTEGRATE THE MAILGUN CLASS WITH THE EMAIL CLASS
				$email->add_recipient($recipient->get('usr_email'), $recipient->display_name());

				$message = new Message(NULL);
				$message->set('msg_usr_user_id_sender', $sender->key);
				$message->set('msg_usr_user_id_recipient', $recipient->key);
				if($event){
					$message->set('msg_evt_event_id', $event->key);
				}
				$message->set('msg_body', $_POST['eml_message']);
				$message->set('msg_sent_time', 'now()');
				$message->save();	
				
				$recipient_email = new EmailRecipient(NULL);
				$recipient_email->set('erc_usr_user_id', $recipient->key);
				$recipient_email->set('erc_email', $recipient->get('usr_email'));
				$recipient_email->set('erc_name', $recipient->display_name());
				$recipient_email->set('erc_eml_email_id', $email_record->key);
				$recipient_email->set('erc_sent_time', 'now()');
				$recipient_email->set('erc_status', 1);
				$recipient_email->save();							
				$numrecipients++;
				$result = $email->send();

			}
			
			if($result){
				$email_record->mark_all_recipients_sent();
				$email_record->set('eml_status', 10);
				$email_record->save();	
			}
			
			//SEND ONE TO LEADER
			if($event->get('evt_usr_user_id_leader')){
				$leader = new User($event->get('evt_usr_user_id_leader'), TRUE);
				$email = new EmailTemplate($email_inner_template, $leader, $email_outer_template, $email_footer_template);
				$email->fill_template(array(
					'subject' => 'COPY: '.$_POST['eml_subject'],
					'body' => $_POST['eml_message'],
					//'utm_source' => 'email', //use defaults
					//'utm_medium' => 'email', //use defaults
					//'utm_campaign' => ContactType::ToReadable(User::TRANSACTIONAL), 
					'utm_content' => urlencode($_POST['eml_subject']), 	

				));
				$result = $email->send();
			}				
			
		}
		else if($group){
			$group_members = NULL;
			//EVENT-ONLY ENTRY, THIS IS SO WE CAN KEEP A RECORD OF THE EVENT MESSAGE
			$message = new Message(NULL);
			$message->set('msg_usr_user_id_sender', $sender->key); 
			$message->set('msg_usr_user_id_recipient', NULL);
			$message->set('msg_evt_event_id', $event->key);
			$message->set('msg_body', $_POST['eml_message']);
			$message->set('msg_sent_time', 'now()');
			$message->save();			
			
			//REGISTRANTS
			$group_members = new MultiGroupMember(
				array('group_id' => $group->key),  //SEARCH CRITERIA
				NULL,  //SORT AND DIRECTION array($usrsort=>$usrsdirection)
				NULL,  //NUM PER PAGE
				NULL,  //OFFSET
				'AND'  //AND OR OR
			);
			//$numrecords = $group_members->count_all();
			$group_members->load();
		
			$settings = Globalvars::get_instance();
			$email_inner_template = $settings->get_setting('group_email_inner_template');
			$email_outer_template = $settings->get_setting('group_email_outer_template');
			$email_footer_template = $settings->get_setting('group_email_footer_template');

			//SAVE THE TEMPLATE
			$email_record->set('eml_message_template_html', $email_inner_template);
			$email_record->save();

			$email = new EmailTemplate($email_inner_template, NULL, $email_outer_template, $email_footer_template);			
			$email->fill_template(array(
				'subject' => $_POST['eml_subject'],
				'body' => $_POST['eml_message'],
				//'utm_source' => 'email', //use defaults
				//'utm_medium' => 'email', //use defaults
				//'utm_campaign' => ContactType::ToReadable(User::TRANSACTIONAL), 
				'utm_content' => urlencode($_POST['eml_subject']), 	
			));
			
			foreach ($group_members as $group_member){
				
				$recipient = new User($group_member->get('grm_foreign_key_id'), TRUE);
						
				//TODO NEED TO INTEGRATE THE MAILGUN CLASS WITH THE EMAIL CLASS
				$email->add_recipient($recipient->get('usr_email'), $recipient->display_name());

				$message = new Message(NULL);
				$message->set('msg_usr_user_id_sender', $sender->key);
				$message->set('msg_usr_user_id_recipient', $recipient->key);
				if($event){
					$message->set('msg_evt_event_id', $event->key);
				}
				$message->set('msg_body', $_POST['eml_message']);
				$message->set('msg_sent_time', 'now()');
				$message->save();	
				
				$recipient_email = new EmailRecipient(NULL);
				$recipient_email->set('erc_usr_user_id', $recipient->key);
				$recipient_email->set('erc_email', $recipient->get('usr_email'));
				$recipient_email->set('erc_name', $recipient->display_name());
				$recipient_email->set('erc_eml_email_id', $email_record->key);
				$recipient_email->set('erc_sent_time', 'now()');
				$recipient_email->set('erc_status', 1);
				$recipient_email->save();	
				$numrecipients++;				

			}
			$result = $email->send();
			if($result){
				$email_record->mark_all_recipients_sent();
				$email_record->set('eml_status', 10);
				$email_record->save();
			}
			
		}		
		else{
			
			$settings = Globalvars::get_instance();
			$email_inner_template = $settings->get_setting('individual_email_inner_template');
			$email = new EmailTemplate($email_inner_template, $recipient);
			$email->fill_template(array(
				'subject' => $_POST['eml_subject'],
				'body' => $_POST['eml_message'],
				//'utm_source' => 'email', //use defaults
				//'utm_medium' => 'email', //use defaults
				//'utm_campaign' => ContactType::ToReadable(User::TRANSACTIONAL), 
				'utm_content' => urlencode($_POST['eml_subject']), 	
			));
			$result = $email->send();
			if($result){
				$email_record->set('eml_status', 10);
				$email_record->save();	

				$message = new Message(NULL);
				$message->set('msg_usr_user_id_sender', $sender->key);
				$message->set('msg_usr_user_id_recipient', $recipient->key);
				if($event){
					$message->set('msg_evt_event_id', $event->key);
				}
				$message->set('msg_body', $_POST['eml_message']);
				$message->set('msg_sent_time', 'now()');
				$message->save();	
				
				$recipient_email = new EmailRecipient(NULL);
				$recipient_email->set('erc_usr_user_id', $recipient->key);
				$recipient_email->set('erc_email', $recipient->get('usr_email'));
				$recipient_email->set('erc_name', $recipient->display_name());
				$recipient_email->set('erc_eml_email_id', $email_record->key);
				$recipient_email->set('erc_sent_time', 'now()');
				$recipient_email->set('erc_status', 1);
				$recipient_email->save();
				$numrecipients++;
			}			
						
		}
		
		
		$email_record->set('eml_status', 10);
		$email_record->save();		
		$email_record->mark_all_recipients_sent();
					
		//SEND ONE TO SENDER
		
		$settings = Globalvars::get_instance();
		$email_inner_template = $settings->get_setting('individual_email_inner_template');
		$email = new EmailTemplate($email_inner_template, $sender);
		$email->fill_template(array(
			'subject' => 'COPY: '.$_POST['eml_subject'],
			'body' => $_POST['eml_message'],
			//'utm_source' => 'email', //use defaults
			//'utm_medium' => 'email', //use defaults
			//'utm_campaign' => ContactType::ToReadable(User::TRANSACTIONAL), 
			'utm_content' => urlencode($_POST['eml_subject']), 	
		));
		$result = $email->send();		
		
		
		
		$page = new AdminPage();
		$page->admin_header(	
		array(
			'menu-id'=> 'users',
			'page_title' => 'Email Users',
			'readable_title' => 'Email Users',
			'breadcrumbs' => NULL,
			'session' => $session,
		)
		);
		$page->begin_box();
		if($event){
			echo '<p>Your email was successfully sent to '.$numrecipients.' recipients.  <a href="/admin/admin_event?evt_event_id='.$event->key.'">Return to the event registrants page</a>';
		}
		else if($group){
			echo '<p>Your email was successfully sent to '.$numrecipients.' recipients.  <a href="/admin/admin_groups">Return to the groups page</a>';
		}
		else{
			echo '<p>Your email was successfully sent to '.$numrecipients.' recipients.  <a href="/admin/admin_user?usr_user_id='.$recipient->key.'">Return to the user page</a>';
		}
		$page->end_box();
		$page->admin_footer();
		exit();		
	}
	
	if($event){
		$title = 'Send email to registrants of "'. $event->get('evt_name'). '"';
		$to_field = 'Registrants of "'. $event->get('evt_name');
	}
	else if($group){
		$title = 'Send email to the group: "'. $group->get('grp_name'). '"';
		$to_field = 'Members of "'. $group->get('grp_name');
	}	
	else{
		$title = 'Send email to "'. $recipient->display_name(). '"';
		$to_field = $recipient->display_name();
	}
		
		
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'users',
		'page_title' => 'Email Users',
		'readable_title' => $title,
		'breadcrumbs' => NULL,
		'session' => $session,
	)
	);

	$page->begin_box();


	$formwriter = new FormWriterMaster("form1", TRUE);

	$validation_rules = array();
	$validation_rules['eml_subject']['required']['value'] = 'true';
	$validation_rules['eml_subject']['minlength']['value'] = 10;
	$validation_rules['eml_message']['required']['value'] = 'true';
	$validation_rules['eml_message']['minlength']['value'] = 10;
	echo $formwriter->set_validate($validation_rules);

	echo $formwriter->begin_form("form1", "post", "/admin/admin_users_message");

	$formwriter->text('to-field', 'To:', $to_field, NULL);
	
	$placeholder = 'RE: ';
	if($event){	
		$placeholder = $event->get('evt_name');
	}
	else if($group){
		$placeholder = $group->get('grp_name');
	}
	echo $formwriter->textinput("Subject", "eml_subject", "ctrlHolder", 30, $placeholder, "", 255, ""); 
	
	echo $formwriter->textbox('Message', 'eml_message', 'ctrlHolder', 10, 80, '', '', 'yes');

	if($event){
		echo $formwriter->hiddeninput('evt_event_id', $event->key);
	}
	else if($group){
		echo $formwriter->hiddeninput('grp_group_id', $group->key);
	}
	else{
		echo $formwriter->hiddeninput('usr_user_id', $recipient->key);
	}

	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();

	$page->end_box();

	$page->admin_footer();
?>