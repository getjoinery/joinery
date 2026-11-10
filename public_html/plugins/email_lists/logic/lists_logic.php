<?php

function lists_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

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
		else if($session->get_user_id()){
			$user = new User($session->get_user_id(), TRUE);
			// A list reaches people by email; with none, there is nowhere to send it.
			if(!$user->get('usr_email')){
				return User::add_email_first('to subscribe to a mailing list: the list is sent there');
			}
			$page_vars['user'] = $user;
			$page_vars['messages'] = lists_apply_choices($user, $mailing_lists, (array)$selected_lists);
		}
		else{
			// A visitor's typed address. Whether it has an account here is not
			// said (B8), and none is made for it until its owner confirms (B9):
			// every address gets the same answer, and the link goes to the inbox.
			$address = strtolower(trim((string)($_POST['usr_email'] ?? '')));
			if(!filter_var($address, FILTER_VALIDATE_EMAIL)){
				return LogicResult::error('Please enter a valid email address.');
			}
			$offered = array();
			foreach ($mailing_lists as $mailing_list) {
				$offered[] = (int)$mailing_list->key;
			}
			$asked = array_values(array_intersect(array_map('intval', (array)$selected_lists), $offered));
			EmailListSubscriptions::request(null, $address, $asked, EmailListSubscriptions::FROM_FORM);
			$page_vars['messages'] = array(array('message_type' => 'success', 'message_title' => 'Check your email',
				'message' => 'If ' . htmlspecialchars($address) . ' can join, a link to confirm is on its way there. Nothing is sent to it until it is confirmed.'));
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
 * A signed-in member's choices on this page (public, active lists only): a
 * ticked list is asked for (confirmed at once for a verified address, by
 * email otherwise), and they are unsubscribed from an unticked one they are on.
 */
function lists_apply_choices(User $user, $mailing_lists, array $selected): array {
	$offered = array();
	foreach ($mailing_lists as $mailing_list) {
		$offered[] = (int)$mailing_list->key;
	}
	return EmailListSubscriptions::set_lists($user, array_map('intval', $selected), $offered, EmailListSubscriptions::FROM_MEMBER);
}

?>