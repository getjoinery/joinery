<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function cart_charge_logic($get_vars, $post_vars){

	require_once(PathHelper::getIncludePath('includes/ShoppingCart.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/EmailTemplate.php'));
	require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
	require_once(PathHelper::getIncludePath('includes/StripeHelper.php'));
	require_once(PathHelper::getIncludePath('includes/PaypalHelper.php'));
	require_once(PathHelper::getIncludePath('includes/Activation.php'));
	require_once(PathHelper::getIncludePath('data/groups_class.php'));
	require_once(PathHelper::getIncludePath('data/orders_class.php'));
	require_once(PathHelper::getIncludePath('data/products_class.php'));
	require_once(PathHelper::getIncludePath('data/address_class.php'));
	require_once(PathHelper::getIncludePath('data/phone_number_class.php'));
	require_once(PathHelper::getIncludePath('data/events_class.php'));
	require_once(PathHelper::getIncludePath('data/product_details_class.php'));
	require_once(PathHelper::getIncludePath('data/event_registrants_class.php')); 
	require_once(PathHelper::getIncludePath('data/coupon_codes_class.php')); 
	require_once(PathHelper::getIncludePath('data/coupon_code_uses_class.php')); 
	
			
	$page_vars = array();
	
	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	
	
	if(!$settings->get_setting('products_active')){
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();
	}
	
	$currency_code = $settings->get_setting('site_currency');
	$page_vars['currency_code'] = $currency_code;
	$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')];
	$page_vars['currency_symbol'] = $currency_symbol;

	


	$cart = $session->get_shopping_cart();
	$page_vars['cart'] = $cart;
	$charge_total = $cart->get_total();
	


	if($charge_total){
		if($settings->get_setting('use_paypal_checkout')){
			$paypal = new PaypalHelper();
			$page_vars['paypal_helper'] = $paypal;
		}
		if($settings->get_setting('checkout_type') != 'none'){
			$stripe_helper = new StripeHelper();
		}
	}

	$receipts = array();
	
	
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
	$billing_user = User::GetByEmail($cart->billing_user['email']); 
	if(!$billing_user){
		$user_data = array(
			'usr_first_name' => $cart->billing_user['first_name'],
			'usr_last_name' => $cart->billing_user['last_name'],
			'usr_email' => $cart->billing_user['email'],		
		);
		
		if($_POST['password']){
			$user_data['password'] = $_POST['password'];
		}
		$billing_user = User::CreateCompleteNew($user_data, true, true, false);
	}
	
	if($settings->get_setting('checkout_type') == 'stripe_regular' || $settings->get_setting('checkout_type') == 'stripe_checkout'){
		$stripe_helper = new StripeHelper();
		$stripe_customer_id = $stripe_helper->get_or_create_stripe_customer($billing_user);
	}

	
	//GET THE ORDER IF IT WAS CREATED EARLIER
	if($settings->get_setting('checkout_type') == 'stripe_checkout' && !empty($_GET['session_id'])){
		
		try {
			$session_id = $stripe_helper->validate_session_id($_GET['session_id']);
			
			if(!$order = Order::GetByStripeSession($session_id)){	
				$error = 'Stripe returned bad or missing session id';
				throw new SystemDisplayablePermanentError("Something went wrong with the order.  There was no stripe session ID returned.");
				exit();				  
			}
			
		} catch (StripeHelperException $e) {
			error_log("Stripe session validation failed: " . $e->getMessage());
			throw new SystemDisplayableError("Invalid payment session");
		}
	}
	else{
		//CREATE THE ORDER 
		$order = new Order(NULL);
		if(StripeHelper::isTestMode()){
			$order->set('ord_test_mode', true);
		}
		$order->set('ord_usr_user_id', $billing_user->key);
		$order->set('ord_total_cost', $cart->get_total());
		$order->set('ord_timestamp', 'now()');	
		$order->set('ord_raw_cart', print_r($cart, true));
		$order->set('ord_serialized_cart', serialize($cart->get_items_generic()));	
		$order->set('ord_status', Order::STATUS_UNPAID);
		$order->prepare();	
		$order->save();
		$order->load();		
	}
	
	
	
	//CHECK THE COUPON CODES BEFORE WE CHARGE
	foreach($cart->coupon_codes as $coupon_code_name){
		$coupon_code_test = CouponCode::GetByColumn('ccd_code', trim($coupon_code_name));
		if(!$coupon_code_test->is_valid()){
			throw new SystemDisplayablePermanentError("Sorry, one of the coupon codes is invalid.");
			exit();				
		}
		
	}
	
	$payment_service = '';
	if($charge_total > 0){
		if(($settings->get_setting('use_paypal_checkout') && $_GET['id']) || ($settings->get_setting('use_paypal_checkout') && $_GET['subscription'])){
			$payment_id=$_GET['id'];
			$paypal=new PaypalHelper();
			$payment=$paypal->validatePayment($payment_id);
			
			if($_GET['subscription']){	
				$order->set('ord_status', Order::STATUS_PAID);	
				$order->save();
				
				$payment_service = 'paypal';				
			}
			else if($payment['status']=='COMPLETED'){	
				$order->set('ord_status', Order::STATUS_PAID);
				$order->save();
				
				$payment_service = 'paypal';
			}
			else{
				$error = 'Paypal returned bad or missing payment id';
				$order->set('ord_error', $error);
				$order->set('ord_status', Order::STATUS_ERROR);
				$order->save();
				throw new SystemDisplayablePermanentError("Something went wrong with the order.  There was no paypal transaction ID returned.");
				exit();		
			}	
		}
		else if($settings->get_setting('checkout_type') == 'stripe_checkout' && $_GET['session_id']){
			
			$order->set('ord_status', Order::STATUS_PAID);
			$order->save();
				
				
			$payment_service = 'stripe_checkout';
		}
		else if($settings->get_setting('checkout_type') == 'stripe_regular'){

			//CHECK CREDIT CARD INFO AND STORE IF PRESENT FOR REGULAR STRIPE CHECKOUT
			//IF IT IS A NONZERO CART, REQUIRE CREDIT CARD INFO
			if(!isset($_REQUEST['stripeToken'])){
				$order->set('ord_status', Order::STATUS_ERROR);
				$order->set('ord_error', 'The credit card was not submitted because the browser is not using https.');
				$order->save();
				
				$log_error = "The credit card information was not submitted because your browser has javascript turned off or is not using https.  Go back to the previous page and make sure that you are accessing this page from https (look for the lock icon) and turn off any script blockers.  For help, contact us at ".$settings->get_setting('defaultemail')." .";

				throw new SystemDisplayableError($log_error);
				exit();					
			}	

			$source_result = $stripe_helper->create_card_from_token($_REQUEST['stripeToken'], $stripe_customer_id, true);
			$payment_service = 'stripe_regular';
		}
		else{		
			throw new SystemDisplayablePermanentError("Something went wrong with the order. Unable to determine checkout type.");
			exit();				  
		}
	}
	else{
		
		$order->set('ord_status', Order::STATUS_PAID);	
		$order->save();	
		$payment_service = 'none';		
	}
	
	//REFRESH THE ORDER 
	$order->load();
		

	
	//NOW CHARGE THE CREDIT CARD FOR THE REMAINING AMOUNT
	if($cart->get_non_recurring_total()){
		if($payment_service == 'stripe_regular'){

			//PROCESS RECURRING ITEMS
			$stripe_item_list = array();
			foreach($cart->items as $key => $cart_item) {
				$email_fill = array();
				list($quantity, $product, $data, $price, $discount) = $cart_item;
				$product_version = $product->get_product_versions(TRUE, $data['product_version']);
				$product_name = $product->get('pro_name').' '. $product_version->get('prv_version_name');
				$email_fill['purchase_amount'] = $price - $discount;

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
				// User-friendly error from comprehensive error handling
				$order->set('ord_status', Order::STATUS_ERROR);
				$order->set('ord_error', substr($e->getMessage(), 0, 250));
				$order->save();	
				PublicPage::OutputGenericPublicPage("Payment Error", "Payment Error", $e->getMessage());
				exit;
			}
			catch (StripeHelperException $e) {
				// Configuration error - should not happen in production
				error_log("Stripe configuration error during payment: " . $e->getMessage());
				$order->set('ord_status', Order::STATUS_ERROR);
				$order->set('ord_error', 'Stripe configuration error');
				$order->save();	
				PublicPage::OutputGenericPublicPage("System Error", "System Error", "Payment system configuration error. Please contact support at " . $settings->get_setting('defaultemail'));
				exit;
			}

			//STORE THE CHARGE ID
			$order->set('ord_stripe_charge_id', $charge_result->id);
			$order->save();	
		}	
	}	
	
	
	
	
	
	
	foreach($cart->items as $key => $cart_item) {
		$email_fill = array();
		list($quantity, $product, $data, $price, $discount) = $cart_item;
		$product_version = $product->get_product_versions(TRUE, $data['product_version']);
		$product_name = $product->get('pro_name').' '. $product_version->get('prv_version_name');
		$email_fill['purchase_amount'] = $price - $discount;


		//GET OR CREATE THE USER, OR USE THE BILLING USER
		if($data['email']){
			$user = User::GetByEmail($data['email']);
			if(!$user){
				$user_data = array(
					'usr_first_name' => $data['full_name_first'],
					'usr_last_name' => $data['full_name_last'],
					'usr_email' => $data['email'],	
				);
				$user = User::CreateCompleteNew($user_data, true, false, false);
			}
		}
		else{
			$user = $billing_user;
		}
		$default_fill = array(
			'user_id' => $user->key,
		);

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
		
		//STORE COMMENT IF ENTERED
		if(isset($data['comment'])){
			$order_item->set('odi_comment', $data['comment']);	
		}

		$order_item->set('odi_prv_product_version_id', $product_version->key);	
		$order_item->set('odi_status', OrderItem::STATUS_UNPAID);
		$order_item->set('odi_status_change_time', 'now()');
		
		$order_item->save();	
		$order_item->load();

		//SAVE THE EXTRA INFO THE USER ENTERED.  IT'S CURRENTLY SITTING IN THE CART
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
					}
				}
			}
		}




		//HANDLE SUBSCRIPTIONS
		if($product_version->is_subscription()){

			if($payment_service == 'stripe_regular'){
				//CREATE A PRICE AND RUN THE SUBSCRIPTION
				$final_price = $price - $discount;
				
				$stripe_price = $stripe_helper->get_or_create_price($product_version, $final_price);		
				$subscription_result = $stripe_helper->process_stripe_regular_subscription_from_order_item($stripe_price, $order_item, $billing_user, $stripe_customer_id);	
				//REFRESH THE ORDER ITEM
				$order_item->set('odi_subscription_status', $subscription_result['status']);
				$order_item->set('odi_status', OrderItem::STATUS_PAID);
				$order_item->save();	
				
			}
			else if($payment_service == 'stripe_checkout'){
				$order_item->set('odi_status', OrderItem::STATUS_PAID);
				//MOVE THE SUBSCRIPTION ID FROM THE ORDER TO THE ORDER ITEM AND REMOVE IT FROM THE ORDER
				$order_item->set('odi_stripe_subscription_id', $order->get('ord_stripe_subscription_id_temp'));
				$order_item->save();
				$order->set('ord_stripe_subscription_id_temp', NULL);
				$order->save();
				
			}	
					
			
			//SEND NOTIFICATION
			if($settings->get_setting('subscription_notification_emails')){
				$notify_emails = explode(',', $settings->get_setting('subscription_notification_emails'));
				foreach($notify_emails as $notify_email){
					try {
						$notify_user = User::GetByEmail($notify_email);
						$body = 'Subscription '.$subscription_result['id'].' (Order '. $order->key .') was started by '.$billing_user->display_name().' '.$billing_user->get('usr_email').'.';
						$email_inner_template = $settings->get_setting('individual_email_inner_template');
						EmailSender::sendTemplate($email_inner_template,
							$notify_user->get('usr_email'),
							[
								'subject' => 'New Subscription',
								'body' => $body,
								'recipient' => $notify_user->export_as_array()
							]
						);
					}					
					catch (Exception $e) {
						//DO NOTHING
						$error = "";
					}
				}
			}
		}
		else{
			//IT WAS PAID ABOVE
			$order_item->set('odi_status', OrderItem::STATUS_PAID);
			$order_item->save();	

			//SEND NOTIFICATION
			if($settings->get_setting('single_purchase_notification_emails')){
				$notify_emails = explode(',', $settings->get_setting('single_purchase_notification_emails'));
				foreach($notify_emails as $notify_email){
					try {
						$notify_user = User::GetByEmail($notify_email);
						$body = 'Order '. $order->key .' was charged - user: '.$billing_user->display_name().' '.$billing_user->get('usr_email').'.';
						$email_inner_template = $settings->get_setting('individual_email_inner_template');
						EmailSender::sendTemplate($email_inner_template,
							$notify_user->get('usr_email'),
							[
								'subject' => 'Order Charged',
								'body' => $body,
								'recipient' => $notify_user->export_as_array()
							]
						);
					}					
					catch (Exception $e) {
						//DO NOTHING
						$error = "";
					}
				}
			}

		}
			
		//ATTACH USERS TO THE RIGHT EVENTS/COURSES
		if($product->get('pro_evt_event_id')){
							
			$event = new Event($product->get('pro_evt_event_id'), TRUE);
			$email_fill['event_name'] = $event->get('evt_name');	

			//ADD THE USER TO THE EVENT
			$event_registrant = $event->add_registrant($user->key, $order_item, NULL, $product->get('pro_expires'));
			$order_item->set('odi_evr_event_registrant_id', $event_registrant->key);
			$order_item->save();

			//THE RECORDING CONSENT BOX
			if(isset($data['record_terms'])){ 
				$event_registrant->set('evr_recording_consent', TRUE);
				$event_registrant->save();		
			} 				
			
			//SEND THE EMAIL
			$email_fill['more_info_required'] = false;
			if($event->get('evt_collect_extra_info')){
				$email_fill['more_info_required'] = true;	
			}
			$email_fill['event_registrant_id'] = $event_registrant->key;

			$template = 'event_reciept_content';
			
			$final_fill = array_merge($default_fill, $email_fill);
			$final_fill['recipient'] = $user->export_as_array();
			$success = EmailSender::sendTemplate($template, $user->get('usr_email'), $final_fill);
			

		}	
		else if($product->get('pro_grp_group_id')){
			
			//IT IS AN EVENT BUNDLE
			$group = new Group($product->get('pro_grp_group_id'), TRUE);
			$group_members = $group->get_member_list();
			$event_list = array();
			foreach ($group_members as $group_member){
				$event = new Event($group_member->get('grm_foreign_key_id'), TRUE);
				$event_list[] = $event->get('evt_name');
				//ADD THE USER TO THE EVENT, SUBSCRIPTIONS CANNOT BE TIME LIMITED
				$event_registrant = $event->add_registrant($user->key, $order_item, $product->get('pro_grp_group_id'), NULL);
				
				//THE RECORDING CONSENT BOX
				if(isset($data['record_terms'])){ 
					$event_registrant->set('evr_recording_consent', TRUE);	
				}

				$event_registrant->save();	
				
			}
			
			//SEND THE EMAIL
			$email_fill['event_list'] = implode('<br>', $event_list);
			$final_fill = array_merge($default_fill, $email_fill);
			$final_fill['recipient'] = $user->export_as_array();
			$success = EmailSender::sendTemplate('event_bundle_content', $user->get('usr_email'), $final_fill);					
			
		}
		else{

			/* DONATION CODE.  NOT NEEDED ANYMORE?
			$email_fill['purchase_amount'] = $price - $discount;
			$final_fill = array_merge($default_fill, $email_fill);
			$final_fill['recipient'] = $user->export_as_array();
			$success = EmailSender::sendTemplate('subscription_reciept', $user->get('usr_email'), $final_fill);
			*/
			
	
		}	
			
		//RUN THE PRODUCT SCRIPTS
		$product->run_product_scripts($user, $order_item);



		if($product_version->get('prv_trial_period_days')){
			$trial = ' (' . $product_version->get('prv_trial_period_days') . ' day free trial)';	
		}
		else{
			$trial = '';
		}
	
		$receipts[$key+1]['pname'] = $product_name . $trial;
		$receipts[$key+1]['name'] = $data['full_name_first']. ' ' .$data['full_name_last'];
		$receipts[$key+1]['price'] = $price - $discount;

		if($product->get('pro_digital_link')){			
			$receipts[$key+1]['link'] = $product->get('pro_digital_link');	
		}	
		
		//UPDATE THE CALCULATED STILL AVAILABLE FIELD
		if($product->get('pro_max_purchase_count') > 0){
			$remaining = $product->get('pro_max_purchase_count') - $product->get_number_purchased();
			$product->set('pro_num_remaining_calc', $remaining);
			$product->save();
		}			

	}		
	
	//MARK THE ORDER PAID
	$order->set('ord_status', Order::STATUS_PAID);
	$order->save();	
	
	$cart->last_receipt = $receipts;
	$cart->clear_cart();
	
	 
	return LogicResult::render($page_vars);
}

?>