<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_product_groups_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/product_groups_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(7);
	$session->set_return();

	$product_groups = new MultiProductGroup();
	$product_groups->load();

	$page_vars = array();
	$page_vars['session'] = $session;
	$page_vars['product_groups'] = $product_groups;

	return LogicResult::render($page_vars);
}
?>
