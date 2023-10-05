<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/mailing_lists_class.php');
	require_once(LibraryFunctions::get_theme_file_path('FormWriterPublicTW.php', '/includes'));

	$settings = Globalvars::get_instance();

	if(!$settings->get_setting('mailing_lists_active')){
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();
	}

	$session = SessionControl::get_instance();
	$session->set_return();
	
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


	if($_POST){
	
		if(!$session->get_user_id()){
			if(!FormWriterPublicTW::honeypot_check($_POST)){
				throw new SystemDisplayableError(
					'Please leave the "Extra email" field blank.');			
			}
			
			if(!FormWriterPublicTW::antispam_question_check($_POST)){
				throw new SystemDisplayableError(
					'Please type the correct value into the anti-spam field.');			
			}		
			
			$captcha_success = FormWriterPublicTW::captcha_check($_POST);
			if (!$captcha_success) {
				$errormsg = 'Sorry, '.strip_tags($_POST['usr_first_name']).' '.strip_tags($_POST['usr_last_name']).', you must click the CAPTCHA to submit the form.';
				throw new SystemDisplayableError($errormsg);	
			}	
		}
		
		//IF USER IS LOGGED IN, LOAD THEIR INFO...IF NOT SEE IF THERE IS EXISTING USER...IF NOT CREATE ONE
		$user = NULL;
		if($session->get_user_id()){ 
			$user = new User($session->get_user_id(), TRUE);
		}
		else if(!$user = User::GetByEmail($_POST['usr_email'])){
			$data = array(
				'usr_first_name' => $_POST['usr_first_name'],
				'usr_last_name' => $_POST['usr_last_name'],
				'usr_email' => $_POST['usr_email'],
				'password' => NULL,
				'send_emails' => false
			);
			$user = User::CreateNew($data);	

			if($_POST['usr_nickname']){
				$user->set('usr_nickname', $_POST['usr_nickname']);
			}
			$user->set('usr_timezone', $_POST['usr_timezone']);
			$user->prepare();
			$user->save();
		}

		$messages = array();
		$thismessage = array();
		foreach ($mailing_lists as $mailing_list){
			if(empty($_POST['new_list_subscribes'])){
				$new_list_subscribes = array();
			}
			else{
				$new_list_subscribes = $_POST['new_list_subscribes'];
			}
			
			//IF IT IS A CHOICE AND SELECTED
			if(in_array($mailing_list->key, $_POST['new_list_subscribes'])){

				if($mailing_list->is_user_in_list($user->key)){
					//IF USER IS ALREADY SUBSCRIBED
					$thismessage['message_type'] = 'warn';
					$thismessage['message_title'] = 'Notice';
					$thismessage['message'] = 'You are already SUBSCRIBED to the following lists: ' . $mailing_list->get('mlt_name');
					$messages[] = $thismessage;
				}
				else{
					//IF USER IS NOT SUBSCRIBED
					$status = $mailing_list->add_registrant($user->key);
					if($status){
						$thismessage['message_type'] = 'success';
						$thismessage['message_title'] = 'Success';
						$thismessage['message'] = 'You are SUBSCRIBED to the following lists: ' . $mailing_list->get('mlt_name');
						$messages[] = $thismessage;
					}
					else{
						$thismessage['message_type'] = 'error';
						$thismessage['message_title'] = 'Error';
						$thismessage['message'] = 'There was an error adding you to the following lists: ' . $mailing_list->get('mlt_name');
						$messages[] = $thismessage;
					}
				}
			}
			else{

				//IF IT IS A CHOICE AND NOT SELECTED
				if($mailing_list->is_user_in_list($user->key)){
					//IF USER IS SUBSCRIBED
					$status = $mailing_list->remove_registrant($user->key);
					if($status){
						$thismessage['message_type'] = 'success';
						$thismessage['message_title'] = 'Success';
						$thismessage['message'] = 'You are UNSUBSCRIBED to the following lists: ' . $mailing_list->get('mlt_name');
						$messages[] = $thismessage;
					}
					else{
						$thismessage['message_type'] = 'error';
						$thismessage['message_title'] = 'Error';
						$thismessage['message'] = 'There was an error removing you from the following lists: ' . $mailing_list->get('mlt_name');
						$messages[] = $thismessage;
					}
				}	
			}				
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


?>