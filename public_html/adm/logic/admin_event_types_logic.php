<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_event_types_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/event_types_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(7);
	$session->set_return();

	$event_types = new MultiEventType();
	$event_types->load();

	$page_vars = array();
	$page_vars['session'] = $session;
	$page_vars['event_types'] = $event_types;

	return LogicResult::render($page_vars);
}
?>
