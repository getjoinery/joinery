<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/ShoppingCart.php');

	//require_once($_SERVER['DOCUMENT_ROOT'].'/includes/stripe-php/init.php');
	$settings = Globalvars::get_instance();
	$composer_dir = $settings->get_setting('composerAutoLoad');	
	require_once $composer_dir.'autoload.php';
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/products_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/address_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/coupon_codes_class.php');

	$session = SessionControl::get_instance();
	$settings = Globalvars::get_instance();
	//$session->check_permission(0); 

	$cart = $session->get_shopping_cart();
	
	$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')];

	$newbilling = 0;
	if($_GET['newbilling'] == 1){
		$cart->billing_user = NULL;
		$newbilling = 1;
	}	

	if (isset($_REQUEST['r']) && is_numeric($_REQUEST['r'])) {
		$cart->remove_item(intval($_REQUEST['r']));
	}
	
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
	
	\Stripe\Stripe::setApiKey($api_key);
	
	if ($session->get_user_id()) {
		$user = new User($session->get_user_id(), TRUE);
	}
	else{
		$user = NULL;
	}	
	
	$currency_code = $settings->get_setting('site_currency');
	$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')];
	
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

	
	/*
	$create_list = array(
		'billing_address_collection' => 'auto',
		'payment_method_types' => ['card'],
		'success_url' => $settings->get_setting('webDir'). '/cart_confirm?session_id={CHECKOUT_SESSION_ID}',
		'cancel_url' => $settings->get_setting('webDir'). '/cart',
	);
	
	if($stripe_item_list){
		$create_list['line_items'] = $stripe_item_list;
	}
	
	if($stripe_subscription_item){
		$create_list['subscription_data'] = $stripe_subscription_item;
	}
	
	if(!$_SESSION['test_mode']){
		if(!$billing_user){				
			$create_list['customer_email'] = $billing_user['billing_email'];
		}
	}
	*/
?>