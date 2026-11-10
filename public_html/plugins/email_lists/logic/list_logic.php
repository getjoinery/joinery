<?php

function list_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

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

		$messages = [];
		if (isset($_POST['mlt_mailing_list_id_subscribe'])) {
			if ($session->get_user_id()) {
				$user = new User($session->get_user_id(), TRUE);
				// A list reaches people by email; with none, there is nowhere to send it.
				if (!$user->get('usr_email')) {
					return User::add_email_first('to subscribe to a mailing list: the list is sent there');
				}
				$messages = EmailListSubscriptions::subscribe($user, (int)$mailing_list->key, EmailListSubscriptions::FROM_MEMBER);
			} else {
				// A visitor's typed address. Whether it has an account here is not
				// said (B8), and none is made for it until its owner confirms (B9):
				// every address gets the same answer, and the link goes to the inbox.
				$address = strtolower(trim((string)($_POST['usr_email'] ?? '')));
				if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
					return LogicResult::error('Please enter a valid email address.');
				}
				EmailListSubscriptions::request(null, $address, array((int)$mailing_list->key), EmailListSubscriptions::FROM_FORM);
				$messages[] = ['message_type' => 'success', 'message_title' => 'Check your email',
					'message' => 'If ' . htmlspecialchars($address) . ' can join ' . htmlspecialchars($mailing_list->get('mlt_name'))
						. ', a link to confirm is on its way there. Nothing is sent to it until it is confirmed.'];
			}
		} elseif (isset($_POST['mlt_mailing_list_id_unsubscribe'])) {
			$user = new User($session->get_user_id(), TRUE);
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