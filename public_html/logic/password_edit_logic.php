<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function password_edit_logic($get_vars, $post_vars){
	require_once(PathHelper::getIncludePath('includes/Activation.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/EmailTemplate.php'));

	require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
	require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
	require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user = new User($session->get_user_id(), TRUE);

	$has_old_password = $user->get('usr_password') !== NULL;

	if($post_vars) {

		if(!isset($post_vars['usr_password']) || !isset($post_vars['usr_password_again'])){
			return LogicResult::error('The following required fields were not set: passwords');
		}

		if ($has_old_password) {
			// If the user doesn't have an existing password
			// then no need for them to type in their old password.
			if(!isset($post_vars['usr_old_password'])){
				return LogicResult::error('The following required fields were not set: old password');
			}

		}

		// Only check the old password if they had one!
		if ($has_old_password && !$user->check_password($post_vars['usr_old_password'])) {
			return LogicResult::error('Sorry, the old password you typed in was not correct.');
		}
		else {
			$user->set('usr_password', User::GeneratePassword($post_vars['usr_password']));
			$user->save();
			$msgtext = '<p>Your password has been updated!</p>';
			$message = new DisplayMessage($msgtxt, 'Success', '/\/profile\/password_edit.*/', DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, "addressbox", TRUE);
			$session->save_message($message);
		}
	}

	$page_vars['tab_menus'] = array(
		'Edit Account' => '/profile/account_edit',
		'Change Password' => '/profile/password_edit',
		'Edit Address' => '/profile/address_edit',
		'Edit Phone Number' => '/profile/phone_numbers_edit',
		'Change Contact Preferences' => '/profile/contact_preferences',
	);

	$page_vars['display_messages'] = $session->get_messages($_SERVER['REQUEST_URI']);

	if ($has_old_password) {
		$page_vars['page_title'] = 'Change Password';
	}
	else {
		$page_vars['page_title'] = 'Set Password';
	}
	$page_vars['has_old_password'] = $has_old_password;

	return LogicResult::render($page_vars);
}

function password_edit_logic_api() {
    return [
        'requires_session' => true,
        'description' => 'Change password (logged in)',
    ];
}
?>