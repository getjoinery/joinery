<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_help_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$page_vars = array();
	$page_vars['settings'] = Globalvars::get_instance();
	$page_vars['session'] = $session;

	return LogicResult::render($page_vars);
}
?>
