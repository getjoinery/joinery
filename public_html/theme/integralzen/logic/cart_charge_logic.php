<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/stripe-php/init.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/Activation.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/ShoppingCart.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/orders_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/products_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/product_details_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_registrants_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/EmailTemplate.php');

	$settings = Globalvars::get_instance();
	if(!$settings->get_setting('products_active')){
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();
	}

	$session = SessionControl::get_instance();
	//$session->check_permission(0); 


	\Stripe\Stripe::setApiKey($settings->get_setting('stripe_api_key'));

	$cart = $session->get_shopping_cart();
	$charge_total = $cart->get_total();
	$receipts = array();
	
	
	if(!$cart->items){
		LibraryFunctions::Redirect('/cart_confirm'); 
		exit();		
	}

	//HANDLE THE BILLING USER
	$billing_user = User::GetByEmail(trim($cart->billing_user['billing_email']));
	if(!$billing_user){
		$cart_billing_user = $cart->billing_user;
		//CREATE THE USER	
		$billing_user = User::CreateNewUser($cart_billing_user['billing_first_name'], $cart_billing_user['billing_last_name'], $cart_billing_user['billing_email'], NULL, TRUE); 
	}
	
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
	
	//IF IT IS A NONZERO CART, REQUIRE CREDIT CARD INFO
	if(!isset($_REQUEST['stripeToken']) && $charge_total > 0){
		$log_error = "The credit card information was not submitted because your browser is not using https.  Go back to the previous page and make sure that you are accessing this page securely (look for https in the address bar or a lock icon).  For help, contact us at ".$settings->get_setting('defaultemail')." .";
		$order->set('ord_error', $log_error);
		$order->save();

		throw new SystemDisplayableError($log_error);
		exit();					
	}	
		
	//HANDLE THE STRIPE USER
	if($billing_user->get('usr_stripe_customer_id')){
		//IF WE STORED A CUSTOMER ID
		$stripe_customer_id = $billing_user->get('usr_stripe_customer_id');
	}
	else{
		//CHECK ON STRIPE 
		$stripe_customer = \Stripe\Customer::all(["email" => $billing_user->get('usr_email')]);
		if($stripe_customer[data][0][id]){
			//IF THERE IS A CUSTOMER ID AT STRIPE
			$stripe_customer_id = $stripe_customer[data][0][id];
		}
		else{		
			//IF THERE IS NO CUSTOMER ID
			$stripe_customer = \Stripe\Customer::create([
				'name' => $billing_user->get('usr_first_name'). ' ' . $billing_user->get('usr_last_name'),
				'email' => $billing_user->get('usr_email'),
				'description' => $billing_user->get('usr_first_name'). ' ' . $billing_user->get('usr_last_name'). ' ('.$billing_user->get('usr_email').')',
			]);
			$stripe_customer_id = $stripe_customer[id];
		}
	}

	
	//SAVE THE CUSTOMER ID
	if(!$billing_user->get('usr_stripe_customer_id')){
		$billing_user->set('usr_stripe_customer_id', $stripe_customer_id);
		$billing_user->save();
	}


	//NOW CHARGE THE CARD
	$has_subscription = 0;
	$stripe_item_list = array();
	foreach($cart->get_detailed_items() as $cart_item) {

		if($cart_item['recurring']){
			
			//CHECK FOR EXISTING PLAN
			try{
				$plan_name = 'recurring_donation-' . (int)$cart_item['price'];
				$plan = \Stripe\Plan::retrieve($plan_name);
			}
			catch (Exception $e) {
				//CREATE NEW PLAN
				$plan = \Stripe\Plan::create([
				  "amount" => (int)$cart_item['price'] * 100,
				  "interval" => "month",
				  "product" => [
					"name" => 'Recurring donation $' . (int)$cart_item['price'],
				  ],
				  "currency" => "usd",
				  "id" => 'recurring_donation-' . (int)$cart_item['price'],
				]); 							
			}

			$plan_items = array(
				'plan' => $plan['id'],
			);
			
			
			$has_subscription =1;
			$charge_total = $charge_total - $cart_item['price'];
			
			$stripe_current_item = $cart_item['name'] .' ('.$cart_item['quantity'].') - '. $cart_item['price']. ' ';
			array_push($stripe_item_list, $stripe_current_item);

			
		}
		else{
			//ASSEMBLE THE STRIPE PRODUCT ARRAY
			$stripe_current_item = $cart_item['name'] .' ('.$cart_item['quantity'].') - $'. $cart_item['price']. ' ';
			
			if($cart_item['price'] > 0){
				array_push($stripe_item_list, $stripe_current_item);		
			}							
		}
	}		


	try{
		$customer_name = $billing_user->get('usr_first_name') . ' ' . $billing_user->get('usr_last_name');
		
		//FILL IN THE ORDER
		$order->set('ord_stripe_customer_id', $stripe_customer_id); 
		if($has_subscription){
			$order->set('ord_stripe_subscription_id', $subscription_result[id]);
		}
		$order->save();		

		
		$error = '';
		if($has_subscription){
			//STORE PAYMENT METHOD 
			$source_result = \Stripe\Customer::createSource( 
			$stripe_customer_id, 
			[ 'source' => [ 'object' => 'source', 'type' => 'card', 'token' => $_REQUEST['stripeToken'], ], ] );


			//START THE SUBSCRIPTION
			$plan_items['metadata'] = array(
				"ord_order_id" => $order->get('ord_order_id'), 
			     "customer_name" => $customer_name,
			     "customer_email" => $billing_user->get('usr_email')
			);
			$plan_items_wrap = array($plan_items);
			$subscription_result = \Stripe\Subscription::create([
			  'customer' => $stripe_customer_id,
			  'items' => $plan_items_wrap,
			  'metadata' => [
			     "ord_order_id" => $order->get('ord_order_id'), 
			     "customer_name" => $customer_name,
			     "customer_email" => $billing_user->get('usr_email')],
			]);	
			
		}
		else if($charge_total > 0){
			//STORE PAYMENT METHOD 
			$source_result = \Stripe\Source::create([
			  "type" => "card",
			  //'customer' => $stripe_customer_id,
			  'token' => $_REQUEST['stripeToken'],
			]);		
		}
		
		
		if($charge_total > 0){
			//CHARGE THE PURCHASE
			$charge_result = \Stripe\Charge::create([
			  'source' => $source_result[id],
			  'amount' => (int)$charge_total*100,
			  'currency' => 'usd',
			  'customer' => $stripe_customer_id,
			  'description' => implode(",", $stripe_item_list),  
			  //'billing_details' => ['email' => $billing_user->get('usr_email'), 'name' => $customer_name, ],
			  'metadata' => [
			     "ord_order_id" => $order->get('ord_order_id'), 
			     "customer_name" => $customer_name,
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


	$order->set('ord_status', 2);
	$order->save();	
	
	$email_info = array();
	$email_fill = array();
				
	foreach($cart->items as $key => $cart_item) {
		list($quantity, $product, $data) = $cart_item;
		$product_version = $product->get_product_version($data);
		
		//DEAL WITH CREATING USERS FOR EACH PRODUCT ITEM
		$user = User::GetByEmail($data['email']);
		if(!$user){
			$user = User::CreateNewUser($data['full_name_first'], $data['full_name_last'], $data['email'], NULL, TRUE); 
		}
		
		//ADD TO THE MAILING LIST IF CHOSEN
		if(isset($data['newsletter']) && $data['newsletter']){
			$status = $user->add_to_mailing_list();		
		}
		
		$price = $product->get_price($product_version, $data['user_price']);

		$email_info['is_deposit'] = $product_version->prv_is_deposit;
		
		
		$receipts[$key+1][pname] = $product->get('pro_name').' '. $product_version->prv_version_name;
		$receipts[$key+1][name] = $data['full_name_first']. ' ' .$data['full_name_last'];
		$receipts[$key+1][price] = $price;
		


		//ATTACH USERS TO THE RIGHT EVENTS/COURSES
		if($product->get('pro_evt_event_id')){
			$email_info['is_event_registration'] = TRUE;
			$email_info['is_single_donation'] = FALSE;
			$email_info['is_recurring_donation'] = FALSE;
							
			$event = new Event($product->get('pro_evt_event_id'), TRUE);
			$email_fill['event_name'] = $event->get('evt_name');
			
			$email_fill['more_info_required'] = false;
			if($event->get('evt_collect_extra_info')){
				$email_fill['more_info_required'] = true;	
			}
			
			//ADD TO REGISTRANTS
			$event_registrant = $event->add_registrant($user->key, $order->key);
			//USER MUST HAVE CLICKED THE RECORDING CONSENT BOX
			if(isset($data['record_terms'])){  //IF IT IS AN ONLINE COURSE
				$event_registrant->set('evr_recording_consent', TRUE);
				$event_registrant->save();		
			} 				
			
			
			
			$email_fill['event_registrant_id'] = $event_registrant->key;
			

		}
		else{
			//IT IS A DONATION OF SOME SORT
			if($product->get('pro_recurring')){
				//RECURRING DONATION
				$email_info['is_event_registration'] = FALSE;
				$email_info['is_single_donation'] = FALSE;
				$email_info['is_recurring_donation'] = TRUE;
				$email_fill['purchase_amount'] = $price;
			}
			else{
				//SINGLE DONATION
				$email_info['is_event_registration'] = FALSE;
				$email_info['is_single_donation'] = TRUE;
				$email_info['is_recurring_donation'] = FALSE;
				$email_fill['donation_amount'] = $price;
			}			
		}



		//CREATE THE ORDER ITEM
		$order_item = new OrderItem(NULL);
		$order_item->set('odi_ord_order_id', $order->key);
		$order_item->set('odi_pro_product_id', $product->key);
		$order_item->set('odi_usr_user_id', $user->key);
		$order_item->set('odi_product_info', base64_encode(serialize($data)));
		
		//STORE COMMENT IF ENTERED
		if(isset($data['comment'])){
			$order_item->set('odi_comment', $data['comment']);	
		}
		
		if($product->get('pro_evt_event_id')){
			$order_item->set('odi_evr_event_registrant_id', $event_registrant->key);
		}
		
		if ($product_version) {
		//THIS PRODUCT HAS A VERSION THAT WE SHOULD PULL TO GET THE PRICE
			$order_item->set('odi_prv_product_version_id', $product_version->prv_product_version_id);
		}	

		$order_item->set('odi_price', $price);
		$email_fill['purchase_amount'] = $price;			
		
		$order_item->set('odi_status_change_time', 'NOW');
		
		$order_item->save();

		Activation::purchase_reciept_send($user, $email_info, $email_fill);

	}		
	
	$cart->last_receipt = $receipts;
	$cart->clear_cart();
	

	
	//NOW REDIRECT TO CONFIRMATION PAGE
	LibraryFunctions::Redirect('/cart_confirm'); 

?>