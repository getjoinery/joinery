<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/groups_class.php');
	require_once(LibraryFunctions::get_theme_file_path('FormWriterPublicTW.php', '/includes'));
	
	$settings = Globalvars::get_instance();
	$composer_dir = $settings->get_setting('composerAutoLoad');	
	require $composer_dir.'autoload.php';
	use MailchimpAPI\Mailchimp;
	
	$settings = Globalvars::get_instance();
	if(!$settings->get_setting('events_active')){
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();
	}
	
	$event_id = LibraryFunctions::fetch_variable('event_id', 0, 1, 'You must pass an event.', TRUE, 'int');
	$event = new Event($event_id, TRUE);

	$session = SessionControl::get_instance();
	//$session->set_return();

	
	if($_POST){
		
		$user = NULL;
		if($session->get_user_id()){
			$user = new User($session->get_user_id(), TRUE);
		}
		else{
	
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
			
			if(!$user = User::GetByEmail($_POST['usr_email'])){
				$user = User::CreateNewUser($_POST['usr_first_name'], $_POST['usr_last_name'], $_POST['usr_email'], NULL, TRUE);
				
			}	

			if($_POST['usr_nickname']){
				$user->set('usr_nickname', $_POST['usr_nickname']);
			}

			$user->set('usr_timezone', $_POST['usr_timezone']);
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
		$display_message = 'You have been added to the '.$event->get('evt_name').' waiting list.';
		$message_type = 'success';	

		if($_POST['newsletter']){
			$status = $user->add_to_mailing_list();	
		}				
				
	}
	
?>