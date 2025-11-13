<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function cart_logic($get_vars, $post_vars){
	require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
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

	// Cart can be empty - the cart view will handle displaying appropriate message 	

	if (isset($_REQUEST['r']) && is_numeric($_REQUEST['r'])) {
		$cart->remove_item(intval($_REQUEST['r']));
		return LogicResult::redirect('/cart');
	}
	
	if (isset($_REQUEST['rc'])) {
		$cart->remove_coupon($_REQUEST['rc']);
		return LogicResult::redirect('/cart');
	}
	
	$currency_code = $settings->get_setting('site_currency');
	$page_vars['currency_code'] = $currency_code;
	$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')];
	$page_vars['currency_symbol'] = $currency_symbol;
	
	//COUPONS	
	if($settings->get_setting('coupons_active')){
		
		//FOR DEBUG
		if(StripeHelper::isTestMode()){
			$searches = array();	
			$coupon_codes = new MultiCouponCode($searches);
			$coupon_codes->load();
			$page_vars['all_coupons'] = $coupon_codes;
		}
		
		
		if($_GET['clear_coupon_code']){
			$cart->remove_coupon($_GET['clear_coupon_code']);
			return LogicResult::redirect('/cart');
		}
		else if($_GET['coupon_code']){
			//CHECK IF VALID
			$result = $cart->add_coupon($_GET['coupon_code']);
			
			if($result != 1){
				$page_vars['coupon_error'] = $result;
			}
		}
	}


	if($_GET['use_current_user'] == 1 && $session->is_logged_in()){
		// Use the logged-in user's information
		require_once(PathHelper::getIncludePath('data/users_class.php'));
		$user = new User($session->get_user_id(), TRUE);
		$cart->billing_user = array(
			'billing_first_name' => $user->get('usr_fname'),
			'billing_last_name' => $user->get('usr_lname'),
			'billing_email' => $user->get('usr_email')
		);
		return LogicResult::redirect('/cart');
	}
	else if($_GET['newbilling'] == 1){
		// Clear billing user to show the form
		$cart->billing_user = NULL;
		// Don't redirect - let the page show the billing form
	}
	else if($_POST['billing_email']){
		// Process billing form submission
		// Validate required fields for logged-out users
		if(!$session->get_user_id()){
			if(empty($_POST['privacy'])){
				throw new SystemDisplayableError('You must agree to the terms of use and privacy policy.');
			}
			if(empty($_POST['password'])){
				throw new SystemDisplayableError('Password is required.');
			}
		}
		$cart->determine_billing_user($_POST, false);
		return LogicResult::redirect('/cart');
	}
	else{
		$cart->determine_billing_user($_POST, false);
	}


	if($cart->get_total() > 0){	

		
		if($settings->get_setting('use_paypal_checkout')){
			//HANDLE SUBSCRIPTION PREP FIRST
			$paypal = new PaypalHelper();
			$page_vars['paypal_helper'] = $paypal;
			foreach($cart->items as $key => $cart_item) {
				list($quantity, $product, $data, $price, $discount, $product_version) = $cart_item;
				if($product_version->is_subscription()){
					//TODO:
					if(!$paypal_product = $paypal->searchProduct($product->get('pro_name'))){
						$paypal_product = $paypal->createProduct($product);
					}

					$paypal_product_id = $paypal_product['id'];
					$amount = $price - $discount;
					if(!$paypal_plan = $paypal->searchPlans($product->get('pro_name') . '-' . $amount)){
						$paypal_plan = $paypal->createPlan($paypal_product_id, $product_version, $amount);
					}
					$page_vars['plan_id'] = $paypal_plan['id'];
					//$result = $paypal->createSubscription($page_vars['plan_id']);

				}
			}
			
			//NOW ITEMS
			$paypal_item_list = $paypal->build_item_array($cart->get_detailed_items());	
			$page_vars['paypal_item_list'] = $paypal_item_list;	
		
		}

		if($settings->get_setting('checkout_type') == 'stripe_checkout'){
			$stripe_helper = new StripeHelper();
			$page_vars['stripe_helper'] = $stripe_helper;
			if($session->get_user_id()){
				$existing_billing_user = User::GetByEmail($session->get_user_id());
				$create_list = $stripe_helper->build_checkout_item_array($cart, $existing_billing_user);
			}
			else{
				$create_list = $stripe_helper->build_checkout_item_array($cart, NULL);
			}
											
			$stripe_session = $stripe_helper->create_stripe_checkout_session($create_list);
		}
		else if($settings->get_setting('checkout_type') == 'stripe_regular'){
			$stripe_helper = new StripeHelper();
			$page_vars['stripe_helper'] = $stripe_helper;
		}
	}


	$require_login = 0;
	if(!$session->get_user_id()){
		//IF NOT LOGGED IN, CHECK TO SEE IF EMAIL EXISTS AND IF SO ASK TO LOG IN
		$user = User::GetByEmail($cart->billing_user['billing_email']);
		if($user){
			$require_login = 1;
		}
	}
	$page_vars['require_login'] = $require_login;


	$page_vars['cart'] = $cart;
	

	return LogicResult::render($page_vars);
}

?>