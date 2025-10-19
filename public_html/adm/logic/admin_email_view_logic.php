<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_email_view_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/emails_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$email_id = $get_vars['eml_email_id'] ?? $post_vars['eml_email_id'] ?? NULL;
	$email = new Email($email_id, TRUE);

	$page_vars = array();
	$page_vars['session'] = $session;
	$page_vars['email'] = $email;

	return LogicResult::render($page_vars);
}
?>
