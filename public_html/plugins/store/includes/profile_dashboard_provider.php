<?php
/**
 * Store profile-dashboard providers.
 *
 * Contributes the "recent orders" and "subscriptions" sections to the member
 * profile page and the native-app dashboard summary. Registered from the
 * store's serve.php; with the store inactive neither section is contributed
 * (the settings that gate them are store-seeded too).
 *
 * The item `data` payloads reproduce the exact keys the native dashboard has
 * always emitted — recent_orders[{order_id,total,date}],
 * subscriptions[{order_item_id,product_name,price,status}],
 * active_subscription_count — so the app contract is unchanged.
 */

/** Recent orders (gate: products_active). */
function store_dashboard_recent_orders($user) {
	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('products_active')) {
		return null;
	}
	require_once(PathHelper::getIncludePath('includes/ProfileDashboardRegistry.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));
	require_once(PathHelper::getIncludePath('includes/CurrencyHelper.php'));

	$symbol = CurrencyHelper::symbol($settings->get_setting('site_currency'));
	$orders = new MultiOrder(array('user_id' => $user->key), array('ord_order_id' => 'DESC'), 3);
	$orders->load();

	$items = array();
	foreach ($orders as $order) {
		$data = array(
			'order_id' => (int)$order->key,
			'total'    => $order->get('ord_total_cost'),
			'date'     => $order->get('ord_timestamp'),
		);
		$items[] = new ProfileDashboardItem(
			$data,
			'Order #' . (int)$order->key,
			LibraryFunctions::convert_time($order->get('ord_timestamp'), 'UTC',
				SessionControl::get_instance()->get_timezone(), 'M j, Y'),
			$symbol . number_format((float)$order->get('ord_total_cost'), 2, '.', ','),
			null,
			'/profile/orders'
		);
	}

	return new ProfileDashboardSection('recent_orders', 'Recent Orders', '/profile/orders', null, $items);
}

/** Active + recent subscriptions (gate: products_active && subscriptions_active). */
function store_dashboard_subscriptions($user) {
	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('products_active') || !$settings->get_setting('subscriptions_active')) {
		return null;
	}
	require_once(PathHelper::getIncludePath('includes/ProfileDashboardRegistry.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
	require_once(PathHelper::getIncludePath('includes/CurrencyHelper.php'));

	$symbol = CurrencyHelper::symbol($settings->get_setting('site_currency'));

	$subscriptions = new MultiOrderItem(
		array('user_id' => $user->key, 'is_subscription' => true),
		array('order_item_id' => 'DESC'), 5
	);
	$subscriptions->load();

	$items = array();
	foreach ($subscriptions as $sub) {
		$product = new Product($sub->get('odi_pro_product_id'), TRUE);
		$status = $sub->get('odi_subscription_cancelled_time')
			? 'cancelled'
			: ($sub->get('odi_subscription_status') ?: 'active');
		$data = array(
			'order_item_id' => (int)$sub->key,
			'product_name'  => $product ? $product->get('pro_name') : '',
			'price'         => $sub->get('odi_price'),
			'status'        => $status,
		);
		$items[] = new ProfileDashboardItem(
			$data,
			$product ? $product->get('pro_name') : 'Subscription',
			ucfirst($status),
			$symbol . number_format((float)$sub->get('odi_price'), 2, '.', ','),
			$status === 'cancelled' ? 'Cancelled' : null,
			'/profile/subscriptions'
		);
	}

	$active_subs = new MultiOrderItem(array('user_id' => $user->key, 'is_active_subscription' => true));
	$stat = new ProfileDashboardStat('active_subscription_count', 'Active subscriptions',
		$active_subs->count_all(), '/profile/subscriptions');

	return new ProfileDashboardSection('subscriptions', 'Subscriptions', '/profile/subscriptions', $stat, $items);
}
