<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_order_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/StripeHelper.php'));
	require_once(PathHelper::getIncludePath('data/address_class.php'));
	require_once(PathHelper::getIncludePath('data/product_groups_class.php'));
	require_once(PathHelper::getIncludePath('data/order_items_class.php'));
	require_once(PathHelper::getIncludePath('data/orders_class.php'));
	require_once(PathHelper::getIncludePath('data/products_class.php'));

	$settings = Globalvars::get_instance();

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')];

	$order_id = LibraryFunctions::fetch_variable('ord_order_id', 0, 0, TRUE);
	$order = new Order($order_id, TRUE);

	$stripe_helper = new StripeHelper();
	$charge = $stripe_helper->update_order_refund_amount_from_stripe($order);

	if($order->get('ord_usr_user_id')){
		$order_user = new User($order->get('ord_usr_user_id'), TRUE);
	}
	else{
		$order_user = new User(NULL);
	}

	// Build dropdown actions
	$options['altlinks'] = array();
	if ($_SESSION['permission'] >= 8) {
		$options['altlinks']['Edit Order'] = '/admin/admin_order_edit?ord_order_id=' . $order->key;
		$options['altlinks']['Add Order Item'] = '/admin/admin_order_item_edit?ord_order_id='.$order->key;
	}
	if ($_SESSION['permission'] == 10) {
		$options['altlinks']['Permanent Delete'] = '/admin/admin_order_delete?ord_order_id=' . $order->key;
	}

	// Build dropdown button from altlinks
	$dropdown_button = '';
	if (!empty($options['altlinks'])) {
		$dropdown_button = '<div class="dropdown">';
		$dropdown_button .= '<button class="btn btn-soft-default btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Actions</button>';
		$dropdown_button .= '<div class="dropdown-menu dropdown-menu-end py-0">';
		foreach ($options['altlinks'] as $label => $url) {
			$is_danger = strpos($label, 'Delete') !== false;
			$dropdown_button .= '<a href="' . htmlspecialchars($url) . '" class="dropdown-item' . ($is_danger ? ' text-danger' : '') . '">' . htmlspecialchars($label) . '</a>';
		}
		$dropdown_button .= '</div>';
		$dropdown_button .= '</div>';
	}

	// Get billing address if exists
	$billing_address = '';
	if($order->get('ord_usa_address_id')){
		$address = new Address($order->get('ord_usa_address_id'), TRUE);
		$billing_address = $address->get_address_string('<br>');
	}

	$PRODUCT_ID_TO_NAME_CACHE = array();
	$order_items = $order->get_order_items();

	// Process order items with Stripe subscription updates
	foreach($order_items as $order_item){
		if($order_item->get('odi_is_subscription')){
			$result = $stripe_helper->update_subscription_in_order_item($order_item);
		}
	}

	$page_vars = array(
		'session' => $session,
		'settings' => $settings,
		'order' => $order,
		'order_user' => $order_user,
		'dropdown_button' => $dropdown_button,
		'billing_address' => $billing_address,
		'currency_symbol' => $currency_symbol,
		'order_items' => $order_items,
		'PRODUCT_ID_TO_NAME_CACHE' => $PRODUCT_ID_TO_NAME_CACHE,
		'stripe_helper' => $stripe_helper,
	);

	return LogicResult::render($page_vars);
}
