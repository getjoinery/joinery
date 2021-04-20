<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/products_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/phone_number_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/orders_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/order_items_class.php');

	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/stripe-php/init.php');

	$session = SessionControl::get_instance();
	$settings = Globalvars::get_instance();
	
	$session->check_permission(0);
	$session->set_return();
	
	echo 'turned off';
	exit;

		$settings = Globalvars::get_instance();
		\Stripe\Stripe::setApiKey($settings->get_setting('stripe_api_key'));
	
	//SUBSCRIPTIONS
	$order_items = new MultiOrderItem();
	$order_items->load();	

	foreach($order_items as $order_item){
		$order = $order_item->get_order();
		$product = new Product($order_item->get('odi_pro_product_id'), TRUE);
		
		if($order->get('ord_stripe_subscription_id')){
			if($order->get('ord_total_cost') == $order_item->get('odi_price')){
				echo $order->key.' -> '.$order_item->key.' - '.$order->get('ord_stripe_subscription_id');
				$order_item->set('odi_is_subscription', true);
				$order_item->set('odi_stripe_subscription_id', $order->get('ord_stripe_subscription_id'));
				$order_item->save();
				$stripe_subscription = \Stripe\Subscription::retrieve($order->get('ord_stripe_subscription_id'));	
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
