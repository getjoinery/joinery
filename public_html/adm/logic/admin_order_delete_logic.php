<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

/**
 * Logic for admin_order_delete
 * Handles administrative deletion of orders (no refund processing)
 *
 * @param array $get_vars GET variables
 * @param array $post_vars POST variables
 * @return LogicResult
 */
function admin_order_delete_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/orders_class.php'));
	require_once(PathHelper::getIncludePath('data/order_items_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$page_vars = array();

	// Handle POST - Process deletion
	if (!empty($post_vars)) {
		$ord_order_id = LibraryFunctions::fetch_variable('ord_order_id', NULL, 1, 'You must provide a order to delete here.', $post_vars);
		$confirm = LibraryFunctions::fetch_variable('confirm', NULL, 1, 'You must confirm the action.', $post_vars);

		if ($confirm) {
			$order = new Order($ord_order_id, TRUE);
			$order->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
			$order->permanent_delete();
		}

		// Redirect after deletion
		$returnurl = $session->get_return();
		return LogicResult::redirect($returnurl);
	}

	// Handle GET - Display confirmation page
	$ord_order_id = LibraryFunctions::fetch_variable('ord_order_id', NULL, 1, 'You must provide a order to edit.', $get_vars);

	$order = new Order($ord_order_id, TRUE);

	$session->set_return("/admin/admin_orders");

	// Pass data to view
	$page_vars['order'] = $order;
	$page_vars['ord_order_id'] = $ord_order_id;
	$page_vars['session'] = $session;

	return LogicResult::render($page_vars);
}
