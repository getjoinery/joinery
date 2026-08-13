<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('includes/Activation.php'));
require_once(PathHelper::getIncludePath('data/contact_types_class.php'));

function admin_contact_type_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->check_permission(8);

	$contact_type = new ContactType($input['ctt_contact_type_id'], TRUE);

	if($input['action'] == 'delete'){
		$contact_type->assert_can_write($session);
		$contact_type->soft_delete();

		return LogicResult::redirect("/admin/admin_contact_types");
	}
	else if($input['action'] == 'undelete'){
		$contact_type->assert_can_write($session);
		$contact_type->undelete();

		return LogicResult::redirect("/admin/admin_contact_types");
	}

	$session->set_return();

	$page_vars = array(
		'session' => $session,
		'contact_type' => $contact_type,
	);

	return LogicResult::render($page_vars);
}
