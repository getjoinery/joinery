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

	// Back after a card payment the webhook completed while this browser was
	// away: the paid lines leave the cart and the confirmation is shown.
	if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
		require_once(PathHelper::getIncludePath('plugins/store/logic/cart_charge_logic.php'));
		require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));
		$settled = cart_charge_settle_completed_card();
		if ($settled !== null) {
			return $settled;
		}
	}

	// Handle item removal
	// A POST, never a link: a link is a GET any site can send this browser to,
	// and it would take a line out of the cart.
	$cart_is_post = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST';
	if ($cart_is_post && isset($_POST['r']) && is_numeric($_POST['r'])) {
		// With what the line brought with it: a site's domain year and its
		// licence are not left behind to be paid for alone.
		$cart->remove_item_with_companions(intval($_POST['r']));
		return LogicResult::redirect('/cart');
	}

	// Handle coupon removal
	if ($cart_is_post && isset($_POST['rc'])) {
		$cart->remove_coupon((string)$_POST['rc']);
		return LogicResult::redirect('/cart');
	}

	$currency_code = $settings->get_setting('site_currency');
	$page_vars['currency_code'] = $currency_code;
	$currency_symbol = CurrencyHelper::symbol($settings->get_setting('site_currency'));
	$page_vars['currency_symbol'] = $currency_symbol;

	if ($settings->get_setting('coupons_active')) {
		if (StripeHelper::isTestMode() && Access::permits(CouponCode::access_level('read'), $session)) {
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
	$page_vars['purchase_path_headings'] = $cart->purchase_path_headings();
	return LogicResult::render($page_vars);
}

function cart_logic_descriptor() {
	return [
		'auth' => ['min_user_permission' => 0],
		'requires_session' => true,
		'description' => 'Cart review page',
	];
}
