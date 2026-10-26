<?php
/**
 * Record what a PayPal payment is for, as the PayPal button starts it
 * (checkout page JS, PayPal's createOrder / createSubscription).
 *
 * The record (ShoppingCart::record_payment()) is what PayPal's return completes
 * the order from; it is made here, by the buyer's own POST, rather than by the
 * checkout page's view. Answers the reference the order or subscription
 * carries as its custom_id, and for a subscription cart the plan to subscribe
 * to. Guest-reachable: the cart is session state (docs/api.md § Authentication).
 *
 * @version 1.0.0
 */

function checkout_record_paypal_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/store/includes/ShoppingCart.php'));
	require_once(PathHelper::getIncludePath('plugins/store/includes/PaypalHelper.php'));

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('products_active') || !$settings->get_setting('use_paypal_checkout')) {
		return LogicResult::error('PayPal is not available here.');
	}

	$cart = ShoppingCart::current();
	// The buttons carry the cart the page showed; PayPal is about to be asked
	// for that page's amount.
	if ((string)($input['cart_cs'] ?? '') !== $cart->get_hash()) {
		return LogicResult::error('Your cart changed since this page was shown. Please reload the page and pay again.');
	}
	if (($refusal = $cart->payment_start_refusal()) !== null) {
		return LogicResult::error($refusal);
	}
	if (!$cart->is_paypal_available()) {
		return LogicResult::error('PayPal cannot take this cart: a subscription must be checked out on its own.');
	}

	$paypal = new PaypalHelper();
	$plan_id = $paypal->plan_for_cart($cart);
	$ref = 'pp_' . bin2hex(random_bytes(16));

	// The buyer's own cart, recorded for the payment they are starting: the
	// checkout's rules above are the authority, as for the card charge.
	SystemBase::rule_checked_write(function () use ($cart, $ref, $plan_id) {
		$cart->record_payment($ref, 'paypal', array('plan_id' => $plan_id));
	});

	return LogicResult::render(array('custom_id' => $ref, 'plan_id' => $plan_id));
}

function checkout_record_paypal_logic_descriptor(): array {
	return [
		'description'      => 'Record the current cart for the PayPal payment the checkout page is starting; returns the custom_id it carries, and the plan for a subscription.',
		'requires_session' => true,
		'mutates'          => true,
		'auth'             => [
			'min_user_permission'      => 0,
			'allow_guest'              => true,
			'requires_browser_session' => true,
		],
		'input'            => [
			'cart_cs' => ['type' => 'string', 'required' => true, 'label' => 'The cart the checkout page showed (its hash)'],
		],
	];
}
?>
