<?php

function lists_logic($get_vars, $post_vars, $params){
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/mailing_lists_class.php');

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;

	if(!$settings->get_setting('mailing_lists_active')){
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();
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
			$formwriter = LibraryFunctions::get_formwriter_object();
			if(!$formwriter->honeypot_check($_POST)){
				throw new SystemDisplayableError(
					'Please leave the "Extra email" field blank.');			
			}
			
			if(!$formwriter->antispam_question_check($_POST)){
				throw new SystemDisplayableError(
					'Please type the correct value into the anti-spam field.');			
			}		
			
			$captcha_success = $formwriter->captcha_check($_POST);
			if (!$captcha_success) {
				$errormsg = 'Sorry, '.strip_tags($_POST['usr_first_name']).' '.strip_tags($_POST['usr_last_name']).', you must click the CAPTCHA to submit the form.';
				throw new SystemDisplayableError($errormsg);	
			}	
		}
		
		//IF USER IS LOGGED IN, LOAD THEIR INFO...IF NOT SEE IF THERE IS EXISTING USER...IF NOT CREATE ONE
		if($session->get_user_id()){ 
			$user = new User($session->get_user_id(), TRUE);
		}
		else if(!$user = User::GetByEmail($_POST['usr_email'])){
			$data = array(
				'usr_first_name' => $_POST['usr_first_name'],
				'usr_last_name' => $_POST['usr_last_name'],
				'usr_email' => $_POST['usr_email'],
				'usr_nickname' => $_POST['usr_nickname'],
				'usr_timezone' => $_POST['usr_timezone'],
				'password' => $_POST['usr_password'],
				'send_emails' => false
			);
			$user = User::CreateNew($data);	
		}
		$page_vars['user'] = $user;

		$page_vars['messages'] = $user->add_user_to_mailing_lists($_POST['new_list_subscribes']);

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
	
	return $page_vars;
}

?>