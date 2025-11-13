<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function address_edit_logic($get_vars, $post_vars){
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/address_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);

	$user_id = $session->get_user_id();

	// Load or create address
	if (isset($post_vars['edit_primary_key_value'])) {
		$address = new Address($post_vars['edit_primary_key_value'], TRUE);
		// Verify user owns this address
		$address->authenticate_write(array(
			'current_user_id' => $user_id,
			'current_user_permission' => $session->get_permission()
		));
	} elseif (isset($get_vars['usa_address_id'])) {
		$address = new Address($get_vars['usa_address_id'], TRUE);
		// Verify user owns this address
		$address->authenticate_write(array(
			'current_user_id' => $user_id,
			'current_user_permission' => $session->get_permission()
		));
	} else {
		// Load user's first address or create new
		$addresses = new MultiAddress(array('user_id' => $user_id));
		if ($addresses->count_all()) {
			$addresses->load();
			$address = $addresses->get(0);
		} else {
			$address = new Address(NULL);
		}
	}

	if($post_vars){
		// Add-only logic - set user_id and defaults when creating new address
		if (!$address->key) {
			$address->set('usa_usr_user_id', $user_id);
			$address->set('usa_is_default', FALSE);
			$address->set('usa_is_bad', FALSE);
		}

		// Set editable fields
		$editable_fields = array(
			'usa_cco_country_code_id',
			'usa_address1',
			'usa_address2',
			'usa_city',
			'usa_state',
			'usa_zip_code_id'
		);

		foreach($editable_fields as $field) {
			if(isset($post_vars[$field])){
				$address->set($field, $post_vars[$field]);
			}
		}

		$address->prepare();
		$address->save();

		// Success message
		$msgtxt = 'Address has been saved.';
		$message = new DisplayMessage($msgtxt, 'Success', '/\/profile\/address_edit.*/',
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, "addressbox", TRUE);
		$session->save_message($message);

		return LogicResult::redirect('/profile/address_edit');
	}

	$page_vars['address'] = $address;
	$page_vars['display_messages'] = $session->get_messages($_SERVER['REQUEST_URI']);
	$page_vars['tab_menus'] = array(
		'Edit Account' => '/profile/account_edit',
		'Change Password' => '/profile/password_edit',
		'Edit Address' => '/profile/address_edit',
		'Edit Phone Number' => '/profile/phone_numbers_edit',
		'Change Contact Preferences' => '/profile/contact_preferences',
	);

	return LogicResult::render($page_vars);
}
?>
