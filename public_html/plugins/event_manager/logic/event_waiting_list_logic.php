<?php


function event_waiting_list_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('plugins/event_manager/data/events_class.php'));
	require_once(PathHelper::getIncludePath('plugins/event_manager/data/waiting_lists_class.php'));

	$event_id = $input['event_id'] ?? null;
	$event_id = LibraryFunctions::fetch_variable_local($event_id, '', 1, 'Event id is missing', '', 'safemode', 'int');

	$session = SessionControl::get_instance();
	$page_vars = [];
	$page_vars['session'] = $session;

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;


	if(!$settings->get_setting('events_active')){
		return LogicResult::error('This feature is turned off');
	}


	$event = new Event($event_id, TRUE);

	if(!$event || !$event->get('evt_visibility') || $event->get('evt_delete_time')){
		if(!$event->can_read($session)){
			require_once(LibraryFunctions::display_404_page());
		}
	}

	$page_vars['event'] = $event;

	if (!empty($_POST)) {

		$user = NULL;
		$guest = false;
		if($session->get_user_id()){
			$user = new User($session->get_user_id(), TRUE);
		}
		else{
			$formwriter = new FormWriterV2HTML5('form1');
			if(!$formwriter->honeypot_check($_POST)){
				LibraryFunctions::display_404_page();
			}

			if(!$formwriter->antispam_question_check($_POST)){
				return LogicResult::error('Please type the correct value into the anti-spam field.');
			}


			$captcha_success = $formwriter->captcha_check($_POST);
			if (!$captcha_success) {
				$errormsg = 'Sorry, '.strip_tags($_POST['usr_first_name']).' '.strip_tags($_POST['usr_last_name']).', you must click the CAPTCHA to submit the form.';
				return LogicResult::error($errormsg);
			}

			$existing = User::GetByEmail($_POST['usr_email'] ?? '');
			if($existing && $existing->get('usr_delete_time')){
				return LogicResult::error(User::CLOSED_ACCOUNT_MESSAGE);
			}
			if($existing){
				// Typing an address proves nothing about owning it: a visitor never
				// acts for an existing account, whose owner signs in to join.
				return LogicResult::error('An account already uses that email address. Please sign in to join the waiting list.');
			}

			// A visitor's signup: no one is signed in to own the new account, its
			// list subscription or its waiting-list row. The honeypot, anti-spam
			// question and CAPTCHA above admitted this request.
			$guest = true;
			$user = SystemBase::rule_checked_write(function () use ($settings) {
				$user = User::CreateNew(array(
					'usr_first_name' => $_POST['usr_first_name'],
					'usr_last_name' => $_POST['usr_last_name'],
					'usr_email' => $_POST['usr_email'],
					'password' => NULL,
					'send_emails' => true
				));
				if (!empty($_POST['privacy'])) {
					$user->set('usr_terms_accepted_time', gmdate('Y-m-d H:i:s'));
				}
				if(!empty($_POST['usr_nickname'])){
					$user->set('usr_nickname', $_POST['usr_nickname']);
				}
				$user->set('usr_timezone', $_POST['usr_timezone'] ?? '');
				$user->prepare();
				$user->save();

				if(!empty($_POST['newsletter']) && $settings->get_setting('default_mailing_list')){
					$user->add_user_to_mailing_lists($settings->get_setting('default_mailing_list'));
				}
				return $user;
			});
		}

		//ADD TO WAITING LIST
		$waiting_list = new WaitingList(NULL);
		$waiting_list->set('ewl_usr_user_id', $user->key);
		$waiting_list->set('ewl_evt_event_id', $event->key);
		$result = WaitingList::CheckIfExists($waiting_list->get('ewl_usr_user_id'), $waiting_list->get('ewl_evt_event_id'));
		if($result){
			$page_vars['display_message'] = 'You are already on the '.$event->get('evt_name').' waiting list.';
			$page_vars['message_type'] = 'success';
		}
		else{
			if($guest){
				SystemBase::rule_checked_write(function () use ($waiting_list) { $waiting_list->save(); });
			}
			else{
				$waiting_list->save();
			}

			require_once(PathHelper::getIncludePath('includes/SignalBus.php'));
			SignalBus::dispatch('event.waitlisted', array(
				'event_id'       => $event->key,
				'event_name'     => $event->get('evt_name'),
				'user_id'        => $user->key,
				'source_user_id' => $user->key,
			));

			$page_vars['display_message'] = 'You have been added to the '.$event->get('evt_name').' waiting list.';
			$page_vars['message_type'] = 'success';
		}


	}

	return LogicResult::render($page_vars);
}

function event_waiting_list_logic_descriptor(): array {
	return [
		'auth' => ['min_user_permission' => 0],
		'description'      => 'Add the current user (or a guest) to an event\'s waiting list.',
		'requires_session' => false,
		'mutates'          => true,
		'ai_agent'         => 'confirm',
		'input'            => [
			'event_id' => ['type' => 'int', 'required' => true, 'label' => 'Event ID'],
			'usr_first_name' => ['type' => 'string', 'required' => false, 'label' => 'First name (guests)'],
			'usr_last_name' => ['type' => 'string', 'required' => false, 'label' => 'Last name (guests)'],
			'usr_email' => ['type' => 'email', 'required' => false, 'label' => 'Email (guests)'],
			'newsletter' => ['type' => 'bool', 'required' => false, 'label' => 'Subscribe to newsletter'],
		],
	];
}
?>
