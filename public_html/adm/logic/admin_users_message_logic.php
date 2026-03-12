<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_users_message_logic($get, $post) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	require_once(PathHelper::getIncludePath('data/emails_class.php'));
	require_once(PathHelper::getIncludePath('data/email_recipients_class.php'));
	require_once(PathHelper::getIncludePath('data/events_class.php'));
	require_once(PathHelper::getIncludePath('data/groups_class.php'));
	require_once(PathHelper::getIncludePath('data/messages_class.php'));
	require_once(PathHelper::getIncludePath('data/event_registrants_class.php'));
	require_once(PathHelper::getIncludePath('data/event_waiting_lists_class.php'));
	require_once(PathHelper::getIncludePath('includes/EmailTemplate.php'));
	require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));
	require_once(PathHelper::getIncludePath('includes/EmailSender.php'));

	$session = SessionControl::get_instance();
	//$session->set_return();
	$session->check_permission(8);

	$evt_event_id = LibraryFunctions::fetch_variable('evt_event_id', 0, FALSE, '');
	$grp_group_id = LibraryFunctions::fetch_variable('grp_group_id', 0, FALSE, '');
	$usr_user_id = LibraryFunctions::fetch_variable('usr_user_id', 0, FALSE, '');

	if(!$evt_event_id && !$usr_user_id && !$grp_group_id){
		return LogicResult::error("You must pass an event or a group or a user.");
	}
	else if($evt_event_id && $usr_user_id && $grp_group_id){
		return LogicResult::error("You cannot pass both an event and a user and a group.");
	}

	$sender = new User($session->get_user_id(), TRUE);

	$event = NULL;
	$group = NULL;
	$recipient = NULL;

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
		return LogicResult::error("You must pass an event or a user.");
	}

	$settings = Globalvars::get_instance();

	if(!$settings->get_setting('mailgun_domain') || !$settings->get_setting('mailgun_api_key')){
		return LogicResult::error('Mailgun credentials are not in the db or settings.');
	}

	$email_inner_template = $settings->get_setting('event_email_inner_template');
	$email_outer_template = $settings->get_setting('event_email_outer_template');
	$email_footer_template = $settings->get_setting('event_email_footer_template');

	$numrecipients = 0;

	if($post){

		$post['eml_message'] = nl2br($post['eml_message']);

		$settings = Globalvars::get_instance();
		$sitename = $settings->get_setting('site_name');
		$fromname = $settings->get_setting('defaultemailname');
		$fromaddress = $settings->get_setting('defaultemail');

		$email_record = new Email(NULL);
		$email_record->set('eml_usr_user_id', $sender->key);
		$email_record->set('eml_from_address', $fromaddress);
		$email_record->set('eml_from_name', $fromname);
		$email_record->set('eml_subject', $post['eml_subject']);
		$email_record->set('eml_reply_to', $fromaddress);
		//$email_record->set('eml_message_template_plain', NULL);
		$email_record->set('eml_message_html', $post['eml_message']);
		$email_record->set('eml_message_plain', LibraryFunctions::htmlToText($post['eml_message']));
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
			$message->set('msg_body', $post['eml_message']);
			$message->set('msg_sent_time', 'now()');
			$message->save();

			//REGISTRANTS OR WAITING LIST
			if(isset($get['waiting_list']) || isset($post['waiting_list'])){
				$event_registrants = new MultiWaitingList(array('event_id' => $event->key), NULL);
				$event_registrants->load();
			}
			else{
				$event_registrants = new MultiEventRegistrant(array('event_id' => $event->key, 'expired' => false), NULL);
				//$numregistrants = $event_registrants->count_all();
				$event_registrants->load();
			}

			$settings = Globalvars::get_instance();
			$email_inner_template = $settings->get_setting('event_email_inner_template');
			$email_outer_template = $settings->get_setting('event_email_outer_template');
			$email_footer_template = $settings->get_setting('event_email_footer_template');

			//SAVE THE TEMPLATE
			$email_record->set('eml_message_template_html', $email_inner_template);
			$email_record->save();

			foreach ($event_registrants as $event_registrant){
				// Using new EmailMessage system instead

				if(isset($get['waiting_list']) || isset($post['waiting_list'])){
					$recipient_user = new User($event_registrant->get('ewl_usr_user_id'), TRUE);
					// Template variables handled in new system below
				}
				else{
					$recipient_user = new User($event_registrant->get('evr_usr_user_id'), TRUE);
					// Template variables handled in new system below
				}

				//TODO NEED TO INTEGRATE THE MAILGUN CLASS WITH THE EMAIL CLASS
				// Recipient added in new system above

				$message = new Message(NULL);
				$message->set('msg_usr_user_id_sender', $sender->key);
				$message->set('msg_usr_user_id_recipient', $recipient_user->key);
				if($event){
					$message->set('msg_evt_event_id', $event->key);
				}
				$message->set('msg_body', $post['eml_message']);
				$message->set('msg_sent_time', 'now()');
				$message->save();

				$recipient_email = new EmailRecipient(NULL);
				$recipient_email->set('erc_usr_user_id', $recipient_user->key);
				$recipient_email->set('erc_email', $recipient_user->get('usr_email'));
				$recipient_email->set('erc_name', $recipient_user->display_name());
				$recipient_email->set('erc_eml_email_id', $email_record->key);
				$recipient_email->set('erc_sent_time', 'now()');
				$recipient_email->set('erc_status', 1);
				$recipient_email->save();
				$numrecipients++;
				// Create and send using new system
			$message_obj = EmailMessage::fromTemplate($email_inner_template, [
				'subject' => $post['eml_subject'],
				'body' => $post['eml_message'],
				'utm_medium' => 'email',
				'utm_content' => urlencode($post['eml_subject']),
				'recipient' => $recipient_user->export_as_array()
			]);
			$message_obj->subject($post['eml_subject'])
					   ->to($recipient_user->get('usr_email'), $recipient_user->display_name());
			$sender_obj = new EmailSender();
			$result = $sender_obj->send($message_obj);

			}

			if($result){
				$email_record->mark_all_recipients_sent();
				$email_record->set('eml_status', 10);
				$email_record->save();
			}

			//SEND ONE TO LEADER
			if(!(isset($get['waiting_list']) || isset($post['waiting_list']))){
				if($event->get('evt_usr_user_id_leader')){
					$leader = new User($event->get('evt_usr_user_id_leader'), TRUE);
					// Using new EmailMessage system instead
					// Template variables handled in new system below
					// Create and send using new system
			$message_obj = EmailMessage::fromTemplate($email_inner_template, [
				'subject' => 'COPY: '.$post['eml_subject'],
				'body' => $post['eml_message'],
				'utm_medium' => 'email',
				'utm_content' => urlencode($post['eml_subject']),
				'recipient' => $leader->export_as_array()
			]);
			$message_obj->subject('COPY: '.$post['eml_subject'])
					   ->to($leader->get('usr_email'), $leader->display_name());
			$sender_obj = new EmailSender();
			$result = $sender_obj->send($message_obj);
				}
			}

		}
		else if($group){
			$group_members = NULL;
			//EVENT-ONLY ENTRY, THIS IS SO WE CAN KEEP A RECORD OF THE EVENT MESSAGE
			$message = new Message(NULL);
			$message->set('msg_usr_user_id_sender', $sender->key);
			$message->set('msg_usr_user_id_recipient', NULL);
			$message->set('msg_evt_event_id', $event->key);
			$message->set('msg_body', $post['eml_message']);
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

			// Using new EmailMessage system instead
			// Template variables handled in new system below

			foreach ($group_members as $group_member){

				$recipient_user = new User($group_member->get('grm_foreign_key_id'), TRUE);

				//TODO NEED TO INTEGRATE THE MAILGUN CLASS WITH THE EMAIL CLASS
				// Recipient added in new system above

				$message = new Message(NULL);
				$message->set('msg_usr_user_id_sender', $sender->key);
				$message->set('msg_usr_user_id_recipient', $recipient_user->key);
				if($event){
					$message->set('msg_evt_event_id', $event->key);
				}
				$message->set('msg_body', $post['eml_message']);
				$message->set('msg_sent_time', 'now()');
				$message->save();

				$recipient_email = new EmailRecipient(NULL);
				$recipient_email->set('erc_usr_user_id', $recipient_user->key);
				$recipient_email->set('erc_email', $recipient_user->get('usr_email'));
				$recipient_email->set('erc_name', $recipient_user->display_name());
				$recipient_email->set('erc_eml_email_id', $email_record->key);
				$recipient_email->set('erc_sent_time', 'now()');
				$recipient_email->set('erc_status', 1);
				$recipient_email->save();
				$numrecipients++;

			}
			// Create and send using new system
			$message_obj = EmailMessage::fromTemplate($email_inner_template, [
				'subject' => $post['eml_subject'],
				'body' => $post['eml_message'],
				'utm_medium' => 'email',
				'utm_content' => urlencode($post['eml_subject']),
				'recipient' => $recipient_user->export_as_array()
			]);
			$message_obj->subject($post['eml_subject'])
					   ->to($recipient_user->get('usr_email'), $recipient_user->display_name());
			$sender_obj = new EmailSender();
			$result = $sender_obj->send($message_obj);
			if($result){
				$email_record->mark_all_recipients_sent();
				$email_record->set('eml_status', 10);
				$email_record->save();
			}

		}
		else{

			$settings = Globalvars::get_instance();
			$email_inner_template = $settings->get_setting('individual_email_inner_template');
			// Using new EmailMessage system instead
			// Template variables handled in new system below
			// Create and send using new system
			$message_obj = EmailMessage::fromTemplate($email_inner_template, [
				'subject' => $post['eml_subject'],
				'body' => $post['eml_message'],
				'utm_medium' => 'email',
				'utm_content' => urlencode($post['eml_subject']),
				'recipient' => $recipient->export_as_array()
			]);
			$message_obj->subject($post['eml_subject'])
					   ->to($recipient->get('usr_email'), $recipient->display_name());
			$sender_obj = new EmailSender();
			$result = $sender_obj->send($message_obj);
			if($result){
				$email_record->set('eml_status', 10);
				$email_record->save();

				$message = new Message(NULL);
				$message->set('msg_usr_user_id_sender', $sender->key);
				$message->set('msg_usr_user_id_recipient', $recipient->key);
				if($event){
					$message->set('msg_evt_event_id', $event->key);
				}
				$message->set('msg_body', $post['eml_message']);
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
		// Using new EmailMessage system instead
		$result = EmailSender::sendTemplate($email_inner_template,
			$sender->get('usr_email'),
			[
				'subject' => 'COPY: '.$post['eml_subject'],
				'body' => $post['eml_message'],
				'utm_medium' => 'email',
				'utm_content' => urlencode($post['eml_subject']),
				'recipient' => $sender->export_as_array()
			]
		);

		return LogicResult::render(array(
			'show_success' => true,
			'numrecipients' => $numrecipients,
			'event' => $event,
			'group' => $group,
			'recipient' => $recipient
		));
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

	return LogicResult::render(array(
		'show_success' => false,
		'title' => $title,
		'to_field' => $to_field,
		'event' => $event,
		'group' => $group,
		'recipient' => $recipient
	));
}
?>
