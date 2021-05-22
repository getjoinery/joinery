<?php
	require_once(LibraryFunctions::get_theme_path().'/includes/PublicPage.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublic.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/stripe-php/init.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/Activation.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/ShoppingCart.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/orders_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/products_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/product_details_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_registrants_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/EmailTemplate.php');
	use Mailgun\Mailgun;

	$dbhelper = DbConnector::get_instance();
	$dblink = $dbhelper->get_db_link();

	$session = SessionControl::get_instance();
	//$session->check_permission(0);
	$session_id = $_GET['session_id']; 

	
	$settings = Globalvars::get_instance();
	
	if(!$_SESSION['test_mode']){
		$api_key = $settings->get_setting('stripe_api_key');
		$api_secret_key = $settings->get_setting('stripe_api_pkey');
	}
	else{
		$api_key = $settings->get_setting('stripe_api_key_test');
		$api_secret_key = $settings->get_setting('stripe_api_pkey_test');		
	}

	\Stripe\Stripe::setApiKey($api_key);

	$cart = $session->get_shopping_cart();
	if($cart->items){
		$cart_total = $cart->get_total();
		$temp_cart = $cart;
		if($order = Order::GetByStripeSession($session_id)){
			$order->set('ord_raw_cart', print_r($cart, true));
			$order->save();
		}
		else{		
			$order = new Order(NULL);
			$order->set('ord_total_cost', $cart_total);
			$order->set('ord_timestamp', 'now');
			$order->set('ord_usr_user_id', $billing_user->key);
			$order->set('ord_raw_cart', print_r($cart, true));
			$order->set('ord_serialized_cart', serialize($cart->get_items_generic()));	
			$order->set('ord_status', 1);	
			$order->prepare();	
			$order->save();
			$order->load();						  
		}
	}
	else{
		throw new TTDisplayableError("The cart has already been cleared or you have cookies turned off.  Please try turning cookies on in your browser.");
		exit();				
	}


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
	$billing_user = User::GetByEmail(trim($cart->billing_user['billing_email'])); 
	if(!$billing_user){
		$cart_billing_user = $cart->billing_user;
		//CREATE THE USER	
		$billing_user = User::CreateNewUser($cart_billing_user['billing_first_name'], $cart_billing_user['billing_last_name'], $cart_billing_user['billing_email'], NULL, TRUE); 
		$billing_name = $billing_user->get('usr_first_name') . ' ' . $billing_user->get('usr_last_name');
	}
	
	$order->set('ord_usr_user_id', $billing_user->key);
	$order->prepare();	
	$order->save();
	$order->load();		
		

	//HANDLE THE STRIPE USER
	if(!$_SESSION['test_mode'] && !$settings->get_setting('debug') && $billing_user->get('usr_stripe_customer_id')){
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

	//FILL IN THE ORDER WITH THE CUSTOMER ID
	$order->set('ord_stripe_customer_id', $stripe_customer_id); 
	$order->save();		

	//SAVE THE CUSTOMER ID
	if(!$billing_user->get('usr_stripe_customer_id')){
		$billing_user->set('usr_stripe_customer_id', $stripe_customer_id);
		$billing_user->save();
	}

	

	//PROCESS RECURRING ITEMS

	$stripe_item_list = array();
	foreach($cart->items as $key => $cart_item) {
		$email_fill = array();
		list($quantity, $product, $data) = $cart_item;
		$product_version = $product->get_product_version($data);
		$price = $product->get_price($product_version, $data);
		$product_name = $product->get('pro_name').' '. $product_version->prv_version_name;
		$email_fill['purchase_amount'] = $price;

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
			$order_item->set('odi_price', $price);	
			
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
				$email_fill['purchase_amount'] = $price;
				$final_fill = array_merge($default_fill, $email_fill);
				$activation_email = new EmailTemplate('monthly_donation_reciept', $user);
				$activation_email->fill_template($final_fill);
				$activation_email->send();
				
		
			}	
			
			$receipts[$key+1][pname] = $product_name;
			$receipts[$key+1][name] = $data['full_name_first']. ' ' .$data['full_name_last'];
			$receipts[$key+1][price] = $price;				
						

			$charge_total = $charge_total - $price;
		}
		else{
			//ASSEMBLE THE STRIPE CHARGE DESCRIPTION
			$stripe_current_item = $product_name .' ('.$quantity.') - $'. $price. ' ';
			array_push($stripe_item_list, $stripe_current_item);		
		}
	}		


	//MARK THE ORDER PAID
	$order->set('ord_status', 2);
	$order->save();		
	
	
	//NOW HANDLE ALL OF THE NON RECURRING ITEMS
	foreach($cart->items as $key => $cart_item) {
		$email_fill = array();
		list($quantity, $product, $data) = $cart_item;
		$product_version = $product->get_product_version($data);
		$price = $product->get_price($product_version, $data);
		$email_fill['purchase_amount'] = $price;

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
			$order_item->set('odi_price', $price);
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
				$email_fill['donation_amount'] = $price;
				$final_fill = array_merge($default_fill, $email_fill);
				$activation_email = new EmailTemplate('single_donation_reciept', $user);
				$activation_email->fill_template($final_fill);
				$activation_email->send();
			}	

			$receipts[$key+1][pname] = $product->get('pro_name').' '. $product_version->prv_version_name;
			$receipts[$key+1][name] = $data['full_name_first']. ' ' .$data['full_name_last'];
			$receipts[$key+1][price] = $price;				
			
			
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