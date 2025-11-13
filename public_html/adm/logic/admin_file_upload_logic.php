<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_file_upload_logic($get, $post) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('includes/UploadHandler.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	return LogicResult::render(array());
}
?>
