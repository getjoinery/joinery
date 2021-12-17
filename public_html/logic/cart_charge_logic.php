<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/stripe-php/init.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ShoppingCart.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/EmailTemplate.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/groups_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/orders_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/products_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/product_details_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_registrants_class.php'); 

	$session = SessionControl::get_instance();
	$settings = Globalvars::get_instance();
	if(!$settings->get_setting('products_active')){
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();
	}
	
	$currency_code = $settings->get_setting('site_currency');
	$currency_symbol = Product::$currency_symbols[$currency_code];

	if($_SESSION['test_mode']){
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

	$cart = $session->get_shopping_cart();
	$charge_total = $cart->get_total();

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
	$billing_user = $cart->get_or_create_billing_user();  //NOTE IF WE ARE IN TEST MODE THIS BILLING USER WILL CONTAIN THE TEST STRIPE ID, BUT THE DATABASE MIGHT CONTAIN THE REAL ONE
	$stripe_customer_id = $billing_user->get('usr_stripe_customer_id'); 

	//GET OR CREATE THE ORDER
	if($settings->get_setting('checkout_type') == 'stripe_checkout'){
		$session_id = $_GET['session_id'];
		if($order = Order::GetByStripeSession($session_id)){
			$order->set('ord_raw_cart', print_r($cart, true));
			$order->save();
		}
		else{		
			throw new SystemDisplayablePermanentError("Something went wrong with the order.  There was no stripe session ID returned.");
			exit();				  
		}

		$order->set('ord_usr_user_id', $billing_user->key);
		$order->prepare();	
		$order->save();
		$order->load();			
	}
	else{
		$order = new Order(NULL);
		$order->set('ord_usr_user_id', $billing_user->key);
		$order->set('ord_total_cost', $cart->get_total());
		$order->set('ord_timestamp', 'now');	
		$order->set('ord_raw_cart', print_r($cart, true));
		$order->set('ord_serialized_cart', serialize($cart->get_items_generic()));	
		$order->set('ord_status', 1);	
		$order->prepare();	
		$order->save();
		$order->load();	 
	}

	//CHECK CREDIT CARD INFO AND STORE IF PRESENT FOR REGULAR STRIPE CHECKOUT
	if($settings->get_setting('checkout_type') == 'stripe_regular' && $charge_total > 0){	
		//IF IT IS A NONZERO CART, REQUIRE CREDIT CARD INFO
		if(!isset($_REQUEST['stripeToken'])){
			$order->set('ord_error', 'The credit card was not submitted because the browser is not using https.');
			$order->save();
			
			$log_error = "The credit card information was not submitted because your browser is not using https.  Go back to the previous page and make sure that you are accessing this page from https (look for the lock icon).  For help, contact us at ".$settings->get_setting('defaultemail')." .";

			throw new SystemDisplayableError($log_error);
			exit();					
		}	

		//STORE PAYMENT METHOD 
		$source_result = \Stripe\Customer::createSource( 
			$stripe_customer_id, 
			[ 'source' => [ 'object' => 'source', 'type' => 'card', 'token' => $_REQUEST['stripeToken'], ], ] );
	}

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

			//DEAL WITH CREATING USERS FOR EACH PRODUCT ITEM
			$user = User::GetByEmail($data['email']);
			if(!$user){
				$user = User::CreateNewUser($data['full_name_first'], $data['full_name_last'], $data['email'], NULL, TRUE); 
			}
			
			$act_code = Activation::getTempCode($user->key, '30 days', Activation::EMAIL_VERIFY, NULL, $user->get('usr_email'));	
			$default_fill = array(
				'act_code' => $act_code,
				'user_id' => $user->key,
			);
			
			//ADD TO THE MAILING LIST IF CHOSEN
			if(isset($data['newsletter']) && $data['newsletter']){
				$status = $user->add_to_mailing_list();		
			}
			
			
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
			$order_item->set('odi_status_change_time', 'NOW');
			$order_item->save();				

			if($settings->get_setting('checkout_type') == 'stripe_regular'){
				//CHECK FOR EXISTING PLAN
				try{
					$plan_name = 'subscription-' . (int)($price - $discount);
					$plan = \Stripe\Plan::retrieve($plan_name);
				}
				catch (Exception $e) {
					//CREATE NEW PLAN
					$plan = \Stripe\Plan::create([
					  "amount" => (int)($price - $discount) * 100,
					  "interval" => 'month',
					  "product" => [
						"name" => 'Subscription '.$currency_symbol . (int)($price - $discount),
					  ],
					  "currency" => $currency_code,
					  "id" => 'subscription-' . (int)($price - $discount),
					]); 							
				}

				$plan_items = array(
					'plan' => $plan['id'],
				);

				//START THE SUBSCRIPTION
				$plan_items['metadata'] = array(
					"ord_order_id" => $order->key, 
					"odi_order_item_id" => $order_item->key, 
					 "customer_name" => $billing_name,
					 "customer_email" => $billing_user->get('usr_email')
				);
				$plan_items_wrap = array($plan_items);
				$subscription_result = \Stripe\Subscription::create([
				  'customer' => $stripe_customer_id,
				  'items' => $plan_items_wrap,
				  'metadata' => [
					 "ord_order_id" => $order->key, 
					 "odi_order_item_id" => $order_item->key,
					 "customer_name" => $billing_name,
					 "customer_email" => $billing_user->get('usr_email')],
				]);		
				

				//IF THE SUBSCRIPTION FAILED MARK IT AS ERROR
				if(!$subscription_result[id]){
					$order_item->set('odi_status', OrderItem::STATUS_ERROR);
					$order_item->set('odi_status_change_time', 'NOW');
					$order_item->save();
					continue;  //SKIP THE REST OF THE ITEM
				}
			}
			
			//SEND NOTIFICATION
			if($settings->get_setting('subscription_notification_emails')){
				$notify_emails = explode(',', $settings->get_setting('subscription_notification_emails'));
				foreach($notify_emails as $notify_email){
					try {
						$notify_user = User::GetByEmail($notify_email);
						$body = 'Subscription '.$subscription_result[id].' (Order '. $order->key .') was started by '.$billing_user->display_name().' '.$billing_user->get('usr_email').'.';
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
			
			//OTHERWISE PROCESS THE ORDER ITEM
			$order_item->set('odi_is_subscription', true);
			$order_item->set('odi_stripe_subscription_id', $subscription_result[id]);
			$order_item->set('odi_stripe_foreign_invoice_id', $subscription_result[latest_invoice]);
			$order_item->set('odi_status', OrderItem::STATUS_PAID);
			$order_item->set('odi_status_change_time', 'NOW');
			$order_item->save();
			
	
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
				$is_deposit = FALSE;
				if($product_version){
					$is_deposit = $product_version->prv_is_deposit;
				}
				if($is_deposit){
					$template = 'event_deposit_reciept_content';
				}
				else{
					$template = 'event_reciept_content';
				}
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
					$event = new Event($group_member->get('grm_evt_event_id'), TRUE);
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
				//RECURRING DONATION
				$email_fill['purchase_amount'] = ($price - $discount);
				$final_fill = array_merge($default_fill, $email_fill);
				$activation_email = new EmailTemplate('monthly_donation_reciept', $user);
				$activation_email->fill_template($final_fill);
				$activation_email->send();
				
		
			}	
			
			$receipts[$key+1][pname] = $product_name;
			$receipts[$key+1][name] = $data['full_name_first']. ' ' .$data['full_name_last'];
			$receipts[$key+1][price] = $price - $discount;				
	
						

			$charge_total = $charge_total - $price - $discount;
		}
		else{
			//ASSEMBLE THE STRIPE CHARGE DESCRIPTION
			$stripe_current_item = substr($product_name, 0, 40) .' ('.$quantity.') - $'. ($price - $discount). ' ';
			array_push($stripe_item_list, $stripe_current_item);		
		}
	}		

	//NOW CHARGE THE CREDIT CARD FOR THE REMAINING AMOUNT
	if($settings->get_setting('checkout_type') == 'stripe_regular'){
		try{	
			if($charge_total > 0){
				
				//CHARGE THE PURCHASE
				$charge_result = \Stripe\Charge::create([
				  'source' => $source_result[id],
				  'amount' => (int)$charge_total*100,
				  'currency' => $currency_code,
				  'customer' => $stripe_customer_id,
				  'description' => implode(",", $stripe_item_list), 
				  //'billing_details' => ['email' => $billing_user->get('usr_email'), 'name' => $billing_name, ],
				  'metadata' => [
					 "ord_order_id" => $order->get('ord_order_id'), 
					 "customer_name" => $billing_name,
					 "customer_email" => $billing_user->get('usr_email')],
				]);
				
			}


		}
		catch(\Stripe\Error\Card $e) {
			// Since it's a decline, \Stripe\Exception\Card will be caught
			$error = "Sorry, we weren't able to charge your card. <strong>" . $e->getMessage()."</strong> Please use your back button to go back to the checkout form and try again or contact us at ".$settings->get_setting('defaultemail')." if you keep having trouble.";
			$order->set('ord_error', substr($error, 0, 250));
			$order->save();	
			PublicPage::OutputGenericPublicPage("Card Error", "Card Error", $error);
		} 
		catch (\Stripe\Exception\RateLimitException $e) {
		  // Too many requests made to the API too quickly
			$error = "Sorry, we weren't able to authorize your card due to too many requests. You have not been charged.";
		} 
		catch (\Stripe\Exception\InvalidRequestException $e) {
			$error = "Sorry, we weren't able to authorize your card due to an invalid request. That's our fault. You have not been charged.";	
		} 
		catch (\Stripe\Exception\AuthenticationException $e) {
		  // Authentication with Stripe's API failed
		  // (maybe you changed API keys recently)
		  $error = "Sorry, our connection to our credit card processor is not currently working. That's our fault. You have not been charged.";
		} 
		catch (\Stripe\Exception\ApiConnectionException $e) {
		  // Network communication with Stripe failed
		  $error = "Sorry, we were unable to reach the credit card processor. That's our fault. You have not been charged.";
		} 
		catch (\Stripe\Exception\ApiErrorException $e) {
		  // Display a very generic error to the user, and maybe send
		  // yourself an email
		  $error = "Sorry, we weren't able to connect to the Stripe api.";
		} 
		catch (Exception $e) {
			$error = "Sorry, we weren't able to charge your card. " . $e->getMessage();
		}

		if($error){
			$order->set('ord_error', substr($error, 0, 250));
			$order->save();		
			
			throw new SystemDisplayablePermanentError($error. "  Contact us at ".$settings->get_setting('defaultemail')." if you keep having trouble.");
			exit();		 
		}

		//STORE THE CHARGE ID
		$order->set('ord_stripe_charge_id', $charge_result->id);
		
		//MARK THE ORDER PAID
		$order->set('ord_status', 2);
		$order->save();	
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
			//DEAL WITH CREATING USERS FOR EACH PRODUCT ITEM
			$user = User::GetByEmail($data['email']);
			if(!$user){
				$user = User::CreateNewUser($data['full_name_first'], $data['full_name_last'], $data['email'], NULL, TRUE); 
			}

			$act_code = Activation::getTempCode($user->key, '30 days', Activation::EMAIL_VERIFY, NULL, $user->get('usr_email'));	
			$default_fill = array(
				'act_code' => $act_code,
				'user_id' => $user->key,
			);
			
			//ADD TO THE MAILING LIST IF CHOSEN
			if(isset($data['newsletter']) && $data['newsletter']){
				$status = $user->add_to_mailing_list();		
			}
			
			
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
			$order_item->set('odi_status_change_time', 'NOW');
			$order_item->save();				


			
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
				$is_deposit = FALSE;
				if($product_version){
					$is_deposit = $product_version->prv_is_deposit;
				}
				if($is_deposit){
					$template = 'event_deposit_reciept_content';
				}
				else{
					$template = 'event_reciept_content';
				}
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
					$event = new Event($group_member->get('grm_evt_event_id'), TRUE);
					$event_registrant = $event->add_registrant($user->key, $order_item, NULL, $product->get('pro_expires'));

					//THE RECORDING CONSENT BOX
					if(isset($data['record_terms'])){ 
						$event_registrant->set('evr_recording_consent', TRUE);	
					}
					$event_registrant->save();
				}
			}
			else{
				//SINGLE DONATION
				$email_fill['donation_amount'] = $price - $discount;
				$final_fill = array_merge($default_fill, $email_fill);
				$activation_email = new EmailTemplate('single_donation_reciept', $user);
				$activation_email->fill_template($final_fill);
				$activation_email->send();
			}	

			$receipts[$key+1][pname] = $product->get('pro_name').' '. $product_version->prv_version_name;
			$receipts[$key+1][name] = $data['full_name_first']. ' ' .$data['full_name_last'];
			$receipts[$key+1][price] = $price - $discount;				
			
			
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
	}			
	

	
	$cart->last_receipt = $receipts;
	$cart->clear_cart();
	
	
	//NOW REDIRECT TO CONFIRMATION PAGE
	LibraryFunctions::Redirect('/cart_confirm'); 

?>