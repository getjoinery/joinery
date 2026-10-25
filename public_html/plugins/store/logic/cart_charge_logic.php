<?php

/**
 * Save a payment error to session and return a redirect to /cart.
 * This ensures all payment errors show on the checkout page instead of throwing exceptions.
 */
function _checkout_error($message) {
	$session = SessionControl::get_instance();
	$session->save_message(new DisplayMessage($message, 'Payment Error', null, DisplayMessage::MESSAGE_ERROR));
	return LogicResult::redirect('/checkout');
}

/**
 * A gateway's return whose payment was taken but is not made into an order:
 * it matches no checkout in this session, or its checkout can no longer be
 * completed as recorded. The buyer has paid, so they land on /cart_confirm,
 * which offers no payment, told to contact the site; /checkout would offer the
 * same cart again and take a second payment. Nothing is written; the reason is
 * logged for the operator.
 */
function _cart_charge_paid_not_completed(string $log_detail, ?string $buyer_message = null) {
	error_log('cart_charge: ' . $log_detail);
	$support_email = Globalvars::get_instance()->get_setting('defaultemail');
	$contact = 'Please contact us' . ($support_email ? ' at ' . $support_email : '') . ' so we can complete your order.';
	$message = ($buyer_message ?? 'Your payment was received, but we could not complete your order.') . ' ' . $contact;
	SessionControl::get_instance()->save_message(new DisplayMessage($message, 'Payment received', '/cart_confirm',
		DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, ShoppingCart::PAYMENT_NOT_COMPLETED));
	return LogicResult::redirect('/cart_confirm');
}

/**
 * Resolve which template name to use for this product's per-product receipt email.
 * Falls back to $default_name when the product has no override or the override
 * points to a missing/soft-deleted template (best-effort, never load-bearing).
 */
function _resolve_receipt_template(Product $product, $default_name) {
	require_once(PathHelper::getIncludePath('data/email_templates_class.php'));
	$override_id = $product->get('pro_emt_email_template_id');
	if ($override_id) {
		$tpl = new EmailTemplate($override_id, TRUE);
		// SystemBase preserves $key even on failed load. Use emt_name as the
		// "row exists" signal — it's required, so null means the load missed.
		$tpl_name = $tpl->get('emt_name');
		if ($tpl_name && !$tpl->get('emt_delete_time')) {
			return $tpl_name;
		}
	}
	return $default_name;
}

/**
 * Whether a captured PayPal order paid what this cart asks. The amount PayPal
 * charged is whatever the browser asked it for (the order is created in the
 * page's script), so it is compared with what the checkout would ask for this
 * cart, currency included.
 */
function _cart_charge_paypal_amount_matches($paypal, $payment, $cart): bool {
	$expected = $paypal->build_item_array($cart->get_detailed_items());
	if (!$expected || empty($payment['purchase_units']) || !is_array($payment['purchase_units'])) {
		return false;
	}
	$want_cents = 0;
	foreach ($expected['purchase_units'] as $unit) {
		$want_cents += (int)round((float)$unit['amount']['value'] * 100);
		$want_currency = strtoupper((string)$unit['amount']['currency_code']);
	}
	$paid_cents = 0;
	foreach ($payment['purchase_units'] as $unit) {
		if (strtoupper((string)($unit['amount']['currency_code'] ?? '')) !== $want_currency) {
			return false;
		}
		$paid_cents += (int)round((float)($unit['amount']['value'] ?? 0) * 100);
	}
	return $paid_cents === $want_cents;
}

/**
 * The gateway helper this request uses (StripeHelper, PaypalHelper), made once.
 * A suite stands one in by passing $stand_in, since the gateways' pages cannot
 * be paid from a script.
 */
function _cart_charge_gateway(string $class, $stand_in = null) {
	static $helpers = array();
	if ($stand_in !== null) {
		$helpers[$class] = $stand_in;
	}
	if (!isset($helpers[$class])) {
		$helpers[$class] = new $class();
	}
	return $helpers[$class];
}

/**
 * Verify a gateway's return with the gateway and find what it paid for,
 * writing nothing.
 *
 * What was paid for is the checkout's record of the payment, made when the
 * checkout page created it (ShoppingCart::record_payment()), never the live
 * cart: the cart can change in another tab between creating the payment and
 * following its return URL. The gateway's reference is what ties the two: a
 * Stripe Checkout session's id, or the custom_id a PayPal order or
 * subscription carries. The record's items, prices, coupons and billing
 * details are what the order is made from.
 *
 * Returns a LogicResult to answer with (an error, or the receipt of a
 * payment already completed into its order), or what the charge needs:
 *   service       'stripe_checkout' | 'paypal'
 *   ref           the gateway reference the record is kept under
 *   lock_ref      the payment reference its order is locked on
 *   record        the record
 *   cart          a ShoppingCart rebuilt from the record
 *   order_fields  what the gateway reported, for the order
 *   stripe_subscription_id / paypal_subscription_id  for a subscription line
 */
function _cart_charge_verify_return($settings) {
	$unmatched = function ($what, $detail) {
		return _cart_charge_paid_not_completed($what . ' ' . $detail . ' paid but not completed',
			'Your payment was received, but we could not match it to your checkout.');
	};
	// The order is made from the record's lines: a line whose product or
	// version no longer loads would leave it short of what was paid for.
	$hydrate_whole = function (array $record) {
		$cart = ShoppingCart::hydrate($record);
		$recorded = is_array($record['items'] ?? null) ? count($record['items']) : 0;
		return ($recorded > 0 && count($cart->items) === $recorded) ? $cart : null;
	};

	// --- Stripe Checkout: ?session_id= -------------------------------------
	if ($settings->get_setting('checkout_type') == 'stripe_checkout' && !empty($_GET['session_id'])) {
		$stripe_helper = _cart_charge_gateway('StripeHelper');
		try {
			$stripe_session = $stripe_helper->validate_session_id((string)$_GET['session_id']);
		} catch (StripeHelperException $e) {
			error_log("Stripe session validation failed: " . $e->getMessage());
			return _checkout_error("Your payment session has expired or is invalid. Please try again.");
		}
		$session_id = (string)$stripe_session->id;

		// A return followed again (a reload, the back button) shows the receipt.
		$existing = Order::GetByStripeSession($session_id);
		if ($existing && $existing->is_fulfilled()) {
			return LogicResult::redirect('/cart_confirm');
		}

		$record = ShoppingCart::recorded_payment($session_id);
		if (!$record || ($record['gateway'] ?? '') !== 'stripe_checkout') {
			return $unmatched('Stripe Checkout session', substr($session_id, 0, 20) . ' (matches no checkout in this session)');
		}
		// The session was made for whoever was signed in then; Stripe says so too.
		$reference_user = (string)($stripe_session->client_reference_id ?? '');
		if ($reference_user !== (string)($record['payer_user_id'] ?? '')) {
			return $unmatched('Stripe Checkout session', substr($session_id, 0, 20) . ' (client_reference_id differs from the record)');
		}

		$record_cart = $hydrate_whole($record);
		if (!$record_cart) {
			return $unmatched('Stripe Checkout session', substr($session_id, 0, 20) . ' (a recorded line no longer loads)');
		}

		return array(
			'service'      => 'stripe_checkout',
			'ref'          => $session_id,
			'lock_ref'     => $session_id,
			'record'       => $record,
			'cart'         => $record_cart,
			'order_fields' => array(
				'ord_stripe_session_id'        => $session_id,
				'ord_stripe_payment_intent_id' => $stripe_session->payment_intent ?? null,
				'ord_payment_method'           => 'stripe_checkout',
			),
			'stripe_subscription_id' => $stripe_session->subscription ?? null,
		);
	}

	// --- PayPal: ?id= (an order) or ?subscription=1&paypal_subscription_id= --
	if ($settings->get_setting('use_paypal_checkout') && (!empty($_GET['id']) || !empty($_GET['subscription']))) {
		$paypal = _cart_charge_gateway('PaypalHelper');
		$is_subscription = !empty($_GET['subscription']);
		$paypal_id = trim((string)($is_subscription ? ($_GET['paypal_subscription_id'] ?? '') : ($_GET['id'] ?? '')));
		if (!preg_match('/^[A-Za-z0-9-]{5,64}$/', $paypal_id)) {
			return _checkout_error($is_subscription
				? 'PayPal subscription could not be verified. Please try again.'
				: 'Your PayPal payment could not be verified. Please try again or use a different payment method.');
		}

		if ($is_subscription) {
			// A subscription is paid only once PayPal confirms it.
			$payment = $paypal->subDetails($paypal_id);
			if (!$payment || !in_array($payment['status'] ?? '', ['ACTIVE', 'APPROVED'])) {
				return _checkout_error('PayPal subscription could not be verified. Please try again.');
			}
			$custom_id = (string)($payment['custom_id'] ?? '');
		}
		else {
			$payment = $paypal->validatePayment($paypal_id);
			if (($payment['status'] ?? '') !== 'COMPLETED' || empty($payment['purchase_units']) || !is_array($payment['purchase_units'])) {
				return _checkout_error("Your PayPal payment could not be verified. Please try again or use a different payment method.");
			}
			$custom_id = (string)($payment['purchase_units'][0]['custom_id'] ?? '');
		}

		// A PayPal payment pays for one order, however often its URL is
		// followed: once that order has its lines, the return shows its
		// receipt. An order the first attempt saved but did not complete is
		// completed, as a Stripe Checkout session's is.
		foreach (new MultiOrder(array('ord_paypal_order_id' => $paypal_id)) as $used_order) {
			if ($used_order->is_fulfilled()) {
				return LogicResult::redirect('/cart_confirm');
			}
		}

		$what = 'PayPal ' . ($is_subscription ? 'subscription' : 'order');
		$record = ShoppingCart::recorded_payment($custom_id);
		if (!$record || ($record['gateway'] ?? '') !== 'paypal') {
			return $unmatched($what, $paypal_id . ' (matches no checkout in this session)');
		}
		$record_cart = $hydrate_whole($record);
		if (!$record_cart) {
			return $unmatched($what, $paypal_id . ' (a recorded line no longer loads)');
		}

		// What PayPal took must be what the record asks: it is whatever the
		// page's script asked PayPal for. A subscription pays for its one
		// line only (the checkout offers it for a cart of one subscription,
		// quantity one); in a cart with other lines it would leave them unpaid.
		if ($is_subscription) {
			$only_line = (count($record_cart->items) === 1) ? reset($record_cart->items) : null;
			if (!$only_line || (int)$only_line[0] !== 1 || !$only_line[5]->is_subscription()
				|| empty($record['plan_id']) || (string)($payment['plan_id'] ?? '') !== (string)$record['plan_id']) {
				return $unmatched($what, $paypal_id . ' (not the recorded cart\'s one subscription and plan)');
			}
		}
		else if (!_cart_charge_paypal_amount_matches($paypal, $payment, $record_cart)) {
			return $unmatched($what, $paypal_id . ' (the amount paid is not the recorded cart\'s)');
		}

		$funding_source = isset($_GET['funding']) ? $_GET['funding'] : 'paypal';
		$payment_method = in_array($funding_source, array('paypal', 'venmo', 'card', 'paylater'), true) ? $funding_source : 'paypal';

		return array(
			'service'      => 'paypal',
			'ref'          => $custom_id,
			'lock_ref'     => $paypal_id,
			'record'       => $record,
			'cart'         => $record_cart,
			'order_fields' => array(
				'ord_paypal_order_id' => $paypal_id,
				'ord_payment_method'  => $payment_method,
				'ord_raw_response'    => json_encode($payment),
			),
			'paypal_subscription_id' => $is_subscription ? $paypal_id : null,
		);
	}

	return _checkout_error("We couldn't process your payment. Please try again or contact support.");
}

/**
 * Whether this request is a payment gateway sending the buyer back: Stripe
 * Checkout's success_url (?session_id=) or PayPal's return (?id= for an order,
 * ?subscription= for a subscription). The handler verifies each with the
 * gateway before it persists anything.
 */
function _cart_charge_is_gateway_return(): bool {
	$settings = Globalvars::get_instance();
	if ($settings->get_setting('checkout_type') == 'stripe_checkout' && !empty($_GET['session_id'])) {
		return true;
	}
	if ($settings->get_setting('use_paypal_checkout') && (!empty($_GET['id']) || !empty($_GET['subscription']))) {
		return true;
	}
	return false;
}

/**
 * Who is behind the checkout's writes, and so which marker they run under.
 *
 * None of them can be checked as the buyer. A guest buyer has no session
 * identity at all (the account is made here, from the billing email); a gift's
 * order line, registration and tier belong to the recipient; and what a
 * purchase grants (a tier, an ownership, an event seat, a coupon use, a
 * product's remaining count, the version's Stripe price) is the payment's to
 * grant, not something a member's level lets them write. The authority is the
 * checkout's own rules (the cart is this session's; coupons, ownership,
 * availability and the recipients checked before any money moves) and the
 * payment itself, verified with the gateway or charged here.
 *
 *  - The gateway's return (a GET the gateway's redirect makes) persists the
 *    gateway's verified result: server_initiated_write().
 *  - The buyer's own POST (a card charge, or a free order from the billing
 *    form) runs inside rule_checked_write(), which keeps the page-view guard.
 *  - Any other GET is a link, not a checkout, and persists nothing.
 */
function cart_charge_logic(array $input): LogicResult{
	$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? ''));
	$page_view = ($method === 'GET' || $method === 'HEAD');
	$run = function () use ($input) { return _cart_charge_run($input); };

	if (_cart_charge_is_gateway_return()) {
		return SystemBase::server_initiated_write($run);
	}
	if ($page_view) {
		return LogicResult::redirect('/checkout');
	}
	return SystemBase::rule_checked_write($run);
}

/**
 * The charge. $return is a gateway return already verified by
 * _cart_charge_verify_return(); the run for it holds that payment's lock
 * (Order::with_payment_lock()), so the return and the gateway's webhook, or
 * the same return followed twice, make and complete one order.
 */
function _cart_charge_run(array $input, ?array $return = null): LogicResult{

	require_once(PathHelper::getIncludePath('plugins/store/includes/ShoppingCart.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/EmailTemplateRenderer.php'));
	require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
	require_once(PathHelper::getIncludePath('plugins/store/includes/StripeHelper.php'));
	require_once(PathHelper::getIncludePath('plugins/store/includes/PaypalHelper.php'));
	require_once(PathHelper::getIncludePath('includes/Activation.php'));
	require_once(PathHelper::getIncludePath('data/groups_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
	require_once(PathHelper::getIncludePath('data/users_addrs_class.php'));
	require_once(PathHelper::getIncludePath('data/phone_numbers_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/coupon_codes_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/coupon_code_uses_class.php'));
	require_once(PathHelper::getIncludePath('data/notifications_class.php'));
	
			
	$page_vars = array();

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;

  try {

	if(!$settings->get_setting('products_active')){
		return _checkout_error('Purchasing is currently unavailable. Please try again later.');
	}

	// The checkout page asks a signed-in buyer with no email to add one; this holds
	// the same rule for a charge that reaches here another way.
	if($session->get_user_id() && !(new User($session->get_user_id(), TRUE))->get('usr_email')){
		return User::add_email_first(User::CHECKOUT_NEEDS_EMAIL);
	}
	
	$currency_code = $settings->get_setting('site_currency');
	$page_vars['currency_code'] = $currency_code;
	$currency_symbol = CurrencyHelper::symbol($settings->get_setting('site_currency'));
	$page_vars['currency_symbol'] = $currency_symbol;

	


	// A gateway's return completes the payment the checkout page recorded,
	// from that record: verified with the gateway first, writing nothing, and
	// then under the payment's lock.
	if($return === null && _cart_charge_is_gateway_return()){
		$verified = _cart_charge_verify_return($settings);
		if($verified instanceof LogicResult){
			return $verified;
		}
		return Order::with_payment_lock($verified['lock_ref'], function () use ($input, $verified) {
			return _cart_charge_run($input, $verified);
		});
	}

	$existing_order = null;
	if($return !== null){
		// Under the lock: a return that got here first has completed it.
		if($return['service'] === 'stripe_checkout'){
			$existing_order = Order::GetByStripeSession($return['ref']);
			if($existing_order && $existing_order->is_fulfilled()){
				return LogicResult::redirect('/cart_confirm');
			}
		}
		else{
			foreach(new MultiOrder(array('ord_paypal_order_id' => $return['lock_ref'])) as $used_order){
				if($used_order->is_fulfilled()){
					return LogicResult::redirect('/cart_confirm');
				}
				$existing_order = $used_order;
			}
		}
		$cart = $return['cart'];
	}
	else{
		$cart = ShoppingCart::current();
	}
	$page_vars['cart'] = $cart;
	$charge_total = $cart->get_total();

	// On a gateway's return the buyer has already paid: a refusal from here
	// on lands where no second payment is offered.
	$refuse = function (string $message, ?string $buyer_message = null) use ($return) {
		if($return === null){
			return _checkout_error($message);
		}
		return _cart_charge_paid_not_completed('gateway return ' . $return['lock_ref'] . ' not completed: ' . $message, $buyer_message);
	};

	// A free order completes from the checkout's billing form, posted here
	// with the billing details. Only a cart with nothing to pay completes so.
	if($return === null && !empty($_POST['complete_order'])){
		if($charge_total > 0){
			return LogicResult::redirect('/checkout');
		}
		if(!empty($_POST['billing_email'])){
			$cart->determine_billing_user($_POST, false);
		}
	}
	


	if($charge_total){
		if($settings->get_setting('use_paypal_checkout')){
			$paypal = _cart_charge_gateway('PaypalHelper');
			$page_vars['paypal_helper'] = $paypal;
		}
		if($settings->get_setting('checkout_type') != 'none'){
			$stripe_helper = _cart_charge_gateway('StripeHelper');
		}
	}

	$receipts = array();
	$line_summaries = array();
	$applied_coupon_codes = array();
	
	
	if(!$cart->items){
		return LogicResult::redirect('/cart_confirm');		
	}
	
	//DEBUG
	/*
	foreach($cart->get_items() as $key => $cart_item) {
		print_r($cart_item);
	}
	*/


	//HANDLE THE BILLING USER
	// On a gateway's return, the billing details are the ones the payment was
	// recorded with, and "who was signed in" is who was when it was made.
	$billing_details = ($return !== null) ? (array)($return['record']['billing_user'] ?? array()) : (array)$cart->billing_user;
	$payer_session_user = ($return !== null) ? ($return['record']['payer_user_id'] ?? null) : $session->get_user_id();
	$billing_user = User::GetByEmail($billing_details['billing_email'] ?? '');
	if ($billing_user && $billing_user->get('usr_delete_time')) {
		return $refuse(User::CLOSED_ACCOUNT_MESSAGE);
	}
	// Paying as an account that already exists needs that account's sign-in,
	// as the checkout page says: the charge, its Stripe customer and the order
	// are written to that account. A guest's checkout makes a new one. The
	// checkout page offers no payment until then, so a gateway's record that
	// names another account than the one signed in when it was made (an
	// account made from that email since) is not completed: the buyer has
	// paid, so they are told to contact the site, and it is logged.
	if($billing_user && (int)$billing_user->key !== (int)$payer_session_user){
		if($return !== null){
			return $refuse('records billing as account ' . $billing_user->key . ' but was made by '
				. ($payer_session_user ? 'account ' . $payer_session_user : 'a guest'),
				'Your payment was received, but ' . $billing_user->get('usr_email')
				. ' belongs to an account you were not logged in to when you paid.');
		}
		return _checkout_error('An account already uses ' . $billing_user->get('usr_email')
			. '. Log in to check out with it.');
	}
	if(!$billing_user){
		$user_data = array(
			'usr_first_name' => $billing_details['billing_first_name'] ?? '',
			'usr_last_name' => $billing_details['billing_last_name'] ?? '',
			'usr_email' => $billing_details['billing_email'] ?? '',
		);
		
		if($return === null && !empty($_POST['password'])){
			$user_data['password'] = $_POST['password'];
		}
		$billing_user = User::CreateCompleteNew($user_data, true, true, false);
		if(!$billing_user){
			return $refuse("We couldn't create your account. Please check your information and try again.");
		}
		// Implicit consent was shown under the Continue button on the cart billing form.
		$billing_user->set('usr_terms_accepted_time', gmdate('Y-m-d H:i:s'));
		$billing_user->save();
		$_SESSION['terms_accepted'] = true;
	}
	
	if($settings->get_setting('checkout_type') == 'stripe_regular' || $settings->get_setting('checkout_type') == 'stripe_checkout'){
		$stripe_helper = _cart_charge_gateway('StripeHelper');
		$stripe_customer_id = $stripe_helper->get_or_create_stripe_customer($billing_user);
	}

	
	//CHECK THE COUPON CODES BEFORE WE CHARGE
	foreach($cart->coupon_codes as $coupon_code_name){
		$coupon_code_test = CouponCode::GetByColumn('ccd_code', trim($coupon_code_name));
		if(!$coupon_code_test->is_valid()){
			return $refuse("One of your coupon codes is no longer valid. Please remove it and try again.");
		}

	}

	//PHASE 1 of cart_charge_atomicity: pre-charge user resolution.
	//Resolve every per-line recipient user *before* the charge so any user-
	//creation validation error surfaces to /cart without touching the customer's
	//card. send_emails=false here defers the activation email to pass 1 of the
	//post-charge work, gated on $is_newly_created — this prevents declined or
	//abandoned checkouts from emailing the recipient.
	$resolved_users = array();
	$is_newly_created = array();
	foreach($cart->items as $key => $cart_item) {
		list($quantity, $product, $data, $price, $discount, $product_version) = $cart_item;
		if(!empty($data['email'])){
			$existing_user = User::GetByEmail($data['email']);
			if ($existing_user && $existing_user->get('usr_delete_time')) {
				return $refuse($data['email'] . ': ' . User::CLOSED_ACCOUNT_MESSAGE);
			}
			if($existing_user){
				$resolved_users[$key] = $existing_user;
				$is_newly_created[$key] = false;
			}
			else {
				try {
					$user_data = array(
						'usr_first_name' => $data['full_name_first'],
						'usr_last_name' => $data['full_name_last'],
						'usr_email' => $data['email'],
					);
					$resolved_users[$key] = User::CreateCompleteNew($user_data, false, false, false);
					$is_newly_created[$key] = true;
				}
				catch (Exception $e) {
					error_log("Pre-charge user resolution failed for cart line {$key} ({$data['email']}): " . $e->getMessage());
					return $refuse("We couldn't create an account for " . $data['email'] . ". Please check the recipient details and try again.");
				}
			}
		}
		else {
			$resolved_users[$key] = $billing_user;
			$is_newly_created[$key] = false;
		}
	}

	//Source exclusivity, pre-charge: a recipient whose active subscription is
	//billed through the App Store or Google Play can't start a web-billed one
	//— they must cancel in the store first. Surfaces before any card charge.
	require_once(PathHelper::getIncludePath('plugins/store/includes/TierBilling.php'));
	foreach($cart->items as $key => $cart_item) {
		list($quantity, $product, $data, $price, $discount, $product_version) = $cart_item;
		if($product_version->is_subscription()){
			$conflict = TierBilling::sourceConflict($resolved_users[$key]->key, 'stripe');
			if ($conflict !== null && in_array($conflict, array('app_store', 'play_store'))) {
				return $refuse('This subscription is billed through ' . TierBilling::sourceLabel($conflict)
					. '. Cancel it in the app or your store account settings before subscribing here.');
			}
		}
	}

	//Fulfillment availability, pre-charge: anything with a finite supply gets to
	//refuse while the purchase can still be declined for free. fulfill() runs
	//only after payment succeeds, so a refusal there would mean the buyer has
	//already been charged for something that cannot be delivered.
	//
	//Asked only while declining IS free. On a gateway's return (a hosted Stripe
	//Checkout session, a captured PayPal order or subscription) the buyer has
	//already paid; a refusal here would send them back to /checkout with an
	//error and leave a paid order with no items and nobody told. The checkout
	//page asked before it offered that payment; a line that went bad in
	//between is fulfill()'s to report.
	require_once(PathHelper::getIncludePath('plugins/store/includes/FulfillmentRegistry.php'));
	$money_moved = ($return !== null);
	if(!$money_moved){
		$unavailable = FulfillmentRegistry::cartRefusal($cart);
		if($unavailable !== null){
			return _checkout_error($unavailable);
		}
	}

	//Ownership, pre-charge: the store will not charge an account for something
	//it already owns. This is the authoritative guard — the product page and
	//the cart refuse earlier for a better experience, but a replayed URL or a
	//guest who resolves to an existing account mid-checkout is caught here,
	//before any payment call. Checked against the user the payment-time
	//recorder would credit, so a gift to an owner is refused too.
	//
	//A refusal is a refusal, never a discount: no branch below reprices.
	foreach($cart->items as $key => $cart_item) {
		list($quantity, $product, $data, $price, $discount, $product_version) = $cart_item;
		$ownership_tag = trim((string)$product->get('pro_ownership_tag'));
		if($ownership_tag === '' || $product_version->is_subscription()){
			continue;
		}
		$ownership_candidate = $resolved_users[$key];
		if(Ownership::user_owns($ownership_candidate->key, $ownership_tag)){
			return $refuse('"' . $product->get('pro_name') . '" is already owned by '
				. $ownership_candidate->get('usr_email')
				. '. Remove it from your cart to continue.');
		}
	}

	//THE ORDER, made once every check before the charge has passed. A
	//payment's order may already exist: the Stripe webhook makes it (PAID,
	//no lines) when Stripe reports the session complete, and a return that
	//saved its order but stopped before its lines left one. The return
	//completes that order rather than making another.
	$order = $existing_order ?: new Order(NULL);
	if(StripeHelper::isTestMode()){
		$order->set('ord_test_mode', true);
	}
	$order->set('ord_usr_user_id', $billing_user->key);
	$order->set('ord_total_cost', $cart->get_total());
	if(!$order->key){
		$order->set('ord_timestamp', 'now()');
	}
	$order->set('ord_raw_cart', print_r($cart, true));
	$order->set('ord_serialized_cart', serialize($cart->get_items_generic()));
	if(!$order->key){
		$order->set('ord_status', Order::STATUS_UNPAID);
	}
	$order->prepare();
	$order->save();
	$order->load();

	$payment_service = '';
	if($charge_total > 0){
		if($return !== null){
			// Verified with the gateway before anything was written
			// (_cart_charge_verify_return()); its amount is the record's.
			foreach($return['order_fields'] as $order_column => $order_value){
				if($order_value !== null){
					$order->set($order_column, $order_value);
				}
			}
			$order->set('ord_status', Order::STATUS_PAID);
			$order->save();
			$payment_service = $return['service'];
		}
		else if($settings->get_setting('checkout_type') == 'stripe_regular'){

			//CHECK CREDIT CARD INFO AND STORE IF PRESENT FOR REGULAR STRIPE CHECKOUT
			//IF IT IS A NONZERO CART, REQUIRE CREDIT CARD INFO
			if(!isset($_REQUEST['stripeToken'])){
				$order->set('ord_status', Order::STATUS_ERROR);
				$order->set('ord_error', 'The credit card was not submitted because the browser is not using https.');
				$order->save();
				
				$log_error = "The credit card information was not submitted because your browser has javascript turned off or is not using https.  Go back to the previous page and make sure that you are accessing this page from https (look for the lock icon) and turn off any script blockers.  For help, contact us at ".$settings->get_setting('defaultemail')." .";

				return _checkout_error("Your credit card information could not be submitted. Please make sure you are using a secure connection (https) and that JavaScript is enabled.");
			}	

			$source_result = $stripe_helper->create_card_from_token($_REQUEST['stripeToken'], $stripe_customer_id, true);
			$order->set('ord_payment_method', 'stripe');
			$order->save();
			$payment_service = 'stripe_regular';
		}
		else{		
			return _checkout_error("We couldn't process your payment. Please try again or contact support.");
		}
	}
	else{
		
		$order->set('ord_status', Order::STATUS_PAID);
		$order->set('ord_payment_method', 'free');
		$order->save();
		$payment_service = 'none';
	}
	
	//REFRESH THE ORDER 
	$order->load();
		

	
	//NOW CHARGE THE CREDIT CARD FOR THE REMAINING AMOUNT
	if($cart->get_non_recurring_total()){
		if($payment_service == 'stripe_regular'){

			//THE CARD CHARGE COVERS THE ONE-TIME LINES ONLY. A subscription line
			//is billed by its own Stripe subscription (below), so it must not
			//appear in this charge's description: the buyer's statement would
			//name a hosting fee the charge did not include.
			$stripe_item_list = array();
			foreach($cart->items as $key => $cart_item) {
				list($quantity, $product, $data, $price, $discount, $product_version) = $cart_item;
				if($product_version->is_subscription()){
					continue;
				}
				$product_name = $product->get('pro_name').' '. $product_version->get('prv_version_name');

				//ASSEMBLE THE STRIPE CHARGE DESCRIPTION
				$stripe_current_item = substr($product_name, 0, 40) .' ('.$quantity.') - $'. ($price - $discount). ' ';
				array_push($stripe_item_list, $stripe_current_item);
			}

			try{
				$charge_result = $stripe_helper->executePaymentWithErrorHandling(
					function() use ($stripe_helper, $source_result, $cart, $stripe_customer_id, $stripe_item_list, $billing_user, $order) {
						return $stripe_helper->process_charge($source_result, $cart->get_non_recurring_total(), $stripe_customer_id, $stripe_item_list, $billing_user, $order);
					},
					'Credit card charge processing'
				);
			}
			catch (SystemDisplayableError $e) {
				$order->set('ord_status', Order::STATUS_ERROR);
				$order->set('ord_error', substr($e->getMessage(), 0, 250));
				$order->save();

				require_once(PathHelper::getIncludePath('includes/SignalBus.php'));
				SignalBus::dispatch('payment.failed', array(
					'order_id'      => $order->key,
					'user_id'       => $billing_user->key,
					'error_message' => substr($e->getMessage(), 0, 160),
				));

				return _checkout_error($e->getMessage());
			}
			catch (StripeHelperException $e) {
				error_log("Stripe configuration error during payment: " . $e->getMessage());
				$order->set('ord_status', Order::STATUS_ERROR);
				$order->set('ord_error', 'Stripe configuration error');
				$order->save();
				$support_email = $settings->get_setting('defaultemail');
				return _checkout_error("Your payment could not be processed. Please try again or contact support" . ($support_email ? " at $support_email" : "") . ".");
			}

			//STORE THE CHARGE ID AND MARK PAID
			//Phase 2 of cart_charge_atomicity: mark PAID immediately upon successful
			//charge to eliminate the UNPAID-with-charge-id contradiction. PayPal,
			//Stripe checkout, and free paths already mark PAID earlier in payment-
			//path branching; this completes the invariant for stripe_regular.
			$order->set('ord_stripe_charge_id', $charge_result->id);
			$order->set('ord_status', Order::STATUS_PAID);
			$order->save();
		}
	}
	
	
	
	
	
	
	// =========================================================================
	// PHASE 3 of cart_charge_atomicity: post-charge work as a two-pass restructure.
	//   Pass 1: data persistence (must succeed for the line to be "real")
	//   R1/R2/R3: send receipts referencing pass-1 data
	//   Pass 2: best-effort side effects (failure does not block receipts)
	// All wrapped in an inner try/catch. On exception: log to ord_error, notify
	// ops, render the success page anyway. Order is already PAID (Phase 2)
	// before we enter this block, so a partial failure leaves the customer with
	// a confirmed payment and the operator with a worklist via /admin/admin_orders.
	// =========================================================================
	$r1_already_sent = false;
	$confirmation_surveys = array();

	$send_r1_r2_r3 = function() use (&$line_summaries, &$applied_coupon_codes, &$billing_user, &$order, &$currency_symbol) {
		$applied_coupon_codes_list = array_keys($applied_coupon_codes);

		$build_line_for_template = function($summary, $for_billing_view) {
			$line = array(
				'product_name' => $summary['product_name'],
				'quantity' => $summary['quantity'],
				'outcome' => $summary['outcome'],
				'is_gift_to' => null,
				'event_name' => $summary['event_name'],
				'digital_link' => $summary['digital_link'],
				'event_list' => is_array($summary['event_list']) ? implode('<br>', $summary['event_list']) : null,
				'subscription_active' => ($summary['outcome'] === 'subscription'),
			);
			if ($for_billing_view) {
				$line['price'] = $summary['price'];
				if ($summary['is_gift']) {
					$line['is_gift_to'] = $summary['recipient_email'];
				} else {
					$line['act_code'] = $summary['act_code'];
					$line['event_registrant_id'] = $summary['event_registrant_id'];
				}
			} else {
				$line['act_code'] = $summary['act_code'];
				$line['event_registrant_id'] = $summary['event_registrant_id'];
			}
			return $line;
		};

		// --- R1: default order receipt to billing user ----------------------
		if (!empty($line_summaries)) {
			$billing_lines = array();
			foreach ($line_summaries as $summary) {
				$billing_lines[] = $build_line_for_template($summary, true);
			}
			$billing_fill = array(
				'recipient' => $billing_user->export_as_array(),
				'is_billing' => true,
				'order' => $order->export_as_array(),
				'order_total' => $order->get('ord_total_cost'),
				'currency_symbol' => $currency_symbol,
				'line_items' => $billing_lines,
				'coupon_codes_used' => $applied_coupon_codes_list,
			);
			try {
				EmailSender::sendTemplate('purchase_receipt_default', $billing_user->get('usr_email'), $billing_fill);
			} catch (Exception $e) {
				error_log('purchase_receipt_default send to billing user failed: ' . $e->getMessage());
			}
		}

		// --- R2: per-registrant activation for event/bundle gift recipients -
		$registrant_groups = array();
		foreach ($line_summaries as $summary) {
			if (!$summary['is_gift']) continue;
			if (!in_array($summary['outcome'], array('event', 'bundle'), true)) continue;
			$registrant_groups[$summary['recipient_email']][] = $summary;
		}
		foreach ($registrant_groups as $recipient_email => $summaries) {
			$registrant_lines = array();
			foreach ($summaries as $summary) {
				$registrant_lines[] = $build_line_for_template($summary, false);
			}
			$registrant_user = $summaries[0]['recipient_user'];
			$registrant_fill = array(
				'recipient' => $registrant_user->export_as_array(),
				'is_billing' => false,
				'order' => $order->export_as_array(),
				'currency_symbol' => $currency_symbol,
				'line_items' => $registrant_lines,
			);
			try {
				EmailSender::sendTemplate('purchase_receipt_default', $recipient_email, $registrant_fill);
			} catch (Exception $e) {
				error_log('purchase_receipt_default registrant send failed: ' . $e->getMessage());
			}
		}

		// --- R3: per-product additional email to billing user (deduped) ----
		$seen_product_ids = array();
		foreach ($line_summaries as $summary) {
			$r3_product = $summary['product'];
			if (isset($seen_product_ids[$r3_product->key])) continue;
			$has_msg = (bool)$r3_product->get('pro_after_purchase_message');
			$has_override = (bool)$r3_product->get('pro_emt_email_template_id');
			if (!$has_msg && !$has_override) continue;
			$seen_product_ids[$r3_product->key] = true;

			$tpl_name = _resolve_receipt_template($r3_product, 'purchase_receipt_product_default');
			$product_fill = array(
				'recipient' => $billing_user->export_as_array(),
				'product_name' => $summary['product_name'],
				'after_purchase_message' => (string)$r3_product->get('pro_after_purchase_message'),
				'order_item' => $summary['order_item']->export_as_array(),
				'order' => $order->export_as_array(),
			);
			try {
				EmailSender::sendTemplate($tpl_name, $billing_user->get('usr_email'), $product_fill);
			} catch (Exception $e) {
				error_log("Per-product receipt send ({$tpl_name}) failed: " . $e->getMessage());
			}
		}
	};

	try {

		// ----- PASS 1: data persistence -----
		// Must succeed for the line to be "real". If anything throws here, the
		// outer catch handler records ord_error, fires a best-effort R1 with
		// whatever has accumulated in $line_summaries, and notifies ops.
		foreach($cart->items as $key => $cart_item) {
			$email_fill = array();
			list($quantity, $product, $data, $price, $discount, $product_version) = $cart_item;
			$product_name = $product->get('pro_name').' '.$product_version->get('prv_version_name');
			$email_fill['purchase_amount'] = $price - $discount;

			//User was resolved pre-charge (Phase 1). Pulling from the parallel
			//array means user-creation validation errors have already surfaced
			//to /cart with no Stripe activity.
			$user = $resolved_users[$key];

			//Fire activation email for newly-created recipient users (deferred
			//from Phase 1's send_emails=false pre-charge resolution). Best-
			//effort: a send failure here is non-blocking — the recipient can
			//set their initial password via forgot-password.
			if(!empty($is_newly_created[$key])){
				try {
					Activation::email_activate_send($user);
				}
				catch (Exception $e) {
					error_log("Activation email send failed for user {$user->key}: " . $e->getMessage());
				}
			}

			//CREATE THE ORDER ITEM
			$order_item = new OrderItem(NULL);
			$order_item->set('odi_ord_order_id', $order->key);
			$order_item->set('odi_pro_product_id', $product->key);
			$order_item->set('odi_usr_user_id', $user->key);
			$order_item->set('odi_product_info', base64_encode(serialize($data)));
			$order_item->set('odi_price', $price - $discount);
			$order_item->set('odi_prv_product_version_id', $product_version->key);

			if($product_version->is_subscription()){
				$order_item->set('odi_is_subscription', true);
			}
			else{
				$order_item->set('odi_is_subscription', false);
			}

			//STORE COMMENT IF ENTERED (legacy — now handled via QuestionRequirement)
			if(isset($data['comment']) && !is_array($data['comment'])){
				$order_item->set('odi_comment', $data['comment']);
			}

			$order_item->set('odi_prv_product_version_id', $product_version->key);
			$order_item->set('odi_status', OrderItem::STATUS_UNPAID);
			$order_item->set('odi_status_change_time', 'now()');

			$order_item->save();
			$order_item->load();

			//SAVE THE EXTRA INFO THE USER ENTERED.
			$order_item->save_cart_data($data);

			//STORE ANY USED COUPONS, ONE ENTRY IN THE COUPON CODES USE TABLE, FK IN ORDER ITEMS
			foreach($cart->coupon_codes as $coupon_code_name){
				if($valid_coupons = $product->get_valid_coupons($product_version)){
					foreach($valid_coupons as $valid_coupon){
						if($coupon_code_name == $valid_coupon->get('ccd_code')){
							$coupon_code_use = new CouponCodeUse(NULL);
							$coupon_code_use->set('ccu_odi_order_item_id', $order_item->key);
							$coupon_code_use->set('ccu_ccd_coupon_code_id', $valid_coupon->key);
							$coupon_code_use->set('ccu_amount_discount', $valid_coupon->get('ccd_amount_discount'));
							$coupon_code_use->set('ccu_percent_discount', $valid_coupon->get('ccd_percent_discount'));
							$coupon_code_use->prepare();
							$coupon_code_use->save();
							$applied_coupon_codes[$valid_coupon->get('ccd_code')] = true;
						}
					}
				}
			}

			//HANDLE SUBSCRIPTIONS — data persistence only; notifications run in pass 2.
			$subscription_result = null;
			if($product_version->is_subscription()){
				if($payment_service == 'stripe_regular'){
					$final_price = $price - $discount;
					// A discounted line is billed at a Stripe price of its own;
					// the version's stored price stays its own amount.
					$stripe_price = $stripe_helper->get_or_create_price($product_version, $final_price);
					$subscription_result = $stripe_helper->process_stripe_regular_subscription_from_order_item($stripe_price, $order_item, $billing_user, $stripe_customer_id);
					$order_item->set('odi_subscription_status', $subscription_result['status']);
					$order_item->set('odi_status', OrderItem::STATUS_PAID);
					$order_item->save();
					// A cart of subscriptions alone has no card charge above to
					// mark its order paid: the subscription's first invoice is
					// the payment, so a live subscription marks it.
					if((int)$order->get('ord_status') === Order::STATUS_UNPAID
						&& in_array($subscription_result['status'], array('active', 'trialing'), true)){
						$order->set('ord_status', Order::STATUS_PAID);
						$order->save();
					}
				}
				else if($payment_service == 'stripe_checkout'){
					$order_item->set('odi_status', OrderItem::STATUS_PAID);
					//THE SUBSCRIPTION STRIPE MADE FOR THIS SESSION
					$order_item->set('odi_stripe_subscription_id', $return['stripe_subscription_id'] ?: $order->get('ord_stripe_subscription_id_temp'));
					$order_item->set('odi_payment_source', 'stripe');
					$order_item->save();
					$order->set('ord_stripe_subscription_id_temp', NULL);
					$order->save();
				}
				else if($payment_service == 'paypal' && !empty($return['paypal_subscription_id'])){
					$order_item->set('odi_status', OrderItem::STATUS_PAID);
					$order_item->set('odi_paypal_subscription_id', $return['paypal_subscription_id']);
					$order_item->set('odi_payment_source', 'paypal');
					$order_item->set('odi_subscription_status', 'active');
					$order_item->save();
				}
			}
			else{
				//NON-SUBSCRIPTION: paid above
				$order_item->set('odi_status', OrderItem::STATUS_PAID);
				$order_item->save();
			}

			//FULFILL THE PRODUCT — data persistence (R2 receipt depends on the
			//returned reference id) plus the provider's own best-effort side
			//effects (notifications, signals). The provider owns everything
			//kind-specific about what a purchase grants; the store just invokes it.
			$event_registrant_id = null;
			$event_name = null;
			$event_list = null;
			$fulfillment_result = null;
			if($product->get('pro_fulfillment_provider')){
				require_once(PathHelper::getIncludePath('plugins/store/includes/FulfillmentRegistry.php'));
				$fulfillment_provider = FulfillmentRegistry::get($product->get('pro_fulfillment_provider'));
				if($fulfillment_provider){
					$fulfillment_result = $fulfillment_provider->fulfill($user, $product, $order_item, $order, (int)$product->get('pro_fulfillment_ref'));
					$event_registrant_id = $fulfillment_result['ref_id'] ?? null;
					$event_name = $fulfillment_result['label'] ?? null;
					$event_list = $fulfillment_result['labels'] ?? null;
					if(!empty($fulfillment_result['confirmation_survey'])){
						$confirmation_surveys[] = $fulfillment_result['confirmation_survey'];
					}
				}
				else{
					//A product declares a fulfillment provider but its key resolves
					//to nothing (plugin deactivated, typo, not yet registered). That
					//is an error, not an ungated product: the buyer paid but nothing
					//was granted. Complete the charge, but log it and stamp ord_error
					//so it surfaces in /admin/admin_orders for manual follow-up.
					$fulfillment_error = 'Unregistered fulfillment provider "'
						. $product->get('pro_fulfillment_provider')
						. '" on product #' . $product->key . ' — purchase completed without fulfillment.';
					error_log('cart_charge_logic: ' . $fulfillment_error . ' (order #' . $order->key . ')');
					$order->set('ord_error', substr($fulfillment_error, 0, 250));
					$order->save();
				}
			}

			//Outcome category (mirrors template body conditionals). The fulfilled
			//cases are driven by the provider's result shape, not by any product
			//column the store would have to interpret.
			if ($fulfillment_result && !empty($fulfillment_result['labels'])) {
				$outcome = 'bundle';
			} else if ($fulfillment_result && !empty($fulfillment_result['ref_id'])) {
				$outcome = 'event';
			} else if ($product_version->is_subscription()) {
				$outcome = 'subscription';
			} else if ($product->get('pro_digital_link')) {
				$outcome = 'digital';
			} else {
				$outcome = 'plain';
			}

			//Canonicalize emails to decide is_gift. Empty data['email'] already
			//collapsed to billing user during pre-charge resolution.
			$billing_email_canon = strtolower(trim($billing_user->get('usr_email')));
			$line_email_canon = strtolower(trim($user->get('usr_email')));
			$is_gift = ($line_email_canon !== $billing_email_canon);

			$line_summaries[$key] = array(
				'order_item' => $order_item,
				'product' => $product,
				'product_version' => $product_version,
				'cart_data' => $data,
				'product_name' => $product_name,
				'quantity' => $quantity,
				'price' => $price - $discount,
				'recipient_email' => $line_email_canon,
				'recipient_user' => $user,
				'is_gift' => $is_gift,
				'outcome' => $outcome,
				'act_code' => $user->get('usr_act_code'),
				'event_registrant_id' => $event_registrant_id,
				'event_name' => $event_name,
				'event_list' => $event_list,                       // array of names, or null
				'digital_link' => $product->get('pro_digital_link'),
				'subscription_result' => $subscription_result,     // for pass-2 ops notification
			);

			//Legacy receipts array for $cart->last_receipt (used by older display paths).
			if($product_version->get('prv_trial_period_days')){
				$trial = ' (' . $product_version->get('prv_trial_period_days') . ' day free trial)';
			}
			else{
				$trial = '';
			}

			$receipts[$key+1]['pname'] = $product_name . $trial;
			$receipts[$key+1]['name'] = trim(($data['full_name_first'] ?? '') . ' ' . ($data['full_name_last'] ?? ''));
			$receipts[$key+1]['price'] = $price - $discount;
			$receipts[$key+1]['after_purchase_message'] = $product->get('pro_after_purchase_message');
			if($product->get('pro_digital_link')){
				$receipts[$key+1]['link'] = $product->get('pro_digital_link');
			}
		}

		// =========================================================================
		// BUILD AND SEND RECEIPT EMAILS (between passes — receipts reference
		// fully-persisted pass-1 data; pass-2 plugin failures cannot strand them).
		// Routing rules per specs/receipts_refactor.md §6.1:
		//   R1 — one default order receipt to the billing user, always
		//   R2 — per-registrant activation email for event/bundle gift recipients
		//   R3 — per-product additional email (opt-in) to the billing user, deduped
		// =========================================================================
		$send_r1_r2_r3();
		$r1_already_sent = true;

		// ----- PASS 2: best-effort side effects -----
		// Failures here do NOT block receipts (already sent above). Each block
		// is individually try/wrapped so one failing extension point does not
		// skip later blocks for the same line. The outer pass-2 try/catch (the
		// inner try/catch of the whole post-charge block) catches anything
		// truly unexpected and routes it to ord_error + ops notification.
		foreach($cart->items as $key => $cart_item) {
			list($quantity, $product, $data, $price, $discount, $product_version) = $cart_item;
			if (!isset($line_summaries[$key])) continue;
			$user = $resolved_users[$key];
			$summary = $line_summaries[$key];
			$order_item = $summary['order_item'];

			//Ownership. A tagged product makes its buyer an owner the moment
			//the item is paid for — this is core's job, not a script's. It runs
			//before the product scripts so a fulfillment script (a key mailer,
			//say) finds the row it decorates already there.
			try {
				Ownership::record_purchase($product, $product_version, $user, $order_item, $order);
			}
			catch (Exception $e) {
				error_log("Ownership recording failed for product {$product->key} (order {$order->key}): " . $e->getMessage());
			}

			//Plugin product scripts.
			try {
				$product->run_product_scripts($user, $order_item, $order);
			}
			catch (Exception $e) {
				error_log("run_product_scripts failed for product {$product->key} (order {$order->key}): " . $e->getMessage());
			}

			//Requirement post_purchase hooks.
			try {
				require_once(PathHelper::getIncludePath('plugins/store/includes/requirements/AbstractProductRequirement.php'));
				foreach (AbstractProductRequirement::getProductRequirements($product->key) as $requirement) {
					$requirement->post_purchase($data, $order_item, $user, $order);
				}
			}
			catch (Exception $e) {
				error_log("Requirement post_purchase failed for product {$product->key} (order {$order->key}): " . $e->getMessage());
			}

			//Subscription tier assignment — only if payment was successful.
			if($order_item->get('odi_status') == OrderItem::STATUS_PAID) {
				try {
					require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/includes/TierBilling.php'));
					TierBilling::handleProductPurchase($user, $product, $order_item, $order);
				}
				catch (Exception $e) {
					error_log("TierBilling::handleProductPurchase failed for product {$product->key} (order {$order->key}): " . $e->getMessage());
				}
			}

			//Refresh cached remaining-count for capacity-limited products.
			if($product->get('pro_max_purchase_count') > 0){
				try {
					$remaining = $product->get('pro_max_purchase_count') - $product->get_number_purchased();
					$product->set('pro_num_remaining_calc', $remaining);
					$product->save();
				}
				catch (Exception $e) {
					error_log("pro_num_remaining_calc refresh failed for product {$product->key}: " . $e->getMessage());
				}
			}

			//Event-registration notification + admin signal are owned by the
			//fulfillment provider (fired during fulfill() in pass 1).

			if($product_version->is_subscription()){
				//In-app notification: subscription confirmed.
				try {
					Notification::create_notification(
						$user->key,
						'subscription',
						'Your ' . $product->get('pro_name') . ' subscription is active',
						'Order #' . $order->key,
						'/profile#orders',
						null
					);
				} catch (Exception $e) { /* notification system not available */ }

				//Admin alert: new subscription.
				require_once(PathHelper::getIncludePath('includes/SignalBus.php'));
				SignalBus::dispatch('subscription.started', array(
					'order_id'       => $order->key,
					'order_item_id'  => $order_item->key,
					'user_id'        => $user->key,
					'source_user_id' => $user->key,
					'product_id'     => $product->key,
					'product_name'   => $product->get('pro_name'),
					'buyer_name'     => $billing_user->display_name(),
					'buyer_email'    => $billing_user->get('usr_email'),
				));
			}
			else{
				//In-app notification: purchase confirmed.
				try {
					Notification::create_notification(
						$user->key,
						'order',
						'Your purchase is confirmed: ' . $product->get('pro_name'),
						'Order #' . $order->key,
						'/profile#orders',
						null
					);
				} catch (Exception $e) { /* notification system not available */ }

				//Admin alert: completed sale.
				require_once(PathHelper::getIncludePath('includes/SignalBus.php'));
				SignalBus::dispatch('purchase.completed', array(
					'order_id'       => $order->key,
					'user_id'        => $user->key,
					'source_user_id' => $user->key,
					'product_id'     => $product->key,
					'product_name'   => $product->get('pro_name'),
					'buyer_name'     => $billing_user->display_name(),
					'buyer_email'    => $billing_user->get('usr_email'),
					'amount'         => (string)$order->get('ord_total_cost'),
					'currency'       => $currency_code,
				));
			}
		}

		// Record PURCHASE conversion event — canonical attribution signal for
		// revenue-by-channel. UTM is carried automatically from the session.
		require_once(PathHelper::getIncludePath('data/visitor_events_class.php'));
		$session->save_visitor_event(VisitorEvent::TYPE_PURCHASE, FALSE, 'order', $order->key);
		unset($_SESSION['checkout_started']);

		// Optional post-purchase surveys for confirmation page display are
		// collected during fulfillment (the provider returns them per line).
		$session->save_session_item('confirmation_surveys', $confirmation_surveys);

	}
	catch (Exception $post_charge_e) {
		// Phase 3 lite safety net: post-charge work threw, but order is already
		// PAID (Phase 2). Record the error, page the operator, render the
		// success page anyway so the customer doesn't see a generic error after
		// being charged.
		error_log("Post-charge work failed for order {$order->key}: " . $post_charge_e->getMessage() . " in " . $post_charge_e->getFile() . ":" . $post_charge_e->getLine());

		try {
			$order->set('ord_error', substr($post_charge_e->getMessage(), 0, 250));
			$order->save();
		}
		catch (Exception $inner_e) {
			error_log("Failed to write ord_error for order {$order->key}: " . $inner_e->getMessage());
		}

		// Best-effort R1 send if pass 1 threw before reaching the receipt block.
		if (!$r1_already_sent) {
			try {
				$send_r1_r2_r3();
			}
			catch (Exception $r1_e) {
				error_log("Best-effort R1 send failed for order {$order->key}: " . $r1_e->getMessage());
			}
		}

		// Operator notification — paged the instant it happens.
		try {
			$ops_email = $settings->get_setting('defaultemail');
			if ($ops_email) {
				$body = "Post-charge work failed for order #{$order->key}.\n\n".
					"Billing user: ".$billing_user->get('usr_email')."\n".
					"Error: ".$post_charge_e->getMessage()."\n".
					"Location: ".$post_charge_e->getFile().":".$post_charge_e->getLine()."\n\n".
					"Order is PAID; manual fix-up via /admin/admin_orders.";
				$email_inner_template = $settings->get_setting('individual_email_inner_template');
				EmailSender::sendTemplate($email_inner_template, $ops_email, array(
					'subject' => 'Order #'.$order->key.' post-charge failure',
					'body' => $body,
					'recipient' => array('display_name' => 'Operator')
				));
			}
		}
		catch (Exception $ops_e) {
			error_log("Operator notification failed for order {$order->key}: " . $ops_e->getMessage());
		}
	}

	// Always run — order is PAID (Phase 2), errors recorded on ord_error for
	// operator follow-up, customer sees the success page.
	if($return !== null){
		// The payment's record is spent. The live cart is emptied only if it
		// still holds what was paid for: lines added since are not paid for.
		ShoppingCart::forget_payment($return['ref']);
		$live_cart = ShoppingCart::current();
		$live_cart->last_receipt = $receipts;
		if($live_cart->holds_lines_of($return['record'])){
			$live_cart->clear_cart();
		}
		else{
			$live_cart->persist();
		}
	}
	else{
		$cart->last_receipt = $receipts;
		$cart->clear_cart();
	}

	return LogicResult::render($page_vars);

  } catch (Exception $e) {
	error_log("Checkout error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
	if($return !== null){
		return _cart_charge_paid_not_completed('gateway return ' . $return['lock_ref'] . ' threw before it completed');
	}
	$support_email = $settings->get_setting('defaultemail');
	return _checkout_error("Something went wrong processing your order. Please try again" . ($support_email ? " or contact us at $support_email" : "") . ".");
  }
}

?>