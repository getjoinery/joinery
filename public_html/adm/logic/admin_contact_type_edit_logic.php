<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_contact_type_edit_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/contact_types_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	if (isset($post_vars['edit_primary_key_value'])) {
		$contact_type = new ContactType($post_vars['edit_primary_key_value'], TRUE);
	} elseif (isset($get_vars['ctt_contact_type_id'])) {
		$contact_type = new ContactType($get_vars['ctt_contact_type_id'], TRUE);
	} else {
		$contact_type = new ContactType(NULL);
	}

	if($post_vars){

		$editable_fields = array('ctt_description', 'ctt_mailchimp_list_id', 'ctt_name');

		foreach($editable_fields as $field) {
			$contact_type->set($field, $post_vars[$field]);
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
