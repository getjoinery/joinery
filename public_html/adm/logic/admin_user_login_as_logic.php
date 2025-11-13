<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_user_login_as_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);
	$session->set_return();

	$usr_user_id = LibraryFunctions::fetch_variable_local($get_vars, 'usr_user_id', NULL);
	$user = new User($usr_user_id, TRUE);

	$_SESSION['usr_user_id'] = $usr_user_id;
	$_SESSION['permission'] = $user->get('usr_permission');

	return LogicResult::redirect('/');
}
?>
