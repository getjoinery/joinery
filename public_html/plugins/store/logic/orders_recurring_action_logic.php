<?php
/**
 * Cancel a subscription, as its buyer (or an admin acting for them).
 *
 * @version 1.1 - refuses anything but a POST: the Cancel link let any site cancel a signed-in
 *                buyer's subscription
 */

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('plugins/store/includes/StripeHelper.php'));
require_once(PathHelper::getIncludePath('data/users_addrs_class.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));

function orders_recurring_action_logic(array $input): LogicResult {
	$stripe_helper = new StripeHelper();

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('products_active')) {
		return LogicResult::error('This feature is turned off');
	}

	// A cancel is a POST. It was a link, and a link is a GET that any other
	// site can send a signed-in buyer's browser to, cookie and all; a
	// cross-site POST carries no session cookie (SameSite=Lax). The API
	// reaches this by POST too.
	if (!LibraryFunctions::isFormSubmission()) {
		return LogicResult::error('Cancel a subscription with the Cancel button on your subscriptions page.');
	}

	$session = SessionControl::get_instance();
	$session->check_permission(0);

	$order_item_id = $input['order_item_id'] ?? $input['order_item_id'] ?? null;
	if (!$order_item_id) {
		return LogicResult::error('order_item_id is required');
	}
	$order_item_id = intval($order_item_id);

	$order_item = new OrderItem($order_item_id, TRUE);
	$success = $order_item->cancel_subscription_order_item(true, 'period_end');

	// Redirect back
	$returnurl = $session->get_return();
	if (!$returnurl) {
		$returnurl = '/profile';
	}
	return LogicResult::redirect($returnurl);
}

function orders_recurring_action_logic_descriptor(): array {
	return [
		'description'      => 'Execute a recurring-order action (cancel, reactivate, etc.) for an order item.',
		'requires_session' => true,
		'mutates'          => true,
		'ai_agent'         => 'confirm',
		'input'            => [
			'order_item_id' => ['type' => 'int', 'required' => true, 'label' => 'Order item ID'],
		],
	];
}
?>
