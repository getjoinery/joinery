<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/stripe-php/init.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/Activation.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/ShoppingCart.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/cart_logs_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/orders_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/products_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/product_details_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_registrants_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/EmailTemplate.php');
	$settings = Globalvars::get_instance();
	$composer_dir = $settings->get_setting('composerAutoLoad');	
	require $composer_dir.'autoload.php';
	use Mailgun\Mailgun;

	$session = SessionControl::get_instance();
	//$session->check_permission(0); 

	
	$settings = Globalvars::get_instance();
	$mg = new Mailgun($settings->get_setting('mailgun_api_key'));
	$domain = $settings->get_setting('mailgun_domain');

	\Stripe\Stripe::setApiKey($settings->get_setting('stripe_api_key'));

	$cart = $session->get_shopping_cart();
	$charge_total = $cart->get_total();
	$receipts = array();
	
	
	if(!$cart->items){
		LibraryFunctions::Redirect('/cart_confirm'); 
		exit();		
	}


	$cl = new CartLog(NULL);
	$cl->set('cls_vse_visitor_id', $session->get_uniqid());
	if($session->get_user_id()){
		$cl->set('cls_usr_user_id_logged_in', $session->get_user_id());
	}
	$cl->set('cls_file', $_SERVER['PHP_SELF']);
	$cl->set('cls_os', SessionControl::getOS());
	$cl->set('cls_browser', SessionControl::getBrowser());
	$cl->set('cls_context', print_r($cart, true));
	$cl->prepare();
	$cl->save();
	

	//HANDLE THE BILLING USER
	$billing_user = User::GetByEmail(trim($cart->billing_user['billing_email']));
	if(!$billing_user){
		$cart_billing_user = $cart->billing_user;
		//CREATE THE USER	
		$billing_user = User::CreateNewUser($cart_billing_user['billing_first_name'], $cart_billing_user['billing_last_name'], $cart_billing_user['billing_email'], NULL, TRUE); 
	}
	
	//LOG THE BILLING USER
	$cl->set('cls_usr_user_id_billing', $billing_user->key);
	$cl->prepare();
	$cl->save();	
	
	//IF IT IS A NONZERO CART, REQUIRE CREDIT CARD INFO
	if(!isset($_REQUEST['stripeToken']) && $charge_total > 0){
		$log_error = "The credit card information was not submitted because your browser is not using https.  Go back to the previous page and make sure that you are accessing this page securely (look for https in the address bar or a lock icon).  For help, contact us at ".$settings->get_setting('defaultemail')." .";
		$cl->set('cls_error', $log_error);
		$cl->prepare();
		$cl->save();

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
		
		//CREATE THE ORDER
		$order = new Order(NULL);
		$order->set('ord_usr_user_id', $billing_user->key);
		//$order->set('ord_stripe_session_id', $sessionobject->id);
		$order->set('ord_stripe_customer_id', $stripe_customer_id); 
		//$order->set('ord_raw_response', $sessionobject);
		//$order->set('ord_stripe_payment_intent_id', $sessionobject->payment_intent);
		if($has_subscription){
			$order->set('ord_stripe_subscription_id', $subscription_result[id]);
		}
		$order->set('ord_total_cost', $cart->get_total());
		$order->set('ord_timestamp', 'now');	
		$order->set('ord_raw_cart', print_r($cart, true));
		$order->set('ord_serialized_cart', serialize($cart->get_items_generic()));	
		$order->set('ord_status', 1);			
		$order->prepare();
		$order->save();	
		$order->load();		

		//LOG THE ORDER
		$cl->set('cls_ord_order_id', $order->key);
		$cl->prepare();
		$cl->save();
		
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
	  // Since it's a decline, \Stripe\Error\Card will be caught
	  $body = $e->getJsonBody();
	  $error  = $body['error']['message'];
	  
		//LOG THE ERROR		
		$log_error = $error . print_r($e, true);	
	} 
	// Probably want to log all of these for later or send yourself a notification
	catch (\Stripe\Error\RateLimit $e) {
	  $error = "Sorry, we weren't able to authorize your card due to too many requests. You have not been charged.";

		//LOG THE ERROR		
		$log_error = $error . print_r($e, true);		
	} catch (\Stripe\Error\InvalidRequest $e) {
	  $error = "Sorry, we weren't able to authorize your card due to an invalid request. That's our fault. You have not been charged.";
		//LOG THE ERROR		
		$log_error = $error . print_r($e, true);		
	} catch (\Stripe\Error\Authentication $e) {
	  $error = "Sorry, we weren't able to authorize your card because it did not authenticate. You have not been charged.";
		//LOG THE ERROR		
		$log_error = $error . print_r($e, true);		
	} catch (\Stripe\Error\ApiConnection $e) {
	  $error = "Sorry, we weren't able to authorize your card because our connection to our credit card processor is not working. You have not been charged.";
		//LOG THE ERROR		
		$log_error = $error . print_r($e, true);		
	} catch (\Stripe\Error\Base $e) {
	  $error = "Sorry, we weren't able to authorize your card. You have not been charged.";
		//LOG THE ERROR		
		$log_error = $error . print_r($e, true);	
	} catch (Exception $e) {
	  $error = "Sorry, we weren't able to authorize your card for some reason. You have not been charged.";
		//LOG THE ERROR		
		$log_error = $error . print_r($e, true);
	}

	if($error){
		$cl->set('cls_error', $log_error);
		$cl->prepare();
		$cl->save();		
		
		throw new SystemDisplayableError($error. "  Contact us at ".$settings->get_setting('defaultemail')." if you keep having trouble.");
		exit();		 
	}

	$order->set('ord_status', 2);
	$order->prepare();
	$order->save();	
	
	$email_info = array();
	$email_info['is_event_registration'] = FALSE;
	$email_info['is_single_donation'] = FALSE;
	$email_info['is_recurring_donation'] = FALSE;
	$email_info['is_deposit'] = FALSE;
	
	$email_fill = array();
	$email_sent_list = array();

				
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
		
		
		//HANDLE PRICES
		if($product->get('pro_user_choose_price') && isset($data['user_price'])){
			//THIS IS A CUSTOM PRICE, USE THE USER FILLED IN PRICE
			$price = $data['user_price'];
		}
		else if ($product_version) {
			//THIS PRODUCT HAS A VERSION THAT WE SHOULD PULL TO GET THE PRICE
			$price = $product_version->prv_version_price;
		} else {
			//GET THE PRICE OFF OF THE PRODUCT
			$price = $product->get('pro_price');
		}	 

		if($price == 0 || $price >= $product->get('pro_price')){
			$email_info['is_deposit'] = FALSE;
		}
		else{
			$email_info['is_deposit'] = TRUE;
		}			
		
		$receipts[$key+1][pname] = $product->get('pro_name').' '. $product_version->prv_version_name;
		$receipts[$key+1][name] = $data['full_name_first']. ' ' .$data['full_name_last'];
		$receipts[$key+1][price] = $price;
		
		
		

		//NOW ATTACH THE USER TO THE ORDER
		//TODO SAVE CURRENT LOGGED IN USER IF PRESENT
		if(!$order->get('ord_usr_user_id')){
			$order->set('ord_usr_user_id', $user->key);
			$order->save();
		}


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
			if($product->get('pro_prg_product_group_id') == 3){
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
		
		//$order_item->set('odi_status', $product->get('pro_initial_odi_status') ?: OrderItem::STATUS_NEW);
		$order_item->set('odi_status_change_time', 'NOW');
		
		$order_item->save();

		Activation::purchase_reciept_send($user, $email_info, $email_fill);
		$email_sent_list[] = $user->get('usr_email');

	}		
	
	$cart->last_receipt = $receipts;
	$cart->clear_cart();
	

	
	//NOW REDIRECT TO CONFIRMATION PAGE
	LibraryFunctions::Redirect('/cart_confirm'); 

?>