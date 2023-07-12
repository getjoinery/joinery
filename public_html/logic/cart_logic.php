<?php

function cart_logic($get_vars, $post_vars){
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/ShoppingCart.php');

	$settings = Globalvars::get_instance();
	$composer_dir = $settings->get_setting('composerAutoLoad');	
	require_once $composer_dir.'autoload.php';
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
		PublicPageTW::OutputGenericPublicPage('Cart Empty', NULL, '<p>Your shopping cart is currently empty.</p>');
		exit();
	} 	

	$newbilling = 0;
	if($_GET['newbilling'] == 1){
		$cart->billing_user = NULL;
		$newbilling = 1;
	}	

	if (isset($_REQUEST['r']) && is_numeric($_REQUEST['r'])) {
		$cart->remove_item(intval($_REQUEST['r']));
	}
	
	if($cart->get_total() > 0){
		$stripe_helper = new StripeHelper();
		$page_vars['stripe_helper'] = $stripe_helper;
		/*
		if($_SESSION['test_mode'] || $settings->get_setting('debug')){
			$api_key = $settings->get_setting('stripe_api_key_test');
			$api_secret_key = $settings->get_setting('stripe_api_pkey_test');
		}
		else{
			$api_key = $settings->get_setting('stripe_api_key');
			$api_secret_key = $settings->get_setting('stripe_api_pkey');		
		}
		
		if(!$api_key || !$api_secret_key){
			throw new SystemDisplayablePermanentError("Stripe api keys are not present.");
			exit();			
		}
		
		$page_vars['api_key'] = $api_key;
		$page_vars['api_secret_key'] = $api_secret_key;
		
		$stripe = new \Stripe\StripeClient([
			'api_key' => $api_key,
			'stripe_version' => '2022-11-15'
		]);
		*/
	}
	
	$currency_code = $settings->get_setting('site_currency');
	$page_vars['currency_code'] = $currency_code;
	$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')];
	$page_vars['currency_symbol'] = $currency_symbol;
	
	//COUPONS
		
	if($settings->get_setting('coupons_active')){
		if($_GET['clear_coupon_code']){
			$cart->coupon_code = NULL;
			$cart->update_items_for_coupon();
		}
		else if($_GET['coupon_code']){
			//CHECK IF VALID
			$coupon_code_test = CouponCode::get_by_name($_GET['coupon_code']);
			$cart->coupon_code = $coupon_code_test->get('ccd_code');
			$cart->update_items_for_coupon();
		}
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
	
	$billing_user = $cart->get_or_create_billing_user(); 
	
	
	if($cart->get_total() > 0 && $cart->billing_user['billing_email']){			

		if($settings->get_setting('checkout_type') == 'stripe_checkout'){

			$stripe_item_list = array();
			foreach($cart->get_detailed_items() as $cart_item) {

				if($cart_item['recurring']){
					//CHECK FOR EXISTING PLAN
					try{
						$plan_name = 'subscription-' . (int)($cart_item['price'] - $cart_item['discount']);
						$plan = $stripe_helper->get_subscription_plan($plan_name);
					}
					catch (Exception $e) {
						$plan_params=array();
						$plan_params['amount'] = (int)($cart_item['price'] - $cart_item['discount']) * 100;
						$plan_params['interval'] = 'month';
						$plan_params['currency_code'] = $currency_code;
						$plan_params['currency_symbol'] = $currency_symbol;
						$plan_params['product'] = $currency_code;
						
						//CREATE NEW PLAN
						$plan = $stripe_helper->create_subscription_plan($plan_params); 							
					}	
					
					//CHECK FOR EXISTING PLAN
					/*
					try{
						$plan_name = 'recurring_donation-' . (int)($cart_item['price'] - $cart_item['discount']);
						$plan = $stripe->plans->retrieve($plan_name);
					}
					catch (Exception $e) {
						//CREATE NEW PLAN
						$plan = $stripe->plans->create([
						  "amount" => (int)($cart_item['price'] - $cart_item['discount']) * 100,
						  "interval" => "month",
						  "product" => [
							"name" => 'Recurring donation $' . (int)($cart_item['price'] - $cart_item['discount']),
						  ],
						  "currency" => $currency_code,
						]); 							
					}
					*/

					$plan_items = array(
						'plan' => $plan['id'],
					);
					
					$plan_items_wrap = array($plan_items);
					
					$stripe_subscription_item = array(
						'items' => $plan_items_wrap,
					);
					
				}
				else{
					//ASSEMBLE THE STRIPE PRODUCT ARRAY
					//'images' => ['https://example.com/t-shirt.png'],

													
					$stripe_current_item = array(
						'name' => $cart_item['name'],
						'description' => $cart_item['name'].' ',			
						'amount' => (int)($cart_item['price'] - $cart_item['discount']) * 100,
						'currency' => $currency_code,
						'quantity' => $cart_item['quantity'],
					);
					
					//TODO add description "metadata" => ["order_id" => "6735"],
					if($cart_item['price'] > 0){
						array_push($stripe_item_list, $stripe_current_item);		
					}							
				}
			}

			$create_list = array(
				'billing_address_collection' => 'auto',
				'payment_method_types' => ['card'],
				'success_url' => $settings->get_setting('webDir'). '/cart_charge?session_id={CHECKOUT_SESSION_ID}',
				'cancel_url' => $settings->get_setting('webDir'). '/cart',
				'mode' => 'payment',
			);
			
			if($stripe_item_list){
				$create_list['line_items'] = $stripe_item_list;
			}
			
			if($stripe_subscription_item){
				$create_list['subscription_data'] = $stripe_subscription_item;
				$create_list['mode'] = 'subscription';
			}			

			$existing_billing_user = User::GetByEmail($cart->billing_user['billing_email']);

			if($existing_billing_user){
				$create_list['client_reference_id'] = $existing_billing_user->key;
			
				if($existing_billing_user->get('usr_stripe_customer_id_test') && $stripe_helper->test_mode){
					$create_list['customer'] = $existing_billing_user->get('usr_stripe_customer_id_test');
				}
				else if($existing_billing_user->get('usr_stripe_customer_id') && !$stripe_helper->test_mode){
					$create_list['customer'] = $existing_billing_user->get('usr_stripe_customer_id');
				}
				else if($existing_billing_user->get('usr_email')){
					$create_list['customer_email'] = $existing_billing_user->get('usr_email');		
				}				
			}
			else{
				$create_list['customer_email'] = $cart->billing_user['billing_email'];
			}
								

			$stripe_session = $stripe_helper->create_stripe_checkout_session($create_list);
			$page_vars['stripe_session'] = $stripe_session;	
		}
	}
	
	
	
	
	
	$page_vars['cart'] = $cart;

	return $page_vars;
}

?>