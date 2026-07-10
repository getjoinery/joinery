<?php
/**
 * API action: subscription_summary — the owner's subscriptions as JSON.
 *
 * POST /api/v1/action/subscription_summary (session key). Returns active
 * and cancelled subscriptions, the current tier, and a payment_source
 * marker (stripe / paypal / none) so the client knows which management
 * affordances to show. Shares subscriptions_logic.php's query path.
 *
 * @version 1.0.0
 */


function subscription_summary_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('products_active') || !$settings->get_setting('subscriptions_active')) {
		return LogicResult::error('This feature is turned off');
	}

	$user_id = $session->get_user_id();

	$build_row = function(OrderItem $sub) {
		$product = new Product($sub->get('odi_pro_product_id'), TRUE);
		$product_version = $sub->get('odi_prv_product_version_id') ? new ProductVersion($sub->get('odi_prv_product_version_id'), TRUE) : null;

		$payment_source = 'none';
		if ($sub->get('odi_stripe_subscription_id')) {
			$payment_source = 'stripe';
		} elseif ($sub->get('odi_paypal_subscription_id')) {
			$payment_source = 'paypal';
		}

		return array(
			'order_item_id' => (int)$sub->key,
			'product_name'  => $product ? $product->get('pro_name') : '',
			'period'        => $product_version ? $product_version->is_subscription() : '',
			'price'         => $sub->get('odi_price'),
			'status'        => $sub->get('odi_subscription_cancelled_time') ? 'cancelled' : ($sub->get('odi_subscription_status') ?: 'active'),
			'renewal_or_end_date' => $sub->get('odi_subscription_cancelled_time') ?: $sub->get('odi_subscription_period_end'),
			'can_cancel'    => !$sub->get('odi_subscription_cancelled_time'),
			'payment_source' => $payment_source,
		);
	};

	$active_subscriptions = new MultiOrderItem(
		array('user_id' => $user_id, 'is_active_subscription' => true),
		array('order_item_id' => 'DESC')
	);
	$active_subscriptions->load();

	$cancelled_subscriptions = new MultiOrderItem(
		array('user_id' => $user_id, 'is_cancelled_subscription' => true),
		array('order_item_id' => 'DESC')
	);
	$cancelled_subscriptions->load();

	$active_out = array();
	$overall_payment_source = 'none';
	foreach ($active_subscriptions as $sub) {
		$row = $build_row($sub);
		$active_out[] = $row;
		if ($overall_payment_source === 'none') {
			$overall_payment_source = $row['payment_source'];
		}
	}

	$cancelled_out = array();
	foreach ($cancelled_subscriptions as $sub) {
		$cancelled_out[] = $build_row($sub);
	}

	$current_tier = SubscriptionTier::GetUserTier($user_id);

	return LogicResult::render(array(
		'active_subscriptions'    => $active_out,
		'cancelled_subscriptions' => $cancelled_out,
		'current_tier'            => $current_tier ? array(
			'tier_id'      => (int)$current_tier->key,
			'name'         => $current_tier->get('sbt_display_name') ?: $current_tier->get('sbt_name'),
		) : null,
		'payment_source' => $overall_payment_source,
	));
}

function subscription_summary_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'Active and cancelled subscriptions, current tier, and payment source for the signed-in owner',
	];
}

?>
