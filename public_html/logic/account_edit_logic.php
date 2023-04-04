<?php

function account_edit_logic($get_vars, $post_vars){
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/address_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/phone_number_class.php');
	
	$page_vars = array();
	
	$page_vars['settings'] = Globalvars::get_instance();

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$session->set_return();

	$user = new User($session->get_user_id(), TRUE);	

	if (!empty($post_vars)) {

		
		
		if(!isset($post_vars['usr_first_name']) || !isset($post_vars['usr_last_name']) || !isset($post_vars['usr_timezone'])){
				throw new SystemDisplayableError(
					'The following required fields were not set: first name, last name, timezone');
		}	
		
		
		$user->set('usr_first_name', trim($post_vars['usr_first_name']));
		$user->set('usr_last_name', trim($post_vars['usr_last_name']));
		$user->set('usr_nickname', trim($post_vars['usr_nickname']));

		
		// Check the timezone is valid
		try {
			new DateTimeZone($post_vars['usr_timezone']);
			$user->set('usr_timezone', $post_vars['usr_timezone']);
		} catch (Exception $e) {
			$errorhandler = new ErrorHandler();
			$errorhandler->handle_general_error('The timezone you entered in invalid.');
		}


		try {
			$user->prepare();
			$user->save();
		} catch (TTClassException $e) {
			$errorhandler = new ErrorHandler();
			$errorhandler->handle_general_error($e->getMessage());
		}

		$session->set_timezone($user->get('usr_timezone'));

		if(isset($post_vars['usr_email_new']) && $post_vars['usr_email_new'] != $user->get('usr_email')) {
			
			if (User::GetByEmail(trim($post_vars['usr_email_new']))) {
				$msgtxt = 'An account has already been registered with the email address '. htmlspecialchars($post_vars['usr_email_new']) .'.';
				$message = new DisplayMessage($msgtxt, 'Account already registered', '/\/profile\/account_edit.*/', DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox', TRUE);
				$session->save_message($message);			
			
			} 
			else {			
				Activation::email_change_send($user->key, trim($post_vars['usr_email_new']));

				$msgtxt = 'To complete your email change, please click the activation link that we sent you at '. htmlspecialchars($post_vars['usr_email_new']) .'.';
				$message = new DisplayMessage($msgtxt, 'Activate your email', '/\/profile\/account_edit.*/', DisplayMessage::MESSAGE_WARNING, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox', TRUE);
				$session->save_message($message);	
			}
		} 
		else {
			$msgtxt = 'Your account has been updated.';
			$message = new DisplayMessage($msgtxt, 'Account updated', '/\/profile\/account_edit.*/', DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox', TRUE);
			$session->save_message($message);		
		}
		
	} 

	$page_vars['display_messages'] = $session->get_messages($_SERVER['REQUEST_URI']);

	$page_vars['tab_menus'] = array(
		'Edit Account' => '/profile/account_edit',
		'Change Password' => '/profile/password_edit',
		'Edit Address' => '/profile/address_edit',
		'Edit Phone Number' => '/profile/phone_numbers_edit',
		'Change Contact Preferences' => '/profile/contact_preferences',
	);
	
	$page_vars['user'] = $user;
	return $page_vars;
}
?>