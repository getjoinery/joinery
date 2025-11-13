<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_product_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/products_class.php'));
	require_once(PathHelper::getIncludePath('data/events_class.php'));
	require_once(PathHelper::getIncludePath('data/orders_class.php'));
	require_once(PathHelper::getIncludePath('data/order_items_class.php'));
	require_once(PathHelper::getIncludePath('data/product_groups_class.php'));
	require_once(PathHelper::getIncludePath('data/product_requirement_instances_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$settings = Globalvars::get_instance();
	$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')];

	$product = new Product($get_vars['pro_product_id'], TRUE);
	$orders = new MultiOrderItem(array('product_id' => $product->key));

	// Handle actions
	if($get_vars['action'] == 'delete'){
		$product->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$product->soft_delete();

		return LogicResult::redirect('/admin/admin_products');
	}
	else if($get_vars['action'] == 'undelete'){
		$product->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$product->undelete();

		return LogicResult::redirect('/admin/admin_products');
	}

	if($get_vars['action'] == 'permanent_delete'){
		if($orders->count_all()){
			throw new SystemDisplayableError('You cannot delete a product with orders.');
		}
		$product->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$product->permanent_delete();

		return LogicResult::redirect('/admin/admin_products');
	}

	// Build dropdown actions
	$options['altlinks'] = array();
	if($_SESSION['permission'] > 7){
		$options['altlinks'] += array('Edit Product'=> '/admin/admin_product_edit?p='.$product->key);
	}

	if(!$orders->count_all()){
		if($_SESSION['permission'] >= 5){
			$options['altlinks'] += array('Soft Delete'=> '/admin/admin_product?action=delete&pro_product_id='.$product->key);
		}
		if($_SESSION['permission'] == 10){
			$options['altlinks'] += array('Permanent Delete'=> '/admin/admin_product?action=permanent_delete&pro_product_id='.$product->key);
		}
	}

	// Build dropdown button from altlinks
	$dropdown_button = '';
	if (!empty($options['altlinks'])) {
		$dropdown_button = '<div class="dropdown">';
		$dropdown_button .= '<button class="btn btn-falcon-default btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Actions</button>';
		$dropdown_button .= '<div class="dropdown-menu dropdown-menu-end py-0">';
		foreach ($options['altlinks'] as $label => $url) {
			$is_danger = strpos($label, 'Delete') !== false;
			$dropdown_button .= '<a href="' . htmlspecialchars($url) . '" class="dropdown-item' . ($is_danger ? ' text-danger' : '') . '">' . htmlspecialchars($label) . '</a>';
		}
		$dropdown_button .= '</div>';
		$dropdown_button .= '</div>';
	}

	// Get event if exists
	$event = NULL;
	if($product->get('pro_evt_event_id')){
		$event = new Event($product->get('pro_evt_event_id'), TRUE);
	}

	// Get product group if exists
	$product_group = NULL;
	if($product->get('pro_prg_product_group_id')){
		$product_group = new ProductGroup($product->get('pro_prg_product_group_id'), TRUE);
	}

	// Get requirements
	$requirements = $product->get_requirement_info();
	$instances = $product->get_requirement_instances();

	$page_vars = array(
		'session' => $session,
		'settings' => $settings,
		'product' => $product,
		'orders' => $orders,
		'currency_symbol' => $currency_symbol,
		'dropdown_button' => $dropdown_button,
		'event' => $event,
		'product_group' => $product_group,
		'requirements' => $requirements,
		'instances' => $instances,
	);

	return LogicResult::render($page_vars);
}
