<?php

function cart_logic($get_vars, $post_vars){
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/ShoppingCart.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/StripeHelper.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/PaypalHelper.php');

	require_once($_SERVER['DOCUMENT_ROOT'].'/data/products_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/address_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/coupon_codes_class.php');
	
	$page_vars = array();

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;

	$cart = $session->get_shopping_cart();
	
	if (count($cart->get_items()) === 0) {
		// Cart is empty, can't checkout!
		PublicPage::OutputGenericPublicPage('Cart Empty', NULL, '<p>Your shopping cart is currently empty.</p>');
		exit();
	} 	

	if (isset($_REQUEST['r']) && is_numeric($_REQUEST['r'])) {
		$cart->remove_item(intval($_REQUEST['r']));
	}
	
	
	$currency_code = $settings->get_setting('site_currency');
	$page_vars['currency_code'] = $currency_code;
	$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')];
	$page_vars['currency_symbol'] = $currency_symbol;
	
	//COUPONS	
	if($settings->get_setting('coupons_active')){
		
		//FOR DEBUG
		if($_SESSION['test_mode'] || $settings->get_setting('debug')){
			$searches = array();	
			$coupon_codes = new MultiCouponCode($searches);
			$coupon_codes->load();
			$page_vars['all_coupons'] = $coupon_codes;
		}
		
		
		if($_GET['clear_coupon_code']){
			unset($cart->coupon_codes[array_search(trim($_GET['clear_coupon_code']), $cart->coupon_codes)]);
			$cart->update_items_for_coupon();
			LibraryFunctions::Redirect('/cart');
		}
		else if($_GET['coupon_code']){
			//CHECK IF VALID

			$coupon_code_test = CouponCode::GetByColumn('ccd_code', trim($_GET['coupon_code']));

			if($coupon_code_test){
				if($coupon_code_test->is_valid()){
					$cart->coupon_codes[] = $coupon_code_test->get('ccd_code');
					$cart->coupon_codes = array_unique($cart->coupon_codes);
					$cart->update_items_for_coupon();
				}
				else{
					$page_vars['coupon_error'] = 'Coupon code not valid.';
				}
			}
			else{
				$page_vars['coupon_error'] = 'Coupon code not found.';
			}
		}
	}

	$newbilling = 0;
	if($_GET['newbilling'] == 1){
		$cart->billing_user = NULL;
		$newbilling = 1;
	}	

	if($_POST['existing_billing_email']){
		$billing_user = array();
		if($_POST['existing_billing_email'] == 'A different person'){
			$billing_user['billing_first_name'] = $_POST['billing_first_name'];
			$billing_user['billing_last_name'] = $_POST['billing_last_name'];
			$billing_user['billing_email'] = strtolower(trim($_POST['billing_email']));
			$cart->billing_user = $billing_user;
			
		}
		else{
			foreach($cart->items as $key => $cart_item) {
				list($quantity, $product, $data, $price, $discount) = $cart_item;
				if(strtolower(trim($_POST['existing_billing_email'])) == strtolower(trim($data['email']))){								
					$billing_user['billing_first_name'] = $data['full_name_first'];
					$billing_user['billing_last_name'] = $data['full_name_last'];
					$billing_user['billing_email'] = strtolower(trim($data['email']));
					$cart->billing_user = $billing_user;
				}				
			}
			
		}
		
	}				
	else if($cart->count_items() > 0 && !$cart->billing_user && !$newbilling){
		//IF AT LEAST ONE ITEM IN CART, LOAD FIRST AS BILLING USER
		foreach($cart->items as $key => $cart_item) {}  //SHORTCUT TO GET ONLY ONE
		list($quantity, $product, $data, $price, $discount) = $cart_item;
		
		$billing_user['billing_first_name'] = $data['full_name_first'];
		$billing_user['billing_last_name'] = $data['full_name_last'];
		$billing_user['billing_email'] = strtolower(trim($data['email']));
		$cart->billing_user = $billing_user;
	}	
	
	
	
	

	if($cart->get_total() > 0 && $cart->billing_user['billing_email']){			
		$billing_user = $cart->get_or_create_billing_user(); 
		//ADD TO THE MAILING LIST IF CHOSEN
		if(isset($data['newsletter']) && $data['newsletter']){
			if($settings->get_setting('default_mailing_list')){
				$messages = $billing_user->add_user_to_mailing_lists($settings->get_setting('default_mailing_list'));
				//$status = $billing_user->subscribe_to_contact_type($settings->get_setting('default_mailing_list'));	
			}
		}	
		if($settings->get_setting('use_paypal_checkout')){
			//HANDLE SUBSCRIPTION PREP FIRST
			$paypal = new PaypalHelper();
			$page_vars['paypal_helper'] = $paypal;
			foreach($cart->items as $key => $cart_item) {
				list($quantity, $product, $data, $price, $discount) = $cart_item;
				$product_version = $product->get_product_versions(TRUE, $data['product_version']);
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
			$existing_billing_user = User::GetByEmail($cart->billing_user['billing_email']);
			$create_list = $stripe_helper->build_checkout_item_array($cart, $existing_billing_user);								
			$stripe_session = $stripe_helper->create_stripe_checkout_session($create_list);
		}
		else if($settings->get_setting('checkout_type') == 'stripe_regular'){
			$stripe_helper = new StripeHelper();
			$page_vars['stripe_helper'] = $stripe_helper;
		}
	}






	$page_vars['cart'] = $cart;
	

	return $page_vars;
}

?>