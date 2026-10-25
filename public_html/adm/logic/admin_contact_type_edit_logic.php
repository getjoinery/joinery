<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_contact_type_edit_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/contact_types_class.php'));

	$session = SessionControl::get_instance();

	if (isset($input['edit_primary_key_value'])) {
		$contact_type = new ContactType($input['edit_primary_key_value'], TRUE);
		$contact_type->assert_found();
	} elseif (isset($input['ctt_contact_type_id'])) {
		$contact_type = new ContactType($input['ctt_contact_type_id'], TRUE);
		$contact_type->assert_found();
	} else {
		$contact_type = new ContactType(NULL);
	}

	if(LibraryFunctions::isFormSubmission()){

		$editable_fields = array('ctt_description', 'ctt_provider_list_id', 'ctt_name');

		foreach($editable_fields as $field) {
			$contact_type->set($field, $input[$field]);
		}

		$contact_type->prepare();
		$contact_type->save();
		$contact_type->load();

		return LogicResult::redirect('/admin/admin_contact_type?ctt_contact_type_id='.$contact_type->key);
	}

	$page_vars = array(
		'contact_type' => $contact_type,
		'session' => $session,
	);

	return LogicResult::render($page_vars);
}

?>
