<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function phone_numbers_edit_logic($get_vars, $post_vars){
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/phone_number_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);

	$user_id = $session->get_user_id();

	// Load or create phone number
	if (isset($post_vars['edit_primary_key_value'])) {
		$phone_number = new PhoneNumber($post_vars['edit_primary_key_value'], TRUE);
		// Verify user owns this phone number
		$phone_number->authenticate_write(array(
			'current_user_id' => $user_id,
			'current_user_permission' => $session->get_permission()
		));
	} elseif (isset($get_vars['phn_phone_number_id'])) {
		$phone_number = new PhoneNumber($get_vars['phn_phone_number_id'], TRUE);
		// Verify user owns this phone number
		$phone_number->authenticate_write(array(
			'current_user_id' => $user_id,
			'current_user_permission' => $session->get_permission()
		));
	} else {
		// Load user's first phone number or create new
		$phone_numbers = new MultiPhoneNumber(array('user_id' => $user_id));
		if ($phone_numbers->count_all()) {
			$phone_numbers->load();
			$phone_number = $phone_numbers->get(0);
		} else {
			$phone_number = new PhoneNumber(NULL);
		}
	}

	if($post_vars){
		// Add-only logic - set user_id when creating new phone number
		if (!$phone_number->key) {
			$phone_number->set('phn_usr_user_id', $user_id);
		}

		// Set editable fields
		$editable_fields = array('phn_cco_country_code_id', 'phn_phone_number');
		foreach($editable_fields as $field) {
			if(isset($post_vars[$field])){
				$phone_number->set($field, $post_vars[$field]);
			}
		}

		$phone_number->prepare();
		$phone_number->save();

		// Success message
		$msgtxt = 'Phone number has been saved.';
		$message = new DisplayMessage($msgtxt, 'Success', '/\/profile\/phone_numbers_edit.*/',
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, "phonebox", TRUE);
		$session->save_message($message);

		return LogicResult::redirect('/profile/phone_numbers_edit');
	}

	$page_vars['phone_number'] = $phone_number;
	$page_vars['display_messages'] = $session->get_messages($_SERVER['REQUEST_URI']);
	$page_vars['tab_menus'] = array(
		'Edit Account' => '/profile/account_edit',
		'Change Password' => '/profile/password_edit',
		'Edit Address' => '/profile/address_edit',
		'Edit Phone Number' => '/profile/phone_numbers_edit',
		'Change Contact Preferences' => '/profile/contact_preferences',
		'Security' => '/profile/security',
	);

	return LogicResult::render($page_vars);
}

function phone_numbers_edit_logic_api() {
    return [
        'requires_session' => true,
        'description' => 'Update phone numbers',
    ];
}
?>
