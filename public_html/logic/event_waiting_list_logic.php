<?php

require_once(PathHelper::getThemeFilePath('FormWriter.php', 'includes'));

function event_waiting_list_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/events_class.php'));
	require_once(PathHelper::getIncludePath('data/event_waiting_lists_class.php'));

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
		if($session->get_permission() < 5){
			require_once(LibraryFunctions::display_404_page());
		}
	}

	$page_vars['event'] = $event;

	if (!empty($_POST)) {

		$user = NULL;
		if($session->get_user_id()){
			$user = new User($session->get_user_id(), TRUE);
		}
		else{
			$formwriter = new FormWriter('form1');
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

			if(!$user = User::GetByEmail($_POST['usr_email'])){
				$data = array(
					'usr_first_name' => $_POST['usr_first_name'],
					'usr_last_name' => $_POST['usr_last_name'],
					'usr_email' => $_POST['usr_email'],
					'password' => NULL,
					'send_emails' => true
				);
				$user = User::CreateNew($data);
				if (!empty($_POST['privacy'])) {
					$user->set('usr_terms_accepted_time', gmdate('Y-m-d H:i:s'));
				}
			}

			if($_POST['usr_nickname']){
				$user->set('usr_nickname', $_POST['usr_nickname']);
			}

			$user->set('usr_timezone', $_POST['usr_timezone']);
			$user->prepare();
			$user->save();

			if($_POST['newsletter']){
				if($settings->get_setting('default_mailing_list')){
					$messages = $user->add_user_to_mailing_lists($settings->get_setting('default_mailing_list'));
				}
			}
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
			$waiting_list->save();

			require_once(PathHelper::getIncludePath('includes/Notify.php'));
			Notify::fire('event.waitlisted', array(
				'title' => 'Waiting list join: ' . $event->get('evt_name'),
				'body'  => 'Someone joined the waiting list for ' . $event->get('evt_name') . '.',
				'link'  => '/admin/admin_events',
				'source_user_id' => $user->key,
			));

			$page_vars['display_message'] = 'You have been added to the '.$event->get('evt_name').' waiting list.';
			$page_vars['message_type'] = 'success';
		}


	}

	return LogicResult::render($page_vars);
}

function event_waiting_list_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'Join event waiting list',
	];
}

function event_waiting_list_logic_descriptor(): array {
	return [
		'description'      => 'Add the current user (or a guest) to an event\'s waiting list.',
		'requires_session' => false,
		'mutates'          => true,
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
