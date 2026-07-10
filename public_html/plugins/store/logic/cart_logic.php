<?php

function cart_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('plugins/store/includes/ShoppingCart.php'));
	require_once(PathHelper::getIncludePath('plugins/store/includes/StripeHelper.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/coupon_codes_class.php'));

	$page_vars = array();
	$session = SessionControl::get_instance();
	$settings = Globalvars::get_instance();
	$page_vars['session'] = $session;
	$page_vars['settings'] = $settings;

	$cart = ShoppingCart::current();

	// Handle item removal
	if (isset($_REQUEST['r']) && is_numeric($_REQUEST['r'])) {
		$cart->remove_item(intval($_REQUEST['r']));
		return LogicResult::redirect('/cart');
	}

	// Handle coupon removal via query param
	if (isset($_REQUEST['rc'])) {
		$cart->remove_coupon($_REQUEST['rc']);
		return LogicResult::redirect('/cart');
	}

	$currency_code = $settings->get_setting('site_currency');
	$page_vars['currency_code'] = $currency_code;
	$currency_symbol = CurrencyHelper::symbol($settings->get_setting('site_currency'));
	$page_vars['currency_symbol'] = $currency_symbol;

	if ($settings->get_setting('coupons_active')) {
		if (StripeHelper::isTestMode() && $session->get_permission() >= 8) {
			$coupon_codes = new MultiCouponCode(array());
			$coupon_codes->load();
			$page_vars['all_coupons'] = $coupon_codes;
		}

		if (isset($_GET['clear_coupon_code'])) {
			$cart->remove_coupon($_GET['clear_coupon_code']);
			return LogicResult::redirect('/cart');
		} else if (isset($_GET['coupon_code']) && $_GET['coupon_code']) {
			$result = $cart->add_coupon($_GET['coupon_code']);
			if ($result != 1) {
				$page_vars['coupon_error'] = $result;
			}
		}
	}

	$page_vars['cart'] = $cart;
	return LogicResult::render($page_vars);
}

function cart_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'Cart review page',
	];
}
