<?php

function event_waiting_list_logic($get_vars, $post_vars, $event_id){
	
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/groups_class.php');
	
	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;
	
	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	
	
	if(!$settings->get_setting('events_active')){
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();
	}
	
	
	$event = new Event($event_id, TRUE);
	$page_vars['event'] = $event;
	
	if($post_vars){
		
		$user = NULL;
		if($session->get_user_id()){
			$user = new User($session->get_user_id(), TRUE);
		}
		else{
	
			if(!FormWriterPublicTW::honeypot_check($post_vars)){
				throw new SystemDisplayableError(
					'Please leave the "Extra email" field blank.');			
			}
			
			if(!FormWriterPublicTW::antispam_question_check($post_vars)){
				throw new SystemDisplayableError(
					'Please type the correct value into the anti-spam field.');			
			}		
		
	
			$captcha_success = FormWriterPublicTW::captcha_check($post_vars);
			if (!$captcha_success) {
				$errormsg = 'Sorry, '.strip_tags($post_vars['usr_first_name']).' '.strip_tags($post_vars['usr_last_name']).', you must click the CAPTCHA to submit the form.';
				throw new SystemDisplayableError($errormsg);	
			}	
			
			if(!$user = User::GetByEmail($post_vars['usr_email'])){
				$user = User::CreateNewUser($post_vars['usr_first_name'], $post_vars['usr_last_name'], $post_vars['usr_email'], NULL, TRUE);
				
			}	

			if($post_vars['usr_nickname']){
				$user->set('usr_nickname', $post_vars['usr_nickname']);
			}

			$user->set('usr_timezone', $post_vars['usr_timezone']);
			$user->prepare();
			$user->save();		

			if($post_vars['newsletter']){
				$status = $user->subscribe_to_contact_type(User::NEWSLETTER);
			}				
		}			

		//ADD TO WAITING LIST
		$group = $event->get_waiting_list_group();
			
		if($group->is_member_in_group($user->key)){
			$page_vars['display_message'] = 'You are already on the '.$event->get('evt_name').' waiting list.';
			$page_vars['message_type'] = 'success';	
		}
		else{
			$group->add_member($user->key);
			$page_vars['display_message'] = 'You have been added to the '.$event->get('evt_name').' waiting list.';
			$page_vars['message_type'] = 'success';	
		}
	
				
	}
	
	return $page_vars;
}
?>