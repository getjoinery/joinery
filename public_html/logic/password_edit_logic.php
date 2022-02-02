<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/EmailTemplate.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SingleRowAccessor.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user = new User($session->get_user_id(), TRUE);

	$has_old_password = $user->get('usr_password') !== NULL;

	
	if($_POST) { 

		if(!isset($_POST['usr_password']) || !isset($_POST['usr_password_again'])){
			throw new SystemDisplayableError('The following required fields were not set: passwords');
		}		
		

		if ($has_old_password) {
			// If the user doesn't have an existing password
			// then no need for them to type in their old password.
			if(!isset($_POST['usr_old_password'])){
				throw new SystemDisplayableError('The following required fields were not set: old password');
			}			
			
		}

		// Only check the old password if they had one!
		if ($has_old_password && !$user->check_password($_POST['usr_old_password'])) {
			throw new SystemDisplayableError('Sorry, the old password you typed in was not correct.');
		}
		else {
			$user->set('usr_password', User::GeneratePassword($_POST['usr_password']));
			$user->save();
			$message = '<p>Your password have been updated!</p>';
		}
	}
	
	$tab_menus = array(
		'Edit Account' => '/profile/account_edit',
		'Change Password' => '/profile/password_edit',
		'Edit Address' => '/profile/address_edit',
		'Edit Phone Number' => '/profile/phone_numbers_edit',
		'Change Contact Preferences' => '/profile/contact_preferences',
	);
	
	$_REQUEST['menu_item'] = 'Change Password';

?>