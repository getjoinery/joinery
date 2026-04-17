<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_softdelete_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(9);
	$session->set_return('/admin/admin_users');

	$usr_user_id = LibraryFunctions::fetch_variable_local($get_vars, 'usr_user_id', NULL);
	$user = new User($usr_user_id, TRUE);

	$page_vars = array();
	$page_vars['session'] = $session;
	$page_vars['user'] = $user;
	$page_vars['usr_user_id'] = $usr_user_id;

	return LogicResult::render($page_vars);
}
?>
