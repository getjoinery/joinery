<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

$settings = Globalvars::get_instance();
$site_template = $settings->get_setting('site_template');
require_once($_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/includes/PublicPage.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/includes/FormWriterPublic.php');
	
require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');

$session = SessionControl::get_instance();
$session->check_permission(0);
$session->set_return("/profile");

$user = new User($session->get_user_id(), TRUE);

$errorhandler = new ErrorHandler();

if ($_POST) {

	
	if(!isset($_POST['usr_first_name']) || !isset($_POST['usr_last_name']) || !isset($_POST['usr_timezone'])){
			throw new SystemDisplayableError(
				'The following required fields were not set: passwords');
	}	
	

	//RESET NAME STATUS FIELD
	if(trim($_POST['usr_first_name']) != $user->get('usr_first_name') || trim($_POST['usr_last_name']) != $user->get('usr_last_name')) {
		$user->set('usr_name_is_bad', NULL);
		$user->set('usr_name_checked_by', NULL);
		$user->set('usr_name_checked_time', NULL);
	}	
	
	$user->set('usr_first_name', trim($_POST['usr_first_name']));
	$user->set('usr_last_name', trim($_POST['usr_last_name']));
	$user->set('usr_nickname', trim($_POST['usr_nickname']));

	
	// Check the timezone is valid
	try {
		new DateTimeZone($_POST['usr_timezone']);
		$user->set('usr_timezone', $_POST['usr_timezone']);
	} catch (Exception $e) {
		$errorhandler->handle_general_error('The timezone you entered in invalid.');
	}

	/*
	if (isset($_POST['usr_gender'])) {
		if ($_POST['usr_gender'] != '') {
			$user->set('usr_gender', trim($_POST['usr_gender']));
		} else {
			$user->set('usr_gender', NULL);
		}
	}
	*/

	try {
		$user->prepare();
		$user->save();
	} catch (TTClassException $e) {
		$errorhandler->handle_general_error($e->getMessage());
	}

	$session->set_timezone($user->get('usr_timezone'));

	if(isset($_POST['usr_email_new']) && $_POST['usr_email_new'] != $user->get('usr_email')) {
		
		if (User::GetByEmail(trim($_POST['usr_email_new']))) {
			$msgtxt = 'An account has already been registered with the email address '. htmlspecialchars($_POST['usr_email_new']) .'.';
			$message = new DisplayMessage($msgtxt, '/\/profile\/account_edit.*/', DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox', TRUE);
			$session->save_message($message);			
		
		} else {			
			Activation::email_change_send($user->key, trim($_POST['usr_email_new']));

			$msgtxt = 'To complete your email change, please click the activation link that we sent you at '. htmlspecialchars($_POST['usr_email_new']) .'.';
			$message = new DisplayMessage($msgtxt, '/\/profile\/account_edit.*/', DisplayMessage::MESSAGE_WARNING, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox', TRUE);
			$session->save_message($message);	
		}
	} else {
		$msgtxt = 'Your account has been updated.';
		$message = new DisplayMessage($msgtxt, '/\/profile\/account_edit.*/', DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox', TRUE);
		$session->save_message($message);		
	}
	
	//NOW REDIRECT
	LibraryFunctions::redirect('/profile/account_edit');
} 

?>
