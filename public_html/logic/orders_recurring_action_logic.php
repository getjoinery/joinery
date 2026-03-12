<?php

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('includes/StripeHelper.php'));
require_once(PathHelper::getIncludePath('data/address_class.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/order_items_class.php'));

function orders_recurring_action_logic($get_vars, $post_vars) {
	$stripe_helper = new StripeHelper();

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('products_active')) {
		return LogicResult::error('This feature is turned off');
	}

	$session = SessionControl::get_instance();
	$session->check_permission(0);

	$order_item_id = $post_vars['order_item_id'] ?? $get_vars['order_item_id'] ?? null;
	if (!$order_item_id) {
		return LogicResult::error('order_item_id is required');
	}
	$order_item_id = intval($order_item_id);

	$order_item = new OrderItem($order_item_id, TRUE);
	$success = $order_item->cancel_subscription_order_item(true);

	// Redirect back
	$returnurl = $session->get_return();
	if (!$returnurl) {
		$returnurl = '/profile';
	}
	return LogicResult::redirect($returnurl);
}

function orders_recurring_action_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'Recurring order action',
	];
}
?>
