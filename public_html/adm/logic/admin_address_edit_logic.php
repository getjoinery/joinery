<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_address_edit_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/address_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	// Load or create address first
	if (isset($post_vars['edit_primary_key_value'])) {
		$address = new Address($post_vars['edit_primary_key_value'], TRUE);
	} elseif (isset($get_vars['usa_address_id'])) {
		$address = new Address($get_vars['usa_address_id'], TRUE);
	} else {
		$address = new Address(NULL);
	}

	// Get user_id
	if($address->key){
		// Editing existing address - get user_id from the address
		$user_id = $address->get('usa_usr_user_id');
	} else {
		// Creating new address - get user_id from POST (hidden field) or GET (initial load)
		if($post_vars){
			$user_id = $post_vars['usr_user_id'] ?? NULL;
		} else {
			$user_id = $get_vars['usr_user_id'] ?? NULL;
		}
	}

	if(!$user_id){
		return LogicResult::error('User ID is required');
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
		$address->load();

		// If this is a new address and user has no default, make it default
		if(!isset($post_vars['edit_primary_key_value']) || !$post_vars['edit_primary_key_value']){
			$address->set('usa_is_default', TRUE);
			$address->save();
		}

		return LogicResult::redirect('/admin/admin_user?usr_user_id='. $user_id);
	}

	$page_vars = array(
		'address' => $address,
		'user_id' => $user_id,
		'session' => $session,
	);

	return LogicResult::render($page_vars);
}

?>
