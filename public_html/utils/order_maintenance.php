<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_registrants_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_sessions_class.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/stripe-php/init.php');

	$session = SessionControl::get_instance();
	$settings = Globalvars::get_instance();
	
	if($_SESSION['test_mode'] || $settings->get_setting('debug')){
		$api_key = $settings->get_setting('stripe_api_key_test');
		$api_secret_key = $settings->get_setting('stripe_api_pkey_test');
	}
	else{
		$api_key = $settings->get_setting('stripe_api_key');
		$api_secret_key = $settings->get_setting('stripe_api_pkey');		
	}

	if(!$api_key || !$api_secret_key){
		//throw new SystemDisplayablePermanentError("Stripe api keys are not present.");
		exit();			
	}
	\Stripe\Stripe::setApiKey($api_key);
					
	$orders = new MultiOrder(array('user_id' => $user->key));
	$orders->load();	

	
	//PERFORM MAINTENANCE ON THE ORDERS	
	foreach($orders as $order){
		$order_items = $order->get_order_items();
		foreach($order_items as $order_item){
			if($order_item->get('odi_is_subscription') && !$order_item->get('odi_subscription_cancelled_time')){
				//CHECK SUBSCRIPTION STATUS
				try{		
					$stripe_subscription = \Stripe\Subscription::retrieve($order_item->get('odi_stripe_subscription_id'));	
					if($stripe_subscription[status] == 'canceled'){
						$canceled_at = gmdate("c", $stripe_subscription[canceled_at]);
						//IF SUBSCRIPTION ENDED, REMOVE 

						$order_item->set('odi_subscription_cancelled_time', $canceled_at);
						$order_item->save();

					}
				}
				catch(Exception $e){
					//DO NOTHING IF THE API CALL FAILS
					continue;
				}
			}
		}
	
	}

	

?>
