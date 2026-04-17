<?php
/**
 * Orders profile logic — full order history with pagination.
 *
 * @version 1.0
 */

function orders_profile_logic($get_vars, $post_vars) {
	$page_vars = array();
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/Pager.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/orders_class.php'));

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;
	$session->check_permission(0);

	$numperpage = 10;
	$page_offset = isset($get_vars['offset']) ? max(0, (int)$get_vars['offset']) : 0;

	$search_criteria = array();
	$search_criteria['user_id'] = $session->get_user_id();

	$orders = new MultiOrder(
		$search_criteria,
		array('ord_order_id' => 'DESC'),
		$numperpage,
		$page_offset
	);
	$numorders = $orders->count_all();
	$orders->load();

	$pager = new Pager(array('numrecords' => $numorders, 'numperpage' => $numperpage));

	$page_vars['orders'] = $orders;
	$page_vars['numorders'] = $numorders;
	$page_vars['pager'] = $pager;

	$page_vars['display_messages'] = $session->get_messages($_SERVER['REQUEST_URI']);

	return LogicResult::render($page_vars);
}
?>
