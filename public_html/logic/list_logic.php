<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/mailing_lists_class.php');
	require_once(LibraryFunctions::get_theme_file_path('FormWriterPublicTW.php', '/includes'));

	$settings = Globalvars::get_instance();

	/*
	if(!$settings->get_setting('mailing_lists_active')){
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();
	}
	*/
	
	$mailing_list = MailingList::get_by_link($static_routes_path);
	if(!$mailing_list || !$mailing_list->get('mlt_is_active') || $mailing_list->get('mlt_visibility') == MailingList::VISIBILITY_PRIVATE){
		require_once(LibraryFunctions::display_404_page());				
	}
	

	$session = SessionControl::get_instance();
	$session->set_return();


	
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
			$user = User::CreateNewUser($_POST['usr_first_name'], $_POST['usr_last_name'], $_POST['usr_email'], NULL, FALSE);	//DO NOT SEND WELCOME EMAIL	
		}

		if($_POST['usr_nickname']){
			$user->set('usr_nickname', $_POST['usr_nickname']);
		}
		$user->set('usr_timezone', $_POST['usr_timezone']);
		$user->prepare();
		$user->save();
		
		$messages = array();
		if($_POST['mlt_mailing_list_id_subscribe']){
			$mailing_list = new MailingList($_POST['mlt_mailing_list_id_subscribe'], TRUE);
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
		else if($_POST['mlt_mailing_list_id_unsubscribe']){
			$mailing_list = new MailingList($_POST['mlt_mailing_list_id_unsubscribe'], TRUE);
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
	
	
	$logged_in = $session->get_user_id();
	$member_of_list = false;
	if($logged_in){
		$member_of_list = $mailing_list->is_user_in_list($session->get_user_id());
	}	
?>