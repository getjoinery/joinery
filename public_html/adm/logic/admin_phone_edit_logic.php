<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_phone_edit_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/phone_number_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	// Load or create phone number first
	if (isset($post_vars['edit_primary_key_value'])) {
		$phone_number = new PhoneNumber($post_vars['edit_primary_key_value'], TRUE);
	} elseif (isset($get_vars['phn_phone_number_id'])) {
		$phone_number = new PhoneNumber($get_vars['phn_phone_number_id'], TRUE);
	} else {
		$phone_number = new PhoneNumber(NULL);
	}

	// Get user_id
	if($phone_number->key){
		// Editing existing phone - get user_id from the phone number
		$user_id = $phone_number->get('phn_usr_user_id');
	} else {
		// Creating new phone - get user_id from POST (hidden field) or GET (initial load)
		if($post_vars){
			$user_id = $post_vars['usr_user_id'] ?? NULL;
		} else {
			$user_id = $get_vars['usr_user_id'] ?? NULL;
		}
	}

	if(!$user_id){
		throw new SystemDisplayableError('User ID is required');
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
		$phone_number->load();

		return LogicResult::redirect('/admin/admin_user?usr_user_id='. $user_id);
	}

	$page_vars = array(
		'phone_number' => $phone_number,
		'user_id' => $user_id,
		'session' => $session,
	);

	return LogicResult::render($page_vars);
}

?>
