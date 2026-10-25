<?php

function lists_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));

	$settings = Globalvars::get_instance();
	$page_vars = [];
	$page_vars['settings'] = $settings;

	if(!$settings->get_setting('mailing_lists_active')){
		return LogicResult::error('This feature is turned off');
	}

	$session = SessionControl::get_instance();
	$session->set_return();
	$page_vars['session'] = $session;
	
	if($session->get_user_id()){ 
		$user = new User($session->get_user_id(), TRUE);
	}
	else{
		$user = new User(NULL);
	}


	$search_criteria = array('deleted' => false, 'active' => true, 'visibility' => MailingList::VISIBILITY_PUBLIC);
	$mailing_lists = new MultiMailingList(
		$search_criteria,
		array('name'=>'ASC'));	
	$mailing_lists->load();
	$numlists = $mailing_lists->count_all();
	$page_vars['mailing_lists'] = $mailing_lists;
	$page_vars['numlists'] = $numlists;

	if($_POST){
	
		if(!$session->get_user_id()){
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
		}

		//NOTHING TICKED IS NOT A SUBSCRIPTION - SAY SO BEFORE AN ACCOUNT GETS CREATED FOR IT
		$selected_lists = $_POST['new_list_subscribes'] ?? array();
		if(empty($selected_lists)){
			$page_vars['messages'] = array(array(
				'message_type' => 'warn',
				'message_title' => 'Nothing selected',
				'message' => 'Check at least one list to subscribe to.',
			));
		}
		else{
			//IF USER IS LOGGED IN, LOAD THEIR INFO...IF NOT SEE IF THERE IS EXISTING USER...IF NOT CREATE ONE
			if($session->get_user_id()){
				$user = new User($session->get_user_id(), TRUE);
				// A list reaches people by email; with none, there is nowhere to send it.
				if(!$user->get('usr_email')){
					return User::add_email_first('to subscribe to a mailing list: the list is sent there');
				}
			}
			else if(($user = User::GetByEmail($_POST['usr_email'] ?? '')) && $user->get('usr_delete_time')){
				return LogicResult::error(User::CLOSED_ACCOUNT_MESSAGE);
			}
			else if($user){
				// Typing an address proves nothing about owning it: a visitor never
				// subscribes an existing account, whose owner signs in to manage it.
				return LogicResult::error('An account already uses that email address. Please sign in to manage its subscriptions.');
			}
			else if(!$user){
				//A COMPACT SIGNUP FORM ASKS ONLY FOR AN EMAIL - NAME THEM FROM IT
				$guessed = User::guessNameFromEmail($_POST['usr_email'] ?? '');
				$data = array(
					'usr_first_name' => !empty($_POST['usr_first_name']) ? $_POST['usr_first_name'] : $guessed['first_name'],
					'usr_last_name' => !empty($_POST['usr_last_name']) ? $_POST['usr_last_name'] : $guessed['last_name'],
					'usr_email' => $_POST['usr_email'] ?? '',
					'usr_nickname' => $_POST['usr_nickname'] ?? '',
					'usr_timezone' => $_POST['usr_timezone'] ?? '',
					'password' => $_POST['usr_password'] ?? '',
					'send_emails' => false
				);
				// A visitor's signup: no one is signed in to own the new account. The
				// honeypot, anti-spam question and CAPTCHA above admitted this request.
				$user = SystemBase::rule_checked_write(function () use ($data) {
					$user = User::CreateNew($data);
					if (!empty($_POST['privacy'])) {
						$user->set('usr_terms_accepted_time', gmdate('Y-m-d H:i:s'));
						$user->save();
					}
					return $user;
				});
			}
			$page_vars['user'] = $user;

			$page_vars['messages'] = lists_apply_choices($user, $mailing_lists, (array)$selected_lists, (bool)$session->get_user_id());
		}
	}

	
	//GET THE USER'S LISTS
	$user_subscribed_list = array();
	if($user->key){
		$search_criteria = array('deleted' => false,'user_id' => $user->key);
		$user_lists = new MultiMailingListRegistrant(
			$search_criteria);	
		$user_lists->load();
		
		foreach ($user_lists as $user_list){
			$user_subscribed_list[] = $user_list->get('mlr_mlt_mailing_list_id');
		}
	}
	$page_vars['user_subscribed_list'] = $user_subscribed_list;
	
	return LogicResult::render($page_vars);
}

/**
 * Act on the lists this page shows (public, active) and no others: a ticked
 * list subscribes. An unticked one unsubscribes only a signed-in member, whose
 * page this is; a visitor who typed an address has proved nothing about owning
 * it, so a visitor's post only ever adds. A visitor's subscriptions are written
 * for an account no one is signed in to: the honeypot, anti-spam question and
 * CAPTCHA in lists_logic() admitted the request.
 */
function lists_apply_choices(User $user, $mailing_lists, array $selected, bool $signed_in): array {
	require_once(PathHelper::getIncludePath('data/visitor_events_class.php'));
	$selected = array_map('intval', $selected);
	$messages = array();
	foreach ($mailing_lists as $mailing_list) {
		$name = $mailing_list->get('mlt_name');
		$subscribed = $mailing_list->is_user_in_list($user->key);
		if (in_array((int)$mailing_list->key, $selected, true)) {
			if ($subscribed) {
				$messages[] = array('message_type' => 'warn', 'message_title' => 'Notice', 'message' => 'You are already SUBSCRIBED to the following lists: ' . $name);
				continue;
			}
			$status = SystemBase::rule_checked_write(function () use ($mailing_list, $user) { return $mailing_list->add_registrant($user->key); });
			if ($status) {
				$messages[] = array('message_type' => 'success', 'message_title' => 'Success', 'message' => 'You are SUBSCRIBED to the following lists: ' . $name);
				SessionControl::get_instance()->save_visitor_event(VisitorEvent::TYPE_LIST_SIGNUP, FALSE, 'mailing_list', $mailing_list->key);
			} else {
				$messages[] = array('message_type' => 'error', 'message_title' => 'Error', 'message' => 'There was an error adding you to the following lists: ' . $name);
			}
		} else if ($signed_in && $subscribed) {
			$status = $mailing_list->remove_registrant($user->key);
			$messages[] = $status
				? array('message_type' => 'success', 'message_title' => 'Success', 'message' => 'You are UNSUBSCRIBED from the following lists: ' . $name)
				: array('message_type' => 'error', 'message_title' => 'Error', 'message' => 'There was an error removing you from the following lists: ' . $name);
		}
	}
	return $messages;
}

?>