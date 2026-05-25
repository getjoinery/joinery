<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_user_add_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	require_once(PathHelper::getIncludePath('includes/Activation.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$settings = Globalvars::get_instance();
	$session = SessionControl::get_instance();
	$session->check_permission(8);

	if ($input){

		$user = User::CreateCompleteNew($input, $input['send_activation_email'], false, false);

		//NOW REDIRECT
		$session = SessionControl::get_instance();
		return LogicResult::redirect("/admin/admin_user?usr_user_id=$user->key");
	}

	return LogicResult::render(array());
}
?>
