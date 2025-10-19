<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('includes/Activation.php'));
require_once(PathHelper::getIncludePath('data/contact_types_class.php'));

function admin_contact_type_logic($get_vars, $post_vars) {
	$session = SessionControl::get_instance();
	$session->check_permission(8);

	$contact_type = new ContactType($get_vars['ctt_contact_type_id'], TRUE);

	if($get_vars['action'] == 'delete'){
		$contact_type->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$contact_type->soft_delete();

		return LogicResult::redirect("/admin/admin_contact_types");
	}
	else if($get_vars['action'] == 'undelete'){
		$contact_type->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
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
