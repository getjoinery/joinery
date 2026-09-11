<?php

function admin_product_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/product_groups_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/product_requirement_instances_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$settings = Globalvars::get_instance();
	$currency_symbol = CurrencyHelper::symbol($settings->get_setting('site_currency'));

	$product = new Product($input['pro_product_id'], TRUE);
	$orders = new MultiOrderItem(array('product_id' => $product->key));

	// Handle actions
	if($input['action'] == 'delete'){
		$product->assert_can_write($session);
		$product->soft_delete();

		return LogicResult::redirect('/plugins/store/admin/admin_products');
	}
	else if($input['action'] == 'undelete'){
		$product->assert_can_write($session);
		$product->undelete();

		return LogicResult::redirect('/plugins/store/admin/admin_products');
	}

	if($input['action'] == 'permanent_delete'){
		if($orders->count_all()){
			return LogicResult::error('You cannot delete a product with orders.');
		}
		$product->assert_can_write($session);
		$product->permanent_delete();

		return LogicResult::redirect('/plugins/store/admin/admin_products');
	}

	// Build dropdown actions
	$options['altlinks'] = array();
	if($_SESSION['permission'] > 7){
		$options['altlinks'] += array('Edit Product'=> '/plugins/store/admin/admin_product_edit?p='.$product->key);
	}

	if(!$orders->count_all()){
		if($_SESSION['permission'] >= 5){
			$options['altlinks'] += array('Soft Delete'=> array('post' => '/plugins/store/admin/admin_product', 'hidden' => array('action' => 'delete', 'pro_product_id' => $product->key)));
		}
		if($_SESSION['permission'] == 10){
			$options['altlinks'] += array('Permanent Delete'=> array('post' => '/plugins/store/admin/admin_product', 'hidden' => array('action' => 'permanent_delete', 'pro_product_id' => $product->key)));
		}
	}

	// Build dropdown button from altlinks
	$dropdown_button = '';
	if (!empty($options['altlinks'])) {
		$dropdown_button = '<div class="dropdown">';
		$dropdown_button .= '<button class="btn btn-soft-default btn-sm dropdown-toggle" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Actions</button>';
		$dropdown_button .= '<div class="dropdown-menu dropdown-menu-end py-0">';
		foreach ($options['altlinks'] as $label => $entry) {
			$is_danger = strpos($label, 'Delete') !== false;
			$dropdown_button .= AdminPage::renderActionEntry($label, $entry, 'dropdown-item' . ($is_danger ? ' text-danger' : ''));
		}
		$dropdown_button .= '</div>';
		$dropdown_button .= '</div>';
	}

	// Resolve what this product's purchase grants (fulfillment), for display.
	$fulfillment_display = NULL;
	if($product->get('pro_fulfillment_provider')){
		require_once(PathHelper::getIncludePath('plugins/store/includes/FulfillmentRegistry.php'));
		$fp = FulfillmentRegistry::get($product->get('pro_fulfillment_provider'));
		if($fp){
			$fulfillment_display = $fp->displayReference((int)$product->get('pro_fulfillment_ref'));
		}
	}

	// Get product group if exists
	$product_group = NULL;
	if($product->get('pro_prg_product_group_id')){
		$product_group = new ProductGroup($product->get('pro_prg_product_group_id'), TRUE);
	}

	// Get requirements
	$requirements = $product->get_requirement_info();

	$page_vars = array(
		'session' => $session,
		'settings' => $settings,
		'product' => $product,
		'orders' => $orders,
		'currency_symbol' => $currency_symbol,
		'dropdown_button' => $dropdown_button,
		'fulfillment_display' => $fulfillment_display,
		'product_group' => $product_group,
		'requirements' => $requirements,
	);

	return LogicResult::render($page_vars);
}
