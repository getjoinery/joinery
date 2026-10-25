<?php

function list_logic(array $input): LogicResult {
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

	$mailing_list = null;
	if (!empty($input['slug'])) {
		$mailing_list = MailingList::get_by_link($input['slug']);
	}
	if(!$mailing_list || !$mailing_list->key || !$mailing_list->get('mlt_is_active') || $mailing_list->get('mlt_visibility') == MailingList::VISIBILITY_PRIVATE){
		require_once(LibraryFunctions::display_404_page());
	}
	$page_vars['mailing_list'] = $mailing_list;
	

	$session = SessionControl::get_instance();
	$session->set_return();
	$page_vars['session'] = $session;

	
	$page_vars['messages'] = [];

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
		
		
		// Typing an address proves nothing about owning it: only the signed-in
		// subscriber (the page offers the form to no one else) or the
		// unsubscribe link in a list email takes someone off a list.
		if (isset($_POST['mlt_mailing_list_id_unsubscribe']) && !isset($_POST['mlt_mailing_list_id_subscribe']) && !$session->get_user_id()) {
			return LogicResult::error('Sign in to unsubscribe, or use the unsubscribe link in any email from this list.');
		}

		//IF USER IS LOGGED IN, LOAD THEIR INFO...IF NOT SEE IF THERE IS EXISTING USER...IF NOT CREATE ONE
		if($session->get_user_id()){ 
			$user = new User($session->get_user_id(), TRUE);
			// A list reaches people by email; with none, there is nowhere to send it.
			if(!$user->get('usr_email') && isset($_POST['mlt_mailing_list_id_subscribe'])){
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
			// Guess name from email when name fields aren't provided (e.g. compact newsletter form)
			$guessed = User::guessNameFromEmail($_POST['usr_email']);
			$data = array(
				'usr_first_name' => !empty($_POST['usr_first_name']) ? $_POST['usr_first_name'] : $guessed['first_name'],
				'usr_last_name' => !empty($_POST['usr_last_name']) ? $_POST['usr_last_name'] : $guessed['last_name'],
				'usr_email' => $_POST['usr_email'],
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
		
		$messages = [];
		if (isset($_POST['mlt_mailing_list_id_subscribe'])) {
			if ($mailing_list->is_user_in_list($user->key)) {
				$messages[] = [
					'message_type' => 'warn',
					'message_title' => 'Notice',
					'message' => 'You are already subscribed to ' . htmlspecialchars($mailing_list->get('mlt_name')),
				];
			} else {
				// The session's own subscription, or a visitor's for the address they
				// gave, admitted by the honeypot, anti-spam question and CAPTCHA above.
				$status = SystemBase::rule_checked_write(function () use ($mailing_list, $user) { return $mailing_list->add_registrant($user->key); });
				$messages[] = $status
					? ['message_type' => 'success', 'message_title' => 'Success', 'message' => 'You are now subscribed to ' . htmlspecialchars($mailing_list->get('mlt_name'))]
					: ['message_type' => 'error', 'message_title' => 'Error', 'message' => 'There was an error subscribing you to ' . htmlspecialchars($mailing_list->get('mlt_name'))];
			}
		} elseif (isset($_POST['mlt_mailing_list_id_unsubscribe'])) {
			if (!$mailing_list->is_user_in_list($user->key)) {
				$messages[] = [
					'message_type' => 'warn',
					'message_title' => 'Notice',
					'message' => 'You are not subscribed to ' . htmlspecialchars($mailing_list->get('mlt_name')),
				];
			} else {
				$status = $mailing_list->remove_registrant($user->key);
				$messages[] = $status
					? ['message_type' => 'success', 'message_title' => 'Success', 'message' => 'You have been unsubscribed from ' . htmlspecialchars($mailing_list->get('mlt_name'))]
					: ['message_type' => 'error', 'message_title' => 'Error', 'message' => 'There was an error unsubscribing you from ' . htmlspecialchars($mailing_list->get('mlt_name'))];
			}
		}
		$page_vars['messages'] = $messages;

	}
	
	
	$page_vars['member_of_list'] = false;
	if($session->get_user_id()){
		$page_vars['member_of_list'] = $mailing_list->is_user_in_list($session->get_user_id());
	}
	
	return LogicResult::render($page_vars);
}
?>