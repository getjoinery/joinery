<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once(LibraryFunctions::get_theme_file_path('FormWriterPublicTW.php', '/includes'));

	$settings = Globalvars::get_instance();
	if(!$settings->get_setting('newsletter_active')){
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();
	}

	$session = SessionControl::get_instance();
	$session->set_return();

	
	if($_POST){
		
		
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
			
		if($user->get('usr_contact_preferences')){
			$message_type = 'warn';
			$message_title = 'Already subscribed';
			$message = '<p>You are already subscribed to our newsletter.  If you would like to unsubscribe, visit <a href="/profile">the My Profile page</a></p>';
		}
		else{
			$status = $user->add_to_mailing_list();		

			if(!$status){
				$message_type = 'error';
				$message_title = 'Error';
				$message = '<p>We were unable to add you to our mailing list.  Please try again later.</p>';
			}			
			else if($status->title == 'Member Exists'){
				$message_type = 'warn';
				$message_title = 'Already subscribed';
				$message =  '<p>You are already signed up for our mailing list.</p>';
			}
			else{
				$message_type = 'success';
				$message_title = 'Success';
				$message =  '<p>You are now signed up for our mailing list.</p>';
			}
		}
				
	}
	
?>