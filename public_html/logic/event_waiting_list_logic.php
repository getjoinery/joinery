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
	
	$composer_dir = $settings->get_setting('composerAutoLoad');	
	require $composer_dir.'autoload.php';
	use MailchimpAPI\Mailchimp;
	
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
		}			

		//ADD TO WAITING LIST
		$waiting_list_name = $event->get('evt_name'). ' ' . LibraryFunctions::convert_time($event->get('evt_start_time'), 'UTC', $event->get('evt_timezone'),'M j, Y') . ' waiting list';
		$waiting_list_name = substr($waiting_list_name,0,75);
		if(!$group = Group::get_by_name($waiting_list_name)){	
			$group = Group::add_group($waiting_list_name, NULL, 'user');
		}
		$group->add_member($user->key);
		$page_vars['display_message'] = 'You have been added to the '.$event->get('evt_name').' waiting list.';
		$page_vars['message_type'] = 'success';	

		if($post_vars['newsletter']){
			$status = $user->subscribe_to_contact_type(User::NEWSLETTER);
		}				
				
	}
	
	return $page_vars;
}
?>