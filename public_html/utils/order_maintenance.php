<?php
	require_once( __DIR__ . '/../includes/Globalvars.php');
	require_once( __DIR__ . '/../includes/ErrorHandler.php');
	require_once( __DIR__ . '/../includes/LibraryFunctions.php');
	require_once( __DIR__ . '/../includes/SessionControl.php');

	require_once( __DIR__ . '/../data/users_class.php');
	require_once( __DIR__ . '/../data/events_class.php');
	require_once( __DIR__ . '/../data/event_registrants_class.php');
	require_once( __DIR__ . '/../data/event_sessions_class.php');
	
	//require_once( __DIR__ . '/../includes/stripe-php/init.php');
	$settings = Globalvars::get_instance();
	$composer_dir = $settings->get_setting('composerAutoLoad');	
	require_once $composer_dir.'autoload.php';

	$settings = Globalvars::get_instance();
	
	if($_SESSION['test_mode'] || $settings->get_setting('debug')){
		$api_key = $settings->get_setting('stripe_api_key_test');
		$api_secret_key = $settings->get_setting('stripe_api_pkey_test');
	}
	else{
		$api_key = $settings->get_setting('stripe_api_key');
		$api_secret_key = $settings->get_setting('stripe_api_pkey');		
	}

	if($api_key && $api_secret_key){
		
		$stripe = new \Stripe\StripeClient([
			'api_key' => $api_key,
			'stripe_version' => '2022-11-15'
		]);
						
		$orders = new MultiOrder(array('user_id' => $user->key));
		$orders->load();	

		
		//PERFORM MAINTENANCE ON THE ORDERS	
		foreach($orders as $order){
			$order_items = $order->get_order_items();
			foreach($order_items as $order_item){
				if($order_item->get('odi_is_subscription') && !$order_item->get('odi_subscription_cancelled_time')){
					//CHECK SUBSCRIPTION STATUS
					try{		
						$stripe_subscription = $stripe->subscriptions->retrieve($order_item->get('odi_stripe_subscription_id'));	
						if($stripe_subscription[status] == 'canceled'){
							$canceled_at = gmdate("c", $stripe_subscription[canceled_at]);
							//IF SUBSCRIPTION ENDED, REMOVE 

							$order_item->set('odi_subscription_cancelled_time', $canceled_at);

							//ONLY SAVE TO DATABASE IF IN DEBUG MODE OR REGULAR MODE
							//DO NOT SAVE TO DATABASE IF TEMPORARILY IN TEST MODE
							if($settings->get_setting('debug') || (!$_SESSION['test_mode'] && !$settings->get_setting('debug'))){
								$order_item->save();
							}
							

						}
					}
					catch(Exception $e){
						//DO NOTHING IF THE API CALL FAILS
						continue;
					}
				}
			}
		
		}
	}
	

?>
