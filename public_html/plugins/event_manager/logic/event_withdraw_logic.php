<?php

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('plugins/event_manager/data/events_class.php'));
require_once(PathHelper::getIncludePath('plugins/event_manager/data/event_registrants_class.php'));

function event_withdraw_logic(array $input): LogicResult {
	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('events_active')) {
		return LogicResult::error('This feature is turned off');
	}

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;

	// Get event registrant ID from parameters
	$evr_event_registrant_id = $input['evr_event_registrant_id'] ?? $input['evr_event_registrant_id'] ?? null;
	if (!$evr_event_registrant_id) {
		return LogicResult::error('You must provide a registrant.');
	}
	$evr_event_registrant_id = intval($evr_event_registrant_id);
	$page_vars['evr_event_registrant_id'] = $evr_event_registrant_id;

	if (!empty($_POST)) {
		$session->check_permission(0);

		$confirm = $input['confirm'] ?? null;

		if ($confirm) {
			if (EventRegistrant::check_if_exists($evr_event_registrant_id)) {
				$event_registrant = new EventRegistrant($evr_event_registrant_id, TRUE);
				$event = new Event($event_registrant->get('evr_evt_event_id'), true);
				$event_registrant->assert_can_write($session);
				$event_registrant->remove();

				require_once(PathHelper::getIncludePath('includes/SignalBus.php'));
				SignalBus::dispatch('event.withdrawn', array(
					'event_id'       => $event->key,
					'event_name'     => $event->get('evt_name'),
					'user_id'        => $event_registrant->get('evr_usr_user_id'),
					'source_user_id' => $event_registrant->get('evr_usr_user_id'),
				));

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

function event_withdraw_logic_descriptor(): array {
	return [
		'description'      => 'Withdraw the current user from an event registration.',
		'requires_session' => true,
		'mutates'          => true,
		'ai_agent'         => 'confirm',
		'input'            => [
			'evr_event_registrant_id' => ['type' => 'int', 'required' => true, 'label' => 'Event registrant ID'],
			'confirm' => ['type' => 'bool', 'required' => true, 'label' => 'Confirmation flag'],
		],
	];
}
?>
