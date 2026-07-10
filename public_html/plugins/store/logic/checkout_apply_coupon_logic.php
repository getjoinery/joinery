<?php
/**
 * Apply a coupon code to the current shopping cart (checkout page JS).
 * Guest-reachable: the cart is session state, so anonymous browser sessions
 * invoke this with the allow_guest credential (docs/api.md § Authentication).
 *
 * @version 1.0.0
 */

function checkout_apply_coupon_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/CurrencyHelper.php'));
	require_once(PathHelper::getIncludePath('plugins/store/includes/ShoppingCart.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/coupon_codes_class.php'));

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('products_active')) {
		return LogicResult::error('This feature is turned off');
	}

	$code = isset($input['coupon_code']) ? trim((string) $input['coupon_code']) : '';
	if ($code === '') {
		return LogicResult::error('Please enter a coupon code.');
	}

	$cart = ShoppingCart::current();
	$result = $cart->add_coupon($code);
	if ($result !== 1) {
		return LogicResult::error(is_string($result) ? $result : 'Unable to apply coupon.');
	}

	$currency_symbol = CurrencyHelper::symbol($settings->get_setting('site_currency'));
	return LogicResult::render(array(
		'coupon_codes' => $cart->coupon_codes,
		'total'        => $currency_symbol . number_format($cart->get_total(), 2, '.', ','),
	));
}

function checkout_apply_coupon_logic_descriptor(): array {
	return [
		'description'      => 'Apply a coupon code to the current shopping cart.',
		'requires_session' => true,
		'mutates'          => true,
		'auth'             => [
			// The cart lives in the visitor's web session: guests may call
			// (anonymous browser credential), API keys may not (no session),
			// and the session must be re-opened so the cart mutation persists.
			'allow_guest'              => true,
			'requires_browser_session' => true,
			'session_write'            => true,
		],
		'input'            => [
			'coupon_code' => ['type' => 'string', 'required' => true, 'label' => 'Coupon code'],
		],
	];
}
?>
