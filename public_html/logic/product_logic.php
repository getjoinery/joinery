<?php
function product_logic($get_vars, $post_vars, $product){
	require_once(__DIR__ . '/../includes/PathHelper.php');
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
	require_once(PathHelper::getIncludePath('includes/ShoppingCart.php'));
	require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/questions_class.php'));
	require_once(PathHelper::getIncludePath('data/products_class.php'));
	require_once(PathHelper::getIncludePath('data/product_versions_class.php'));
	require_once(PathHelper::getIncludePath('data/product_requirements_class.php'));
	require_once(PathHelper::getIncludePath('data/product_requirement_instances_class.php'));

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	if(!$settings->get_setting('products_active')){
		return LogicResult::error('This feature is turned off');
	}

	if($product){
		$page_vars['product'] = $product;
	}
	else if($get_vars['product_id']){
		$product_id = LibraryFunctions::fetch_variable_local($get_vars, 'product_id', NULL, TRUE, 'Product ID is required', TRUE, 'int');
		$product = new Product($product_id, TRUE);
	}
	else if($post_vars['product_id']){
		$product_id = LibraryFunctions::fetch_variable_local($post_vars, 'product_id', NULL, TRUE, 'Product ID is required', TRUE, 'int');
		$product = new Product($product_id, TRUE);
	}
	else{
		require_once(LibraryFunctions::display_404_page());
	}

	if($get_vars['product_version_id']){
		$product_version_id = LibraryFunctions::fetch_variable_local($get_vars, 'product_version_id', NULL, FALSE, '', TRUE, 'int');
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

	$page_vars['currency_symbol'] = Product::$currency_symbols[$settings->get_setting('site_currency')];

	$page_vars['display_empty_form'] = TRUE;

	if ($session->get_user_id()) {
		$user = new User($session->get_user_id(), TRUE);
	}
	else{
		$user = NULL;
	}
	$page_vars['user'] = $user;

	if ($post_vars || isset($get_vars['cart'])) {

		try {
			list($form_data, $display_data) = $product->validate_form($post_vars, $session);
			$page_vars['display_data'] = $display_data;
		}
		catch (BasicProductRequirementException $e) {
			return LogicResult::error($e->getMessage());
		}

		try {
			$cart = $session->get_shopping_cart();

			//IF USER ENTERED AN EXTRA DONATION CREATE THAT ITEM
			if($post_vars['user_price']){
				$extra_donation = new Product(Product::PRODUCT_ID_OPTIONAL_DONATION, TRUE);
				$cart->add_item($extra_donation, $form_data);
			}

			$cart->add_item($product, $form_data);
		}
		catch (ShoppingCartException $e) {
			return LogicResult::error($e->getMessage());
		}

		$form_key = md5(serialize($form_data) . time());
		$session->save_session_item($form_key, $form_data);
		$page_vars['display_empty_form'] = FALSE;

		// Redirect to cart after successful addition
		return LogicResult::redirect('/cart');
	}

	$page_vars['cart'] = $session->get_shopping_cart();

	return LogicResult::render($page_vars);
}
?>