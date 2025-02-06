<?php
function cart_charge_logic($get_vars, $post_vars){

	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ShoppingCart.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/EmailTemplate.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/StripeHelper.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/PaypalHelper.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/groups_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/orders_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/products_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/address_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/phone_number_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/product_details_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_registrants_class.php'); 
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/coupon_codes_class.php'); 
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/coupon_code_uses_class.php'); 
	
			
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
		LibraryFunctions::Redirect('/cart_confirm'); 
		exit();		
	}
	
	//DEBUG
	/*
	foreach($cart->get_items() as $key => $cart_item) {
		print_r($cart_item);
	}
	*/

	//HANDLE THE BILLING USER
	$billing_user = $cart->get_or_create_billing_user(); 
	
	//GET THE ORDER IF IT WAS CREATED EARLIER
	if($settings->get_setting('checkout_type') == 'stripe_checkout' && $_GET['session_id']){
		if(!$order = Order::GetByStripeSession($session_id)){	
			$error = 'Stripe returned bad or missing session id';
			throw new SystemDisplayablePermanentError("Something went wrong with the order.  There was no stripe session ID returned.");
			exit();				  
		}
	}
	else{
		//CREATE THE ORDER 
		$order = new Order(NULL);
		if($_SESSION['test_mode'] || $settings->get_setting('debug')){
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
	
	
	//CREATE ALL OF THE NEEDED USERS 
	foreach($cart->items as $key => $cart_item) {
		list($quantity, $product, $data, $price, $discount) = $cart_item;

		//DEAL WITH CREATING USERS FOR EACH PRODUCT ITEM
		$user = User::GetByEmail($data['email']);
		if(!$user){
			$data = array(
				'usr_first_name' => $data['full_name_first'],
				'usr_last_name' => $data['full_name_last'],
				'usr_email' => $data['email'],
				'password' => NULL,
				'send_emails' => true
			);
			$user = User::CreateNew($data);
		}
		
		$act_code = Activation::getTempCode($user->key, '30 days', Activation::EMAIL_VERIFY, NULL, $user->get('usr_email'));	
		$default_fill = array(
			'act_code' => $act_code,
			'user_id' => $user->key,
		);
		
		//ADD TO THE MAILING LIST IF CHOSEN
		if(isset($data['newsletter']) && $data['newsletter']){
			if($settings->get_setting('default_mailing_list')){
				$messages = $user->add_user_to_mailing_lists($settings->get_setting('default_mailing_list'));
				//$status = $user->subscribe_to_contact_type($settings->get_setting('default_mailing_list'));		
			}
		}		

		//IF THE USER ENTERED A PHONE NUMBER, SAVE THAT
		if(!$user->phone() && $data['phn_phone_number']){
			$phone_number = PhoneNumber::CreateFromForm($data, $user->key, NULL, FALSE);
		}
		
		//IF THE USER ENTERED AN ADDRESS, SAVE THAT
		if(!$user->address() && $data['address']){
			$address = $data['address'];
			if(!$address->get('usa_usr_user_id')){
				$address->set('usa_usr_user_id', $user->key);
				$address->save();
			}
		}
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
			$stripe_customer_id = $stripe_helper->get_stripe_customer_id($billing_user);
			
			$order->set('ord_status', Order::STATUS_PAID);
			$order->save();
				
				
			$payment_service = 'stripe_checkout';
		}
		else if($settings->get_setting('checkout_type') == 'stripe_regular'){
			$stripe_customer_id = $stripe_helper->get_stripe_customer_id($billing_user);

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
	
	//PROCESS RECURRING ITEMS
	$stripe_item_list = array();
	foreach($cart->items as $key => $cart_item) {
		$email_fill = array();
		list($quantity, $product, $data, $price, $discount) = $cart_item;
		$product_version = $product->get_product_version($data);
		//$price = $product->get_price($product_version, $data);
		$product_name = $product->get('pro_name').' '. $product_version->prv_version_name;
		$email_fill['purchase_amount'] = $price - $discount;

		//HANDLE SUBSCRIPTIONS
		if($product->get('pro_recurring')){

			//CREATE THE ORDER ITEM
			$order_item = new OrderItem(NULL);
			$order_item->set('odi_ord_order_id', $order->key);
			$order_item->set('odi_pro_product_id', $product->key);
			$order_item->set('odi_usr_user_id', $user->key);
			$order_item->set('odi_product_info', base64_encode(serialize($data)));
			$order_item->set('odi_price', $price - $discount);	
			
			//STORE COMMENT IF ENTERED
			if(isset($data['comment'])){
				$order_item->set('odi_comment', $data['comment']);	
			}
			if ($product_version) {
				$order_item->set('odi_prv_product_version_id', $product_version->prv_product_version_id);
			}			
			$order_item->set('odi_status', OrderItem::STATUS_UNPAID);
			$order_item->set('odi_status_change_time', 'now()');
			$order_item->set('odi_is_subscription', true);
			$order_item->save();	
			$order_item->load();
			
			//STORE ANY USED COUPONS, ONE ENTRY IN THE COUPON CODES USE TABLE, FK IN ORDER ITEMS
			foreach($cart->coupon_codes as $coupon_code_name){
				if($valid_coupons = $product->get_valid_coupons()){
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
			
			//SAVE THE EXTRA INFO THE USER ENTERED.  IT'S CURRENTLY SITTING IN THE CART
			$order_item->save_cart_data($data);


			if($payment_service == 'stripe_regular'){
				//CREATE A PLAN AND RUN THE SUBSCRIPTION
				$final_price = $price - $discount;
				
				$stripe_price = $stripe_helper->get_or_create_price($product, $final_price);		
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
			
			$order_item->load();
			

			
			
			//SEND NOTIFICATION
			if($settings->get_setting('subscription_notification_emails')){
				$notify_emails = explode(',', $settings->get_setting('subscription_notification_emails'));
				foreach($notify_emails as $notify_email){
					try {
						$notify_user = User::GetByEmail($notify_email);
						$body = 'Subscription '.$subscription_result['id'].' (Order '. $order->key .') was started by '.$billing_user->display_name().' '.$billing_user->get('usr_email').'.';
						$email_inner_template = $settings->get_setting('individual_email_inner_template');
						$email = new EmailTemplate($email_inner_template, $notify_user);
						$email->fill_template(array(
							'subject' => 'New Subscription',
							'body' => $body,	
						));	
						$result = $email->send();
					}					
					catch (Exception $e) {
						//DO NOTHING
						$error = "";
					}
				}
			}
			
	
			//ATTACH USERS TO THE RIGHT EVENTS/COURSES
			if($product->get('pro_evt_event_id')){					
				$event = new Event($product->get('pro_evt_event_id'), TRUE);
				
				//ADD THE USER TO THE EVENT, SUBSCRIPTIONS CANNOT BE TIME LIMITED
				$event_registrant = $event->add_registrant($user->key, $order_item, NULL, NULL);

				//THE RECORDING CONSENT BOX
				if(isset($data['record_terms'])){ 
					$event_registrant->set('evr_recording_consent', TRUE);	
					$event_registrant->save();	
				}
				
				//LINK THE ORDER ITEM AND THE EVENT REGISTRATION
				$order_item->set('odi_evr_event_registrant_id', $event_registrant->key);
				$order_item->save();								
				
				//SEND THE EMAIL
				$email_fill['event_name'] = $event->get('evt_name');			
				$email_fill['more_info_required'] = false;
				if($event->get('evt_collect_extra_info')){
					$email_fill['more_info_required'] = true;	
				}
				$email_fill['event_registrant_id'] = $event_registrant->key;

				$template = 'event_reciept_content';
				
				$final_fill = array_merge($default_fill, $email_fill);
				$activation_email = new EmailTemplate($template, $user);
				$activation_email->fill_template($final_fill);
				$activation_email->send();
				

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
				$activation_email = new EmailTemplate('event_bundle_content', $user);
				$activation_email->fill_template($final_fill);
				$activation_email->send();					
				
			}
			else{
				//SUBSCRIPTION
				$email_fill['purchase_amount'] = ($price - $discount);
				$final_fill = array_merge($default_fill, $email_fill);
				$activation_email = new EmailTemplate('subscription_reciept', $user);
				$activation_email->fill_template($final_fill);
				$activation_email->send();
				
		
			}	
			
			//RUN THE PRODUCT SCRIPTS
			$product->run_product_scripts($user, $order_item);



			if($product->get('pro_trial_period_days')){
				$trial = ' (' . $product->get('pro_trial_period_days') . ' day free trial)';	
			}
			else{
				$trial = '';
			}
		
			$receipts[$key+1]['pname'] = $product_name . $trial;
			$receipts[$key+1]['name'] = $data['full_name_first']. ' ' .$data['full_name_last'];
			$receipts[$key+1]['price'] = $price - $discount;

		}
		else{
			//ASSEMBLE THE STRIPE CHARGE DESCRIPTION
			$stripe_current_item = substr($product_name, 0, 40) .' ('.$quantity.') - $'. ($price - $discount). ' ';
			array_push($stripe_item_list, $stripe_current_item);		
		}
	}		


	//NOW CHARGE THE CREDIT CARD FOR THE REMAINING AMOUNT
	if($cart->get_non_recurring_total()){
		if($payment_service == 'stripe_regular'){

			try{
				$charge_result = $stripe_helper->process_charge($source_result, $cart->get_non_recurring_total(), $stripe_customer_id, $stripe_item_list, $billing_user, $order);
			}
			catch (Exception $e) {		  
				$stored_error = "Card not charged.   Error type: ". $e->getError()->type . "  Code: " . $e->getError()->code. "  Decline code: ". $e->getError()->decline_code . "  Message: ".$e->getMessage(). "  Debug info: ".$e->getError()->doc_url .", ". $e->getError()->param;

				$error = "Sorry, we weren't able to charge your card. <strong>" . $e->getMessage()."</strong> Please use your back button to go back to the checkout form and try again or contact us at ".$settings->get_setting('defaultemail')." if you keep having trouble.";
				$order->set('ord_status', Order::STATUS_ERROR);
				$order->set('ord_error', substr($stored_error, 0, 250));
				$order->save();	
				PublicPage::OutputGenericPublicPage("Card Error", "Card Error", $error);

				exit;
			}

			//STORE THE CHARGE ID
			$order->set('ord_stripe_charge_id', $charge_result->id);
			
			//MARK THE ORDER PAID
			$order->set('ord_status', Order::STATUS_PAID);
			$order->save();	
		}	
	}	
	
	
	//NOW HANDLE ALL OF THE NON RECURRING ITEMS
	foreach($cart->items as $key => $cart_item) {
		$email_fill = array();
		list($quantity, $product, $data, $price, $discount) = $cart_item;
		$product_version = $product->get_product_version($data);
		//$price = $product->get_price($product_version, $data);
		$email_fill['purchase_amount'] = $price - $discount;

		//ONLY NON RECURRING
		$one_time_purchase_exists = 0;
		if(!$product->get('pro_recurring')){
			$one_time_purchase_exists = 1;
			
			
			
			
			//CREATE THE ORDER ITEM
			$order_item = new OrderItem(NULL);
			$order_item->set('odi_ord_order_id', $order->key);
			$order_item->set('odi_pro_product_id', $product->key);
			$order_item->set('odi_usr_user_id', $user->key);
			$order_item->set('odi_product_info', base64_encode(serialize($data)));
			$order_item->set('odi_price', $price - $discount);
			$order_item->set('odi_is_subscription', false);			
			if ($product_version) {
				$order_item->set('odi_prv_product_version_id', $product_version->prv_product_version_id);
			}
		
			//STORE COMMENT IF ENTERED
			if(isset($data['comment'])){
				$order_item->set('odi_comment', $data['comment']);	
			}
			
			$order_item->set('odi_status', OrderItem::STATUS_PAID);
			$order_item->set('odi_status_change_time', 'now()');
			$order_item->save();				
			$order_item->load();
			
			//STORE ANY USED COUPONS, ONE ENTRY IN THE COUPON CODES USE TABLE, FK IN ORDER ITEMS
			foreach($cart->coupon_codes as $coupon_code_name){
				if($valid_coupons = $product->get_valid_coupons()){
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
			
			//SAVE THE EXTRA INFO THE USER ENTERED.  IT'S CURRENTLY SITTING IN THE CART
			$order_item->save_cart_data($data);
			
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
				$activation_email = new EmailTemplate($template, $user);
				$activation_email->fill_template($final_fill);
				$activation_email->send();
				

			}
			else if($product->get('pro_grp_group_id')){
				//IT IS AN EVENT BUNDLE
				$group = new Group($product->get('pro_grp_group_id'), TRUE);
				$group_members = $group->get_member_list();
				foreach ($group_members as $group_member){
					$event = new Event($group_member->get('grm_foreign_key_id'), TRUE);
					$event_registrant = $event->add_registrant($user->key, $order_item, NULL, $product->get('pro_expires'));

					//THE RECORDING CONSENT BOX
					if(isset($data['record_terms'])){ 
						$event_registrant->set('evr_recording_consent', TRUE);	
					}
					$event_registrant->save();
				}
			}
			else{
				//SUBSCRIPTION
				$email_fill['purchase_amount'] = $price - $discount;
				$final_fill = array_merge($default_fill, $email_fill);
				$activation_email = new EmailTemplate('purchase_reciept', $user);
				$activation_email->fill_template($final_fill);
				$activation_email->send();
			}	
			
			//RUN THE PRODUCT SCRIPTS
			$product->run_product_scripts($user, $order_item);

			$receipts[$key+1]['pname'] = $product->get('pro_name').' '. $product_version->prv_version_name;
			$receipts[$key+1]['name'] = $data['full_name_first']. ' ' .$data['full_name_last'];
			$receipts[$key+1]['price'] = $price - $discount;
			
			if($product->get('pro_digital_link')){			
				$receipts[$key+1]['link'] = $product->get('pro_digital_link');	
			}			
			
			
		}
		
		if($one_time_purchase_exists){
			//SEND NOTIFICATION
			if($settings->get_setting('single_purchase_notification_emails')){
				$notify_emails = explode(',', $settings->get_setting('single_purchase_notification_emails'));
				foreach($notify_emails as $notify_email){
					try {
						$notify_user = User::GetByEmail($notify_email);
						$body = 'Order '. $order->key .' was charged - user: '.$billing_user->display_name().' '.$billing_user->get('usr_email').'.';
						$email_inner_template = $settings->get_setting('individual_email_inner_template');
						$email = new EmailTemplate($email_inner_template, $notify_user);
						$email->fill_template(array(
							'subject' => 'New Order',
							'body' => $body,
						));	
						$result = $email->send();
					}					
					catch (Exception $e) {
						//DO NOTHING
						$error = "";
					}
				}
			}
		}
		
		//UPDATE THE CALCULATED STILL AVAILABLE FIELD
		if($product->get('pro_max_purchase_count') > 0){
			$remaining = $product->get('pro_max_purchase_count') - $product->get_number_purchased();
			$product->set('pro_num_remaining_calc', $remaining);
			$product->save();
		}		
	}

	
	
	

	
	$cart->last_receipt = $receipts;
	$cart->clear_cart();
	
	 
	return $page_vars;
}

?>