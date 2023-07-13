<?php

function cart_logic($get_vars, $post_vars){
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/ShoppingCart.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/StripeHelper.php');

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

			$contains_subscription = 0;
			$stripe_item_list = array();
			foreach($cart->get_detailed_items() as $cart_item) {

				if($cart_item['recurring']){
					$final_price = $cart_item['price'] - $cart_item['discount'];
					$plan = $stripe_helper->get_or_create_subscription_plan($final_price);
					

					$product_data = array(
						'name' => $cart_item['name'],
						'description' => $cart_item['name'].' ',
					);
					
					$recurring = array(
						'interval' => 'month'
					);
					
					$price_data = array(
						'currency' => $currency_code,
						'product_data' => $product_data,
						'unit_amount' => (int)($cart_item['price'] - $cart_item['discount']) * 100,
						'recurring' => $recurring,
					);
					
					$stripe_current_item = array(
						'price_data' => $price_data,
						'quantity' => $cart_item['quantity'],
						//'metadata' => 
					);

					
					//TODO add description "metadata" => 
					if($cart_item['price'] > 0){
						array_push($stripe_item_list, $stripe_current_item);		
					}	

					$contains_subscription = 1;


				}
				else{
					//ASSEMBLE THE STRIPE PRODUCT ARRAY

					
					$product_data = array(
						'name' => $cart_item['name'],
						'description' => $cart_item['name'].' ',
					);
					
					$price_data = array(
						'currency' => $currency_code,
						'product_data' => $product_data,
						'unit_amount' => (int)($cart_item['price'] - $cart_item['discount']) * 100,
					);
					
					$stripe_current_item = array(
						'price_data' => $price_data,
						'quantity' => $cart_item['quantity'],
						//'metadata' => 
					);

					
					//TODO add description "metadata" => 
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
				
			);
			
			if($contains_subscription){
				$create_list['mode'] = 'subscription';
			}
			else{
				$create_list['mode'] = 'payment';
			}
			
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