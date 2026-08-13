<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function address_edit_logic(array $input): LogicResult{
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/address_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);

	$user_id = $session->get_user_id();

	// Load or create address
	if (isset($input['edit_primary_key_value'])) {
		$address = new Address($input['edit_primary_key_value'], TRUE);
		// Verify user owns this address
		$address->authenticate_write(array(
			'current_user_id' => $user_id,
			'current_user_permission' => $session->get_permission()
		));
	} elseif (isset($input['usa_address_id'])) {
		$address = new Address($input['usa_address_id'], TRUE);
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

	if (!empty($_POST)) {
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

		// Success message
		$msgtxt = 'Address has been saved.';
		$message = new DisplayMessage($msgtxt, 'Success', '/\/profile\/address_edit.*/',
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, "addressbox", TRUE);
		$session->save_message($message);

		return LogicResult::redirect('/profile/address_edit');
	}

	$page_vars['address'] = $address;

	return LogicResult::render($page_vars);
}

/**
 * Form definition for the native/API form face (GET /api/v1/form/address_edit).
 * Prefills from the acting user's first address, mirroring the web page's
 * "load first or create new" behavior; the submit round-trips through the
 * address_edit action (address_edit_logic on POST). Field set is identical to
 * the web form (Address::renderFormFields), so client and web stay in lockstep.
 */
function address_edit_logic_form($formwriter, $user = null, $input = []) {
	require_once(PathHelper::getIncludePath('data/address_class.php'));

	$user_id = $user ? $user->key : null;

	$address = new Address(NULL);
	if ($user_id) {
		$addresses = new MultiAddress(array('user_id' => $user_id));
		if ($addresses->count_all()) {
			$addresses->load();
			$address = $addresses->get(0);
		}
	}

	if ($address->key) {
		$formwriter->set_model($address);
		// Carries the address id so the submit updates it rather than creating
		// a second address (address_edit_logic keys off this on POST).
		$formwriter->hiddeninput('edit_primary_key_value', '', ['value' => $address->key]);
	}

	Address::renderFormFields($formwriter, [
		'required' => true,
		'include_country' => true,
		'include_user_id' => false,
		'model' => $address,
	]);

	$formwriter->submitbutton('btn_submit', 'Submit');
}

function address_edit_logic_descriptor(): array {
	return [
		'description'      => 'Create or update the current user\'s address.',
		'requires_session' => true,
		'mutates'          => true,
		'ai_agent'         => 'confirm',
		'input'            => [
			'edit_primary_key_value' => ['type' => 'int', 'required' => false, 'label' => 'Address ID (omit to create)'],
			'usa_cco_country_code_id' => ['type' => 'string', 'required' => false, 'label' => 'Country'],
			'usa_address1' => ['type' => 'string', 'required' => false, 'label' => 'Address line 1'],
			'usa_address2' => ['type' => 'string', 'required' => false, 'label' => 'Address line 2'],
			'usa_city' => ['type' => 'string', 'required' => false, 'label' => 'City'],
			'usa_state' => ['type' => 'string', 'required' => false, 'label' => 'State / province'],
			'usa_zip_code_id' => ['type' => 'string', 'required' => false, 'label' => 'Postal code'],
		],
	];
}
?>
