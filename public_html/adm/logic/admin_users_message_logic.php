<?php
/**
 * Direct message: an email to an audience (an event's registrants, its
 * waiting list, a group's members, or one person) about the thing they
 * belong to (spec email_lists_plugin §3).
 *
 * The page queues a DirectMessage: one row for the message and one per
 * recipient. The SendQueuedEmails task sends them in the background,
 * resuming after a failure instead of repeating. Nothing is sent inside this
 * request. The sender always gets a copy; an event's leader gets one too.
 *
 * @version 3.0.0 - a DirectMessage, not a campaign Email row
 * @version 2.0.0
 */
function admin_users_message_logic(array $input): LogicResult {

	$session = SessionControl::get_instance();

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

	// The audience: a provider and its reference, plus anyone else who gets a
	// copy. The sender always does.
	$provider = NULL;
	$reference_id = 0;
	$also_user_ids = array();

	if ($evt_event_id) {
		if (!PluginHelper::isPluginActive('event_manager')) {
			return LogicResult::error('Event messaging requires the Event Manager plugin.');
		}
		$event = new Event($evt_event_id, TRUE);
		if (!$event->key) {
			return LogicResult::error('No such event.');
		}
		$inner_template = $settings->get_setting('event_email_inner_template');
		$reference_id = (int)$event->key;
		if ($waiting_list) {
			$provider = 'event_waiting_list';
		} else {
			$provider = 'event';
			if ($event->get('evt_usr_user_id_leader')) {
				$also_user_ids[] = (int)$event->get('evt_usr_user_id_leader');
			}
		}
	}
	else if ($grp_group_id) {
		$group = new Group($grp_group_id, TRUE);
		if (!$group->key) {
			return LogicResult::error('No such group.');
		}
		$inner_template = $settings->get_setting('group_email_inner_template');
		$provider = 'group';
		$reference_id = (int)$group->key;
	}
	else {
		$recipient = new User($usr_user_id, TRUE);
		if (!$recipient->key) {
			return LogicResult::error('No such user.');
		}
		$inner_template = $settings->get_setting('individual_email_inner_template');
		$provider = 'user';
		$reference_id = (int)$recipient->key;
	}
	$also_user_ids[] = (int)$sender->key;

	if (LibraryFunctions::isFormSubmission()) {
		// Sending is mail to other people about themselves: personal work.
		Access::demand('personal:write', $session);
		$subject = trim((string)($input['eml_subject'] ?? ''));
		$body = nl2br((string)($input['eml_message'] ?? ''));
		if ($subject === '' || trim(strip_tags($body)) === '') {
			return LogicResult::error('A subject and a message are required.');
		}

		$message = DirectMessage::queue_to($sender, $provider, $reference_id, $subject, $body, $inner_template, $also_user_ids);
		$numrecipients = (int)$message->get('dmg_recipient_count');

		return LogicResult::render(array(
			'show_success' => true,
			'numrecipients' => $numrecipients,
			'message' => $message,
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
