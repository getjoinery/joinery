<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_utilities_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	$session = SessionControl::get_instance();
	$session->set_return();

	$page_vars = array();
	$page_vars['settings'] = Globalvars::get_instance();
	$page_vars['session'] = $session;

	return LogicResult::render($page_vars);
}
?>
