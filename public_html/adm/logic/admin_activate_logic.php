<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_activate_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/Activation.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(9);
	$session->set_return();

	$usr_user_id = LibraryFunctions::fetch_variable_local($get_vars, 'usr_user_id', NULL);
	$user = new User($usr_user_id, TRUE);

	$act_code = Activation::getTempCode($user->key, '30 day', 2, NULL, NULL);

	if (!$activated_user = Activation::ActivateUser($act_code)) {
		return LogicResult::error('Unable to activate user');
	}

	return LogicResult::redirect('/admin/admin_user?usr_user_id=' . $user->key);
}
?>
