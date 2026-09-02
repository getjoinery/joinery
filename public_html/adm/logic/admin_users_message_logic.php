<?php
/**
 * Group send: an email to an audience — an event's registrants, its waiting
 * list, a group's members, or one person — queued as an email campaign.
 *
 * The page creates one Email row, attaches the audience and the copies as
 * recipient groups, and queues it. The scheduled sender expands the audience
 * and delivers in the background, resuming after a failure instead of
 * repeating. Nothing is sent inside this request.
 *
 * @version 2.0.0
 */
function admin_users_message_logic(array $input): LogicResult {

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	$evt_event_id = (int)LibraryFunctions::fetch_variable('evt_event_id', 0, FALSE, '');
	$grp_group_id = (int)LibraryFunctions::fetch_variable('grp_group_id', 0, FALSE, '');
	$usr_user_id  = (int)LibraryFunctions::fetch_variable('usr_user_id', 0, FALSE, '');
	$waiting_list = !empty($input['waiting_list']);

	$targets = ($evt_event_id ? 1 : 0) + ($grp_group_id ? 1 : 0) + ($usr_user_id ? 1 : 0);
	if ($targets === 0) {
		return LogicResult::error('You must pass an event or a group or a user.');
	}
	if ($targets > 1) {
		return LogicResult::error('Pass one of an event, a group or a user — not more than one.');
	}

	$sender = new User($session->get_user_id(), TRUE);
	$settings = Globalvars::get_instance();

	$event = NULL;
	$group = NULL;
	$recipient = NULL;

	// Each entry names its audience as recipient groups, and the inner
	// template the email is rendered with. The sender always gets a copy.
	$recipient_groups = array();

	if ($evt_event_id) {
		if (!PluginHelper::isPluginActive('event_manager')) {
			return LogicResult::error('Event messaging requires the Event Manager plugin.');
		}
		$event = new Event($evt_event_id, TRUE);
		if (!$event->key) {
			return LogicResult::error('No such event.');
		}
		$inner_template = $settings->get_setting('event_email_inner_template');
		if ($waiting_list) {
			$recipient_groups[] = array('event_waiting_list', $event->key);
		} else {
			$recipient_groups[] = array('event', $event->key);
			if ($event->get('evt_usr_user_id_leader')) {
				$recipient_groups[] = array('user', (int)$event->get('evt_usr_user_id_leader'));
			}
		}
	}
	else if ($grp_group_id) {
		$group = new Group($grp_group_id, TRUE);
		if (!$group->key) {
			return LogicResult::error('No such group.');
		}
		$inner_template = $settings->get_setting('group_email_inner_template');
		$recipient_groups[] = array('group', $group->key);
	}
	else {
		$recipient = new User($usr_user_id, TRUE);
		if (!$recipient->key) {
			return LogicResult::error('No such user.');
		}
		$inner_template = $settings->get_setting('individual_email_inner_template');
		$recipient_groups[] = array('user', $recipient->key);
	}
	$recipient_groups[] = array('user', $sender->key);

	if (LibraryFunctions::isFormSubmission()) {
		$subject = trim((string)($input['eml_subject'] ?? ''));
		$body = nl2br((string)($input['eml_message'] ?? ''));
		if ($subject === '' || trim(strip_tags($body)) === '') {
			return LogicResult::error('A subject and a message are required.');
		}

		$email = new Email(NULL);
		$email->set('eml_usr_user_id', $sender->key);
		$email->set('eml_from_address', $settings->get_setting('defaultemail'));
		$email->set('eml_from_name', $settings->get_setting('defaultemailname'));
		$email->set('eml_reply_to', $settings->get_setting('defaultemail'));
		$email->set('eml_subject', $subject);
		$email->set('eml_message_html', $body);
		$email->set('eml_message_plain', LibraryFunctions::htmlToText($body));
		$email->set('eml_message_template_html', $inner_template);
		$email->set('eml_status', Email::EMAIL_CREATED);
		$email->save();
		$email->load();

		foreach ($recipient_groups as $rg) {
			$email->add_recipient_group($rg[0], $rg[1]);
		}

		$numrecipients = $email->queue();

		return LogicResult::render(array(
			'show_success' => true,
			'numrecipients' => $numrecipients,
			'email' => $email,
			'event' => $event,
			'group' => $group,
			'recipient' => $recipient,
			'waiting_list' => $waiting_list,
		));
	}

	if ($event) {
		$audience = $waiting_list ? 'the waiting list for' : 'registrants of';
		$title = 'Send email to ' . $audience . ' "' . $event->get('evt_name') . '"';
		$to_field = ucfirst($audience) . ' "' . $event->get('evt_name') . '"';
	}
	else if ($group) {
		$title = 'Send email to the group: "' . $group->get('grp_name') . '"';
		$to_field = 'Members of "' . $group->get('grp_name') . '"';
	}
	else {
		$title = 'Send email to "' . $recipient->display_name() . '"';
		$to_field = $recipient->display_name();
	}

	return LogicResult::render(array(
		'show_success' => false,
		'title' => $title,
		'to_field' => $to_field,
		'event' => $event,
		'group' => $group,
		'recipient' => $recipient,
		'waiting_list' => $waiting_list,
	));
}
