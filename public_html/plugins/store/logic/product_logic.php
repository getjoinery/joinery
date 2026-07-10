<?php
function product_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/store/includes/ShoppingCart.php'));
	require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/questions_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/product_versions_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/product_requirements_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/product_requirement_instances_class.php'));

	$session = SessionControl::get_instance();
	$page_vars = [];
	$page_vars['session'] = $session;

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	if(!$settings->get_setting('products_active')){
		return LogicResult::error('This feature is turned off');
	}

	$product = null;
	if (!empty($input['slug'])) {
		$product = Product::get_by_link($input['slug']);
	} elseif (!empty($input['product_id'])) {
		$product_id = LibraryFunctions::fetch_variable_local($input, 'product_id', NULL, TRUE, 'Product ID is required', TRUE, 'int');
		$product = new Product($product_id, TRUE);
	}
	if (!$product || !$product->key) {
		require_once(LibraryFunctions::display_404_page());
	}

	if(!empty($input['product_version_id'])){
		$product_version_id = LibraryFunctions::fetch_variable_local($input, 'product_version_id', NULL, FALSE, '', TRUE, 'int');
		$product_version = new ProductVersion($product_version_id, TRUE);
		$page_vars['product_version'] = $product_version;
	}
	else {
		// If no specific version requested, get the first active version
		$product_versions = $product->get_product_versions(TRUE);
		if ($product_versions && count($product_versions) > 0) {
			$page_vars['product_version'] = $product_versions->get(0);
		} else {
			$page_vars['product_version'] = null;
		}
	}

	if ($product && $session->get_user_id() && $session->get_permission() > 4) {
		//SHOW IT EVEN IF UNPUBLISHED OR DELETED
	}
	else {
		if(!$product->get('pro_is_active') || $product->get('pro_delete_time')){
			require_once(LibraryFunctions::display_404_page());
		}
	}
	$page_vars['product'] = $product;

	$page_vars['currency_symbol'] = CurrencyHelper::symbol(strtolower($settings->get_setting('site_currency'))) ?? '$';

	$page_vars['display_empty_form'] = TRUE;

	if ($session->get_user_id()) {
		$user = new User($session->get_user_id(), TRUE);
	}
	else{
		$user = NULL;
	}
	$page_vars['user'] = $user;

	// Handle edit_item mode: pre-fill form with existing cart item data
	$edit_item_index = isset($input['edit_item']) ? intval($input['edit_item']) : null;
	if ($edit_item_index !== null && empty($_POST)) {
		$cart = ShoppingCart::current();
		$cart_item = $cart->get_item($edit_item_index);
		if ($cart_item) {
			$page_vars['edit_item_index'] = $edit_item_index;
			$page_vars['prefill_data'] = $cart_item[2]; // form_data is element [2]
		}
	}

	if (!empty($_POST)) {

		try {
			list($form_data, $display_data) = $product->validate_form($_POST, $session);
		}
		catch (BasicProductRequirementException $e) {
			return LogicResult::error($e->getMessage());
		}

		try {
			$cart = ShoppingCart::current();

			// Check if we're updating an existing cart item
			$edit_index = isset($input['edit_item_index']) ? intval($input['edit_item_index']) : null;
			if ($edit_index !== null && $cart->get_item($edit_index) !== null) {
				$cart->update_item($edit_index, $form_data);
			} else {
				// New item — add to cart
				if(!empty($input['user_price'])){
					$extra_donation = new Product(Product::PRODUCT_ID_OPTIONAL_DONATION, TRUE);
					$cart->add_item($extra_donation, $form_data);
				}
				$cart->add_item($product, $form_data);
			}
		}
		catch (ShoppingCartException $e) {
			return LogicResult::error($e->getMessage());
		}

		$settings = Globalvars::get_instance();
		$dest = $settings->get_setting('cart_intermediate_page') ? '/cart' : '/checkout';
		return LogicResult::redirect($dest);
	}

	$page_vars['cart'] = ShoppingCart::current();

	return LogicResult::render($page_vars);
}
?>