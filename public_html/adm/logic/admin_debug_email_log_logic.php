<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_debug_email_log_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/debug_email_logs_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$debug_email_log = new DebugEmailLog($get_vars['del_debug_email_log_id'] ?? $post_vars['del_debug_email_log_id'] ?? NULL, TRUE);

	$page_vars = array();
	$page_vars['session'] = $session;
	$page_vars['debug_email_log'] = $debug_email_log;

	return LogicResult::render($page_vars);
}
?>
