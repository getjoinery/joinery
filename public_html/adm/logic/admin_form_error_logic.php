<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_form_error_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/log_form_errors_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(9);
	$session->set_return();

	$form_error = new FormError($get_vars['lfe_log_form_error_id'] ?? NULL, TRUE);
	$user = new User($form_error->get('lfe_usr_user_id'), TRUE);

	$page_vars = array();
	$page_vars['session'] = $session;
	$page_vars['form_error'] = $form_error;
	$page_vars['user'] = $user;

	return LogicResult::render($page_vars);
}
?>
