<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_order_edit_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('/data/orders_class.php'));
	require_once(PathHelper::getIncludePath('/data/products_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	// Load or create order
	if (isset($get_vars['ord_order_id']) || isset($post_vars['ord_order_id'])) {
		$ord_order_id = isset($post_vars['ord_order_id']) ? $post_vars['ord_order_id'] : $get_vars['ord_order_id'];
		$order = new Order($ord_order_id, TRUE);
	} else {
		$order = new Order(NULL);
	}

	// Process POST actions
	if($post_vars){

		$order->set('ord_usr_user_id', $post_vars['ord_usr_user_id']);

		if(!$order->key || !$order->is_stripe_order()){
			$order->set('ord_total_cost', $post_vars['ord_total_cost']);
			$order->set('ord_status', Order::STATUS_PAID);

			if($post_vars['ord_timestamp_date'] && $post_vars['ord_timestamp_time']){
				$time_combined = $post_vars['ord_timestamp_date'] . ' ' . LibraryFunctions::toDBTime($post_vars['ord_timestamp_time']);
				$utc_time = LibraryFunctions::convert_time($time_combined, $session->get_timezone(),  'UTC', 'c');
				$order->set('ord_timestamp', $utc_time);
				//$event->set('evt_start_time_local', $time_combined);
			}
		}

		$order->prepare();
		$order->save();

		return LogicResult::redirect('/admin/admin_order?ord_order_id='.$order->key);
	}

	// Load data for display
	$breadcrumbs = array('Orders'=>'/admin/admin_orders');
	$breadcrumbs += array('Order Edit'=>'');

	// Load order user if exists
	$order_user = NULL;
	if($order->get('ord_usr_user_id')){
		$order_user = new User($order->get('ord_usr_user_id'), TRUE);
	}

	// Load users for dropdown
	$users = new MultiUser(array('deleted' => FALSE), array('last_name' => 'ASC'));
	$users->load();

	// Return page variables for rendering
	return LogicResult::render(array(
		'order' => $order,
		'breadcrumbs' => $breadcrumbs,
		'order_user' => $order_user,
		'users' => $users,
		'session' => $session,
	));
}

?>
