<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_product_version_edit_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/StripeHelper.php'));
	require_once(PathHelper::getIncludePath('data/products_class.php'));
	require_once(PathHelper::getIncludePath('data/product_versions_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$settings = Globalvars::get_instance();
	$currency_code = $settings->get_setting('site_currency');
	$currency_symbol = Product::$currency_symbols[strtolower($currency_code)] ?? '$';

	// Load product
	if (!isset($get_vars['product_id']) && !isset($post_vars['product_id'])) {
		return LogicResult::redirect('/admin/admin_products');
	}
	$product_id = isset($post_vars['product_id']) ? $post_vars['product_id'] : $get_vars['product_id'];
	$product = new Product($product_id, TRUE);

	// Load or create product version
	// CRITICAL: Check edit_primary_key_value (form submission first), fallback to GET
	if (isset($post_vars['edit_primary_key_value'])) {
		$product_version = new ProductVersion($post_vars['edit_primary_key_value'], TRUE);
	} elseif (isset($get_vars['product_version_id'])) {
		$product_version = new ProductVersion($get_vars['product_version_id'], TRUE);
	} else {
		$product_version = new ProductVersion(NULL);
	}

	// Check if any orders reference this product version
	$has_orders = false;
	if ($product_version->key) {
		$dbconnector = DbConnector::get_instance();
		$dblink = $dbconnector->get_db_link();
		$q = $dblink->prepare("SELECT COUNT(*) FROM odi_order_items WHERE odi_prv_product_version_id = ?");
		$q->execute([$product_version->key]);
		$has_orders = $q->fetchColumn() > 0;
	}

	// Process POST actions
	// CRITICAL: Check for POST submission
	if ($post_vars) {
		$product_version->set('prv_version_name', $post_vars['version_name']);

		if(isset($post_vars['prv_display_priority'])){
			$product_version->set('prv_display_priority', $post_vars['prv_display_priority']);
		}

		// Set price fields for new versions, or existing versions with no orders
		if((!$product_version->key || !$has_orders) && isset($post_vars['version_price'])){
			$product_version->set('prv_pro_product_id', $product->key);
			$product_version->set('prv_version_price', $post_vars['version_price']);
			$product_version->set('prv_price_type', $post_vars['prv_price_type']);
			$product_version->set('prv_trial_period_days', $post_vars['prv_trial_period_days']);
			if(!$product_version->key){
				$product_version->set('prv_status', 1);
			}
		}

		$product_version->prepare();
		$product_version->save();

		// Sync Stripe price when editing
		if($settings->get_setting('checkout_type') != 'none'){
			try {
				$stripe_helper = new StripeHelper();
				$stripe_price = $stripe_helper->get_or_create_price($product_version, NULL);
			} catch (Exception $e) {
				error_log('StripeHelper::get_or_create_price failed for product version ' . $product_version->key . ': ' . $e->getMessage());
			}
		}

		return LogicResult::redirect('/admin/admin_product?pro_product_id='. $product->key);
	}

	// Handle GET actions for version management
	if ($get_vars['action'] == 'remove_version') {
		$product_version = new ProductVersion($get_vars['product_version_id'], TRUE);
		$product_version->set('prv_status', 0);
		$product_version->prepare();
		$product_version->save();
		return LogicResult::redirect('/admin/admin_product?pro_product_id='. $product->key);
	}
	else if ($get_vars['action'] == 'activate_version') {
		$product_version = new ProductVersion($get_vars['product_version_id'], TRUE);
		$product_version->set('prv_status', 1);
		$product_version->prepare();
		$product_version->save();
		return LogicResult::redirect('/admin/admin_product?pro_product_id='. $product->key);
	}

	// Load data for display
	$options = [];
	if ($product_version->key) {
		$options['title'] = 'Product Version Edit - '. $product_version->get('prv_version_name');
		$breadcrumb = 'Product '.$product->get('pro_name');
	}
	else{
		$options['title'] = 'New Product Version';
		$breadcrumb = 'New Product Version';
	}

	// Return page variables for rendering
	return LogicResult::render(array(
		'product_version' => $product_version,
		'product' => $product,
		'pageoptions' => $options,
		'breadcrumb' => $breadcrumb,
		'currency_symbol' => $currency_symbol,
		'has_orders' => $has_orders,
		'session' => $session,
		'settings' => $settings,
	));
}

?>
