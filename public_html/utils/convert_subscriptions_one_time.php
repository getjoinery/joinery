<?php
	require_once('../includes/Globalvars.php');
	$settings = Globalvars::get_instance();
	$siteDir = $settings->get_setting('siteDir');	
	require_once($siteDir . '/includes/ErrorHandler.php');
	require_once($siteDir . '/includes/LibraryFunctions.php');
	require_once($siteDir . '/includes/SessionControl.php');

	require_once($siteDir . '/data/users_class.php');
	require_once($siteDir . '/data/products_class.php');
	require_once($siteDir . '/data/phone_number_class.php');
	require_once($siteDir . '/data/orders_class.php');
	require_once($siteDir . '/data/events_class.php');
	require_once($siteDir . '/data/order_items_class.php');

	//require_once($siteDir.'/includes/stripe-php/init.php');
	$composer_dir = $settings->get_setting('composerAutoLoad');	
	require_once $composer_dir.'autoload.php';	
	
	$session = SessionControl::get_instance();
	
	$session->check_permission(0);
	$session->set_return();
	
	echo 'turned off';
	exit;

		$settings = Globalvars::get_instance();
		$stripe = new \Stripe\StripeClient($settings->get_setting('stripe_api_key'));
	
	//SUBSCRIPTIONS
	$order_items = new MultiOrderItem();
	$order_items->load();	

	foreach($order_items as $order_item){
		$order = $order_item->get_order();
		$product = new Product($order_item->get('odi_pro_product_id'), TRUE);
		
		if($order_item->get('odi_stripe_subscription_id')){
			if($order->get('ord_total_cost') == $order_item->get('odi_price')){
				echo $order->key.' -> '.$order_item->key.' - '.$order_item->get('odi_stripe_subscription_id');
				$order_item->set('odi_is_subscription', true);
				$order_item->set('odi_stripe_subscription_id', $order_item->get('odi_stripe_subscription_id'));
				$order_item->save();
				$stripe_subscription = $stripe->subscriptions->retrieve($order_item->get('odi_stripe_subscription_id'));	
				if($stripe_subscription[status] == 'canceled'){
					$canceled_at = gmdate("c", $stripe_subscription[canceled_at]);
					
					$order_item->set('odi_subscription_cancelled_time', $canceled_at);
					$order_item->save();
					echo ' CANCELLED ';
				}				
			}
		}
		echo '<br>';
		
	}

	
?>
