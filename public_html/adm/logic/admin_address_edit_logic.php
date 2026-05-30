<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_address_edit_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/address_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	// Load or create address first
	if (isset($input['edit_primary_key_value'])) {
		$address = new Address($input['edit_primary_key_value'], TRUE);
	} elseif (isset($input['usa_address_id'])) {
		$address = new Address($input['usa_address_id'], TRUE);
	} else {
		$address = new Address(NULL);
	}

	// Get user_id
	if($address->key){
		// Editing existing address - get user_id from the address
		$user_id = $address->get('usa_usr_user_id');
	} else {
		// Creating new address - get user_id from POST (hidden field) or GET (initial load)
		$user_id = $input['usr_user_id'] ?? NULL;
	}

	if(!$user_id){
		return LogicResult::error('User ID is required');
	}

	if(LibraryFunctions::isFormSubmission()){
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
			if(isset($input[$field])){
				$address->set($field, $input[$field]);
			}
		}

		$address->prepare();
		$address->save();
		$address->load();

		// If this is a new address and user has no default, make it default
		if(!isset($input['edit_primary_key_value']) || !$input['edit_primary_key_value']){
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
