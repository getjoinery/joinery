<?php

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/events_class.php'));
require_once(PathHelper::getIncludePath('data/event_registrants_class.php'));

function event_withdraw_logic($get_vars, $post_vars) {
	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('events_active')) {
		return LogicResult::error('This feature is turned off');
	}

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;

	// Get event registrant ID from parameters
	$evr_event_registrant_id = $post_vars['evr_event_registrant_id'] ?? $get_vars['evr_event_registrant_id'] ?? null;
	if (!$evr_event_registrant_id) {
		return LogicResult::error('You must provide a registrant.');
	}
	$evr_event_registrant_id = intval($evr_event_registrant_id);
	$page_vars['evr_event_registrant_id'] = $evr_event_registrant_id;

	if ($post_vars) {
		$session->check_permission(0);

		$confirm = $post_vars['confirm'] ?? null;

		if ($confirm) {
			if (EventRegistrant::check_if_exists($evr_event_registrant_id)) {
				$event_registrant = new EventRegistrant($evr_event_registrant_id, TRUE);
				$event = new Event($event_registrant->get('evr_evt_event_id'), true);
				$event_registrant->authenticate_write(array(
					'current_user_id' => $session->get_user_id(),
					'current_user_permission' => $session->get_permission()
				));
				$event_registrant->remove();

				$msgtxt = 'You have now withdrawn from ' . $event->get('evt_name') . '.';
				$message = new DisplayMessage($msgtxt, 'Success', '/\/profile\/account/', DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox', TRUE);
				$session->save_message($message);
			} else {
				$msgtxt = 'You are no longer registered for the event.';
				$message = new DisplayMessage($msgtxt, 'Success', '/\/profile\/account/', DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox', TRUE);
				$session->save_message($message);
			}

			return LogicResult::redirect('/profile');
		}
	}

	// GET request — load data for the confirmation form
	if (EventRegistrant::check_if_exists($evr_event_registrant_id)) {
		$event_registrant = new EventRegistrant($evr_event_registrant_id, true);
		$user = new User($event_registrant->get('evr_usr_user_id'), TRUE);
		$event = new Event($event_registrant->get('evr_evt_event_id'), true);
		$page_vars['event_registrant'] = $event_registrant;
		$page_vars['event'] = $event;
	} else {
		$page_vars['event_registrant'] = null;
	}

	return LogicResult::render($page_vars);
}

function event_withdraw_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'Withdraw from event',
	];
}
?>
