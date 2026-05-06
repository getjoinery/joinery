<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function account_edit_logic(array $input): LogicResult{
	require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/address_class.php'));
	require_once(PathHelper::getIncludePath('data/phone_number_class.php'));
	require_once(PathHelper::getIncludePath('data/files_class.php'));
	require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));
	
	$page_vars = array();
	
	$page_vars['settings'] = Globalvars::get_instance();

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$session->set_return();

	$user = new User($session->get_user_id(), TRUE);	

	if (!empty($input)) {

		// Photo management actions
		if (isset($input['action']) && $input['action'] == 'set_primary_photo') {
			$user = new User($session->get_user_id(), TRUE);
			$user->set_primary_photo((int)$input['photo_id']);

			$msgtxt = 'Your profile picture has been updated.';
			$message = new DisplayMessage($msgtxt, 'Photo updated', '/\/profile\/account_edit.*/',
				DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox', TRUE);
			$session->save_message($message);
			return LogicResult::redirect('/profile/account_edit');
		}

		if (isset($input['action']) && $input['action'] == 'clear_primary_photo') {
			$user = new User($session->get_user_id(), TRUE);
			$user->clear_primary_photo();

			$msgtxt = 'Your profile picture has been removed.';
			$message = new DisplayMessage($msgtxt, 'Photo removed', '/\/profile\/account_edit.*/',
				DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox', TRUE);
			$session->save_message($message);
			return LogicResult::redirect('/profile/account_edit');
		}

		//IF USER IS LOGGED IN, LOAD THEIR INFO...IF NOT SEE IF THERE IS EXISTING USER...IF NOT CREATE ONE
		if($session->get_user_id()){ 
			$user = new User($session->get_user_id(), TRUE);
			$user->set('usr_first_name', preg_replace("/[^a-zA-Z'-]/", "", $input['usr_first_name']));
			$user->set('usr_last_name', preg_replace("/[^a-zA-Z'-]/", "", $input['usr_last_name']));
			$user->set('usr_timezone', preg_replace("/[^a-zA-Z\/_-]/", "", $input['usr_timezone']));
			$user->save();
		}
		else if(!$user = User::GetByEmail($input['usr_email'])){
			$data = array(
				'usr_first_name' => $input['usr_first_name'],
				'usr_last_name' => $input['usr_last_name'],
				'usr_email' => $input['usr_email'],
				'usr_nickname' => $input['usr_nickname'],
				'usr_timezone' => $input['usr_timezone'],
				'password' => $input['usr_password'],
				'send_emails' => false
			);
			$user = User::CreateNew($data);	
		}

		$session->set_timezone($user->get('usr_timezone'));
		

		if(isset($input['usr_email_new']) && $input['usr_email_new'] != $user->get('usr_email')) {
			
			if (User::GetByEmail(trim($input['usr_email_new']))) {
				$msgtxt = 'An account has already been registered with the email address '. htmlspecialchars($input['usr_email_new']) .'.';
				$message = new DisplayMessage($msgtxt, 'Account already registered', '/\/profile\/account_edit.*/', DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox', TRUE);
				$session->save_message($message);			
			
			} 
			else {			
				Activation::email_change_send($user->key, trim($input['usr_email_new']));

				$msgtxt = 'To complete your email change, please click the activation link that we sent you at '. htmlspecialchars($input['usr_email_new']) .'.';
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
		'Security' => '/profile/security',
	);
	
	$page_vars['user'] = $user;
	$page_vars['user_photos'] = $user->get_photos();
	return LogicResult::render($page_vars);
}

function account_edit_logic_api() {
    return [
        'requires_session' => true,
        'description' => 'Update profile fields',
    ];
}

function account_edit_logic_descriptor(): array {
	return [
		'description'      => 'Update the current user\'s profile fields.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => [
			'usr_first_name' => ['type' => 'string', 'required' => true, 'label' => 'First name'],
			'usr_last_name' => ['type' => 'string', 'required' => true, 'label' => 'Last name'],
			'usr_timezone' => ['type' => 'string', 'required' => true, 'label' => 'Timezone'],
			'usr_email_new' => ['type' => 'email', 'required' => false, 'label' => 'New email address'],
		],
	];
}
?>
