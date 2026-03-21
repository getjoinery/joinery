<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function cart_logic($get_vars, $post_vars){
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/ShoppingCart.php'));
	require_once(PathHelper::getIncludePath('includes/StripeHelper.php'));
	require_once(PathHelper::getIncludePath('includes/PaypalHelper.php'));

	require_once(PathHelper::getIncludePath('data/products_class.php'));
	require_once(PathHelper::getIncludePath('data/address_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/coupon_codes_class.php'));

	$page_vars = array();

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;

	$cart = $session->get_shopping_cart();

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
	$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')];
	$page_vars['currency_symbol'] = $currency_symbol;

	// Coupon handling (GET-based for non-AJAX fallback)
	if ($settings->get_setting('coupons_active')) {
		if (StripeHelper::isTestMode()) {
			$coupon_codes = new MultiCouponCode(array());
			$coupon_codes->load();
			$page_vars['all_coupons'] = $coupon_codes;
		}

		if (isset($_GET['clear_coupon_code'])) {
			$cart->remove_coupon($_GET['clear_coupon_code']);
			return LogicResult::redirect('/cart');
		}
		else if (isset($_GET['coupon_code']) && $_GET['coupon_code']) {
			$result = $cart->add_coupon($_GET['coupon_code']);
			if ($result != 1) {
				$page_vars['coupon_error'] = $result;
			}
		}
	}

	// Billing user handling
	if (isset($_GET['use_current_user']) && $_GET['use_current_user'] == 1 && $session->is_logged_in()) {
		$user = new User($session->get_user_id(), TRUE);
		$cart->billing_user = array(
			'billing_first_name' => $user->get('usr_fname'),
			'billing_last_name' => $user->get('usr_lname'),
			'billing_email' => $user->get('usr_email')
		);
		return LogicResult::redirect('/cart');
	}
	else if (isset($_GET['newbilling']) && $_GET['newbilling'] == 1) {
		$cart->billing_user = NULL;
	}
	else if (isset($_POST['billing_email']) && $_POST['billing_email']) {
		if (!$session->get_user_id()) {
			if (empty($_POST['privacy'])) {
				return LogicResult::error('You must agree to the terms of use and privacy policy.');
			}
			if (empty($_POST['password'])) {
				return LogicResult::error('Password is required.');
			}
		}
		$cart->determine_billing_user($_POST, false);
		// For free orders, go directly to charge processing
		if ($cart->get_total() <= 0 && !empty($_POST['complete_order'])) {
			return LogicResult::redirect('/cart_charge');
		}
		return LogicResult::redirect('/cart');
	}
	else {
		$cart->determine_billing_user($_POST, false);
	}

	// Pre-fill contact email from cart items if not already set
	if (empty($cart->billing_user['billing_email'])) {
		$cart->billing_user_prefill_from_items();
	}

	// Build sections array for the accordion
	$sections = array();

	// Section 1: Contact - always shown
	$contact_state = 'active';
	if (!empty($cart->billing_user['billing_email'])) {
		$contact_state = 'completed';
	}
	$sections['contact'] = array(
		'title' => 'Contact Information',
		'state' => $contact_state,
		'number' => 1,
		'summary' => !empty($cart->billing_user['billing_email']) ? htmlspecialchars($cart->billing_user['billing_email'], ENT_QUOTES, 'UTF-8') : '',
	);

	// Section 2: Coupon - shown if coupons active
	if ($settings->get_setting('coupons_active')) {
		$coupon_state = ($contact_state == 'completed') ? 'active' : 'pending';
		// Coupon is completed if codes were applied OR if billing is already complete (user passed through coupon)
		if (!empty($cart->coupon_codes) || $cart->is_billing_user_complete()) {
			$coupon_state = 'completed';
		}
		$coupon_summary = '';
		if (!empty($cart->coupon_codes)) {
			$coupon_summary = htmlspecialchars(implode(', ', $cart->coupon_codes), ENT_QUOTES, 'UTF-8') . ' applied';
		} else if ($cart->is_billing_user_complete()) {
			$coupon_summary = 'No coupon';
		}
		$sections['coupon'] = array(
			'title' => 'Coupon Code',
			'state' => $coupon_state,
			'number' => count($sections) + 1,
			'summary' => $coupon_summary,
		);
	}

	// Section 3: Billing - always shown
	$billing_state = 'pending';
	if ($contact_state == 'completed' && (!isset($sections['coupon']) || $sections['coupon']['state'] != 'active')) {
		$billing_state = 'active';
	}
	if ($cart->is_billing_user_complete()) {
		$billing_state = 'completed';
	}

	// Get pre-fill name from cart items
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

	$billing_summary = '';
	if (!empty($cart->billing_user['billing_first_name'])) {
		$billing_summary = htmlspecialchars($cart->billing_user['billing_first_name'] . ' ' . $cart->billing_user['billing_last_name'], ENT_QUOTES, 'UTF-8');
		$billing_summary .= ' (' . htmlspecialchars($cart->billing_user['billing_email'], ENT_QUOTES, 'UTF-8') . ')';
	}

	$sections['billing'] = array(
		'title' => 'Billing & Account',
		'state' => $billing_state,
		'number' => count($sections) + 1,
		'summary' => $billing_summary,
	);

	// Section 4: Payment - shown if total > 0
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

	// Payment setup (only when billing is complete and total > 0)
	if ($cart->get_total() > 0 && $cart->billing_user['billing_email']) {

		if ($settings->get_setting('use_paypal_checkout')) {
			$paypal = new PaypalHelper();
			$page_vars['paypal_helper'] = $paypal;
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
				}
			}
			$paypal_item_list = $paypal->build_item_array($cart->get_detailed_items());
			$page_vars['paypal_item_list'] = $paypal_item_list;
		}

		if ($settings->get_setting('checkout_type') == 'stripe_checkout') {
			$stripe_helper = new StripeHelper();
			$page_vars['stripe_helper'] = $stripe_helper;
			if ($session->get_user_id()) {
				$existing_billing_user = User::GetByEmail($session->get_user_id());
				$create_list = $stripe_helper->build_checkout_item_array($cart, $existing_billing_user);
			} else {
				$create_list = $stripe_helper->build_checkout_item_array($cart, NULL);
			}
			$stripe_session = $stripe_helper->create_stripe_checkout_session($create_list);
		}
		else if ($settings->get_setting('checkout_type') == 'stripe_regular') {
			$stripe_helper = new StripeHelper();
			$page_vars['stripe_helper'] = $stripe_helper;
		}
	}

	// Check if existing email requires login
	$require_login = 0;
	if (!$session->get_user_id() && !empty($cart->billing_user['billing_email'])) {
		$user = User::GetByEmail($cart->billing_user['billing_email']);
		if ($user) {
			$require_login = 1;
		}
	}
	$page_vars['require_login'] = $require_login;

	$page_vars['cart'] = $cart;

	return LogicResult::render($page_vars);
}

/**
 * Validate a single checkout section. Used by checkout_ajax.php.
 */
function validate_checkout_section($section, $data) {
	$errors = array();

	switch ($section) {
		case 'contact':
			if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
				$errors['email'] = 'Please enter a valid email address.';
			}
			break;

		case 'billing':
			if (empty($data['billing_first_name'])) {
				$errors['billing_first_name'] = 'First name is required.';
			}
			if (empty($data['billing_last_name'])) {
				$errors['billing_last_name'] = 'Last name is required.';
			}
			if (empty($data['privacy'])) {
				$errors['privacy'] = 'You must agree to the terms of use and privacy policy.';
			}
			$session = SessionControl::get_instance();
			if (!$session->get_user_id() && empty($data['password'])) {
				$errors['password'] = 'Password is required to create your account.';
			}
			break;
	}

	return $errors;
}

function cart_logic_api() {
    return [
        'requires_session' => true,
        'description' => 'Add item to cart',
    ];
}
?>