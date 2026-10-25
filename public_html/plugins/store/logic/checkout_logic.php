<?php

function checkout_logic(array $input): LogicResult{
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('plugins/store/includes/ShoppingCart.php'));
	require_once(PathHelper::getIncludePath('plugins/store/includes/StripeHelper.php'));
	require_once(PathHelper::getIncludePath('plugins/store/includes/PaypalHelper.php'));

	require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
	require_once(PathHelper::getIncludePath('data/users_addrs_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/coupon_codes_class.php'));

	$page_vars = array();

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;

	// Set return URL so login redirects back to checkout
	if (!$session->get_user_id()) {
		$session->set_return('/checkout');
	}

	$cart = ShoppingCart::current();

	// Handle item removal
	if (isset($_REQUEST['r']) && is_numeric($_REQUEST['r'])) {
		$cart->remove_item(intval($_REQUEST['r']));
		return LogicResult::redirect('/checkout');
	}

	// Handle coupon removal via query param
	if (isset($_REQUEST['rc'])) {
		$cart->remove_coupon($_REQUEST['rc']);
		return LogicResult::redirect('/checkout');
	}

	$currency_code = $settings->get_setting('site_currency');
	$page_vars['currency_code'] = $currency_code;
	$currency_symbol = CurrencyHelper::symbol($settings->get_setting('site_currency'));
	$page_vars['currency_symbol'] = $currency_symbol;

	// Coupon handling (GET-based for non-AJAX fallback)
	if ($settings->get_setting('coupons_active')) {
		if (StripeHelper::isTestMode() && Access::permits(CouponCode::access_level('read'), $session)) {
			$coupon_codes = new MultiCouponCode(array());
			$coupon_codes->load();
			$page_vars['all_coupons'] = $coupon_codes;
		}

		if (isset($_GET['clear_coupon_code'])) {
			$cart->remove_coupon($_GET['clear_coupon_code']);
			return LogicResult::redirect('/checkout');
		}
		else if (isset($_GET['coupon_code']) && $_GET['coupon_code']) {
			$result = $cart->add_coupon($_GET['coupon_code']);
			if ($result != 1) {
				$page_vars['coupon_error'] = $result;
			}
		}
	}

	// A signed-in buyer with no email adds one to their account first: the receipt
	// and what they buy are sent there, and the purchase is theirs, not a new
	// account made from an address typed here.
	if ($session->is_logged_in() && !(new User($session->get_user_id(), TRUE))->get('usr_email')) {
		return User::add_email_first(User::CHECKOUT_NEEDS_EMAIL);
	}

	// Billing user handling
	if (isset($_GET['use_current_user']) && $_GET['use_current_user'] == 1 && $session->is_logged_in()) {
		$user = new User($session->get_user_id(), TRUE);
		$cart->billing_user = array(
			'billing_first_name' => $user->get('usr_first_name'),
			'billing_last_name' => $user->get('usr_last_name'),
			'billing_email' => $user->get('usr_email')
		);
		$cart->persist();
		return LogicResult::redirect('/checkout');
	}
	else if (isset($_GET['newbilling']) && $_GET['newbilling'] == 1) {
		$cart->billing_user = NULL;
		$cart->persist();
	}
	else if (isset($_POST['billing_email']) && $_POST['billing_email']) {
		// Implicit consent via the action-button copy replaces the explicit
		// privacy checkbox. Password is auto-generated downstream by
		// User::CreateNew when one is not supplied.
		// A free order's billing form posts straight to /cart_charge, which
		// completes it; this is the billing step alone.
		$cart->determine_billing_user($_POST, false);
		return LogicResult::redirect('/checkout');
	}
	else {
		$cart->determine_billing_user($_POST, false);
	}

	// For logged-in users, auto-populate billing from their account if not already set.
	// This auto-completes the contact section so they don't have to click through it.
	if ($session->is_logged_in() && empty($cart->billing_user['billing_email'])) {
		$logged_in_user = new User($session->get_user_id(), TRUE);
		$cart->billing_user = array(
			'billing_first_name' => $logged_in_user->get('usr_first_name'),
			'billing_last_name'  => $logged_in_user->get('usr_last_name'),
			'billing_email'      => $logged_in_user->get('usr_email'),
		);
		$cart->persist();
	}
	// Pre-fill contact email from cart items if not already set (guest checkout)
	else if (empty($cart->billing_user['billing_email'])) {
		$cart->billing_user_prefill_from_items();
	}

	// Build sections array for the accordion
	$sections = array();

	// Get pre-fill name from cart items (for guest checkout pre-population)
	$prefill_name = array('first' => '', 'last' => '');
	foreach ($cart->items as $cart_item) {
		list($quantity, $product, $data, $price, $discount, $product_version) = $cart_item;
		if (!empty($data['full_name_first'])) {
			$prefill_name['first'] = $data['full_name_first'];
			$prefill_name['last'] = $data['full_name_last'];
			break;
		}
	}
	$page_vars['prefill_name'] = $prefill_name;
	$has_name_from_cart = !empty($prefill_name['first']);
	$page_vars['has_name_from_cart'] = $has_name_from_cart;

	// Section 1: Billing User - always shown
	$billing_state = $cart->is_billing_user_complete() ? 'completed' : 'active';
	$billing_summary = '';
	if (!empty($cart->billing_user['billing_first_name'])) {
		$billing_summary = htmlspecialchars($cart->billing_user['billing_first_name'] . ' ' . $cart->billing_user['billing_last_name'], ENT_QUOTES, 'UTF-8');
		$billing_summary .= ' (' . htmlspecialchars($cart->billing_user['billing_email'], ENT_QUOTES, 'UTF-8') . ')';
	} else if (!empty($cart->billing_user['billing_email'])) {
		$billing_summary = htmlspecialchars($cart->billing_user['billing_email'], ENT_QUOTES, 'UTF-8');
	}
	$sections['billing'] = array(
		'title'   => 'Billing User',
		'state'   => $billing_state,
		'number'  => count($sections) + 1,
		'summary' => $billing_summary,
	);

	// Section 2: Payment - shown if total > 0
	if ($cart->get_total() > 0) {
		$payment_state = ($billing_state == 'completed') ? 'active' : 'pending';
		$sections['payment'] = array(
			'title' => 'Payment',
			'state' => $payment_state,
			'number' => count($sections) + 1,
			'summary' => '',
		);
	}

	// Determine the first active section (for progressive disclosure)
	$found_active = false;
	foreach ($sections as $key => &$section) {
		if ($section['state'] == 'completed') {
			continue;
		}
		if (!$found_active) {
			$section['state'] = 'active';
			$found_active = true;
		} else {
			$section['state'] = 'pending';
		}
	}
	unset($section);

	// Free orders with all sections completed: re-open billing so user can click "Complete Order"
	if (!$found_active && $cart->get_total() <= 0 && !isset($sections['payment'])) {
		$sections['billing']['state'] = 'active';
	}

	$page_vars['sections'] = $sections;

	// stripe_regular only needs the helper object (no API call); initialize whenever total > 0
	// so the payment section can always render its form (even before billing is filled in).
	if ($cart->get_total() > 0 && $settings->get_setting('checkout_type') == 'stripe_regular') {
		$stripe_helper = new StripeHelper();
		$page_vars['stripe_helper'] = $stripe_helper;
	}

	// Before any payment step exists, every line's fulfilment provider gets to
	// refuse — an event with no seats left, a hosted site whose setup changed
	// after it was added. A hosted Stripe Checkout session charges the card
	// before the buyer is back here, so the question has to be asked before
	// the session is created; a refusal replaces the payment forms with the
	// sentence, and no session, plan or order is made.
	require_once(PathHelper::getIncludePath('plugins/store/includes/FulfillmentRegistry.php'));
	$page_vars['payment_refusal'] = ($cart->get_total() > 0) ? FulfillmentRegistry::cartRefusal($cart) : null;

	// Paying as an account that already exists needs that account's sign-in:
	// the order, its charge and what it grants are written to that account. A
	// guest's checkout makes a new one. No payment is offered until then.
	$require_login = 0;
	if (!empty($cart->billing_user['billing_email'])) {
		$billing_account = User::GetByEmail($cart->billing_user['billing_email']);
		if ($billing_account && (int)$billing_account->key !== (int)$session->get_user_id()) {
			$require_login = 1;
		}
	}

	// Payment setup requiring billing email (API calls, PayPal, stripe_checkout session).
	// Each payment offered here is recorded with the cart it is for
	// (ShoppingCart::record_payment()); the gateway's return completes that
	// record, whatever the cart holds by then.
	if ($cart->get_total() > 0 && !empty($cart->billing_user['billing_email']) && !$require_login && $page_vars['payment_refusal'] === null) {

		if ($settings->get_setting('use_paypal_checkout')) {
			$paypal = new PaypalHelper();
			$page_vars['paypal_helper'] = $paypal;
			$paypal_plan_id = null;
			foreach ($cart->items as $key => $cart_item) {
				list($quantity, $product, $data, $price, $discount, $product_version) = $cart_item;
				if ($product_version->is_subscription()) {
					if (!$paypal_product = $paypal->searchProduct($product->get('pro_name'))) {
						$paypal_product = $paypal->createProduct($product);
					}
					$paypal_product_id = $paypal_product['id'];
					$amount = $price - $discount;
					if (!$paypal_plan = $paypal->searchPlans($product->get('pro_name') . '-' . $amount)) {
						$paypal_plan = $paypal->createPlan($paypal_product_id, $product_version, $amount);
					}
					$page_vars['plan_id'] = $paypal_plan['id'];
					$paypal_plan_id = $paypal_plan['id'];
				}
			}
			$paypal_ref = 'pp_' . bin2hex(random_bytes(16));
			$page_vars['paypal_ref'] = $paypal_ref;
			$paypal_item_list = $paypal->build_item_array($cart->get_detailed_items());
			if ($paypal_item_list) {
				$paypal_item_list['purchase_units'][0]['custom_id'] = $paypal_ref;
			}
			$page_vars['paypal_item_list'] = $paypal_item_list;
			$cart->record_payment($paypal_ref, 'paypal', array('plan_id' => $paypal_plan_id));
		}

		if ($settings->get_setting('checkout_type') == 'stripe_checkout') {
			$stripe_helper = new StripeHelper();
			$page_vars['stripe_helper'] = $stripe_helper;
			if ($session->get_user_id()) {
				$existing_billing_user = new User($session->get_user_id(), TRUE);
				$create_list = $stripe_helper->build_checkout_item_array($cart, $existing_billing_user);
			} else {
				$create_list = $stripe_helper->build_checkout_item_array($cart, NULL);
			}
			$stripe_session = $stripe_helper->create_stripe_checkout_session($create_list);
			$cart->record_payment($stripe_session->id, 'stripe_checkout');
		}
	}

	$page_vars['require_login'] = $require_login;

	$page_vars['cart'] = $cart;

	return LogicResult::render($page_vars);
}

function checkout_logic_descriptor() {
    return [
        'auth' => ['min_user_permission' => 0],
        'requires_session' => true,
        'description' => 'Add item to cart',
        'input' => [
            'coupon_code' => ['type' => 'string', 'required' => false, 'label' => 'Coupon code to apply'],
            'clear_coupon_code' => ['type' => 'string', 'required' => false, 'label' => 'Coupon code to remove'],
            'use_current_user' => ['type' => 'int', 'required' => false, 'label' => '1 to bill the signed-in user'],
            'newbilling' => ['type' => 'int', 'required' => false, 'label' => '1 to enter new billing details'],
            'billing_email' => ['type' => 'string', 'required' => false, 'label' => 'Billing email'],
        ],
    ];
}
?>