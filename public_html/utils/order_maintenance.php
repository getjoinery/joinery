<?php
	require_once( __DIR__ . '/../includes/Globalvars.php');
	require_once( __DIR__ . '/../includes/ErrorHandler.php');
	require_once( __DIR__ . '/../includes/LibraryFunctions.php');
	require_once( __DIR__ . '/../includes/SessionControl.php');
	require_once( __DIR__ . '/../includes/StripeHelper.php');

	require_once( __DIR__ . '/../data/users_class.php');
	require_once( __DIR__ . '/../data/events_class.php');
	require_once( __DIR__ . '/../data/event_registrants_class.php');
	require_once( __DIR__ . '/../data/event_sessions_class.php');
	
	
	$settings = Globalvars::get_instance();
	
	$stripe_helper = new StripeHelper();

					
	$orders = new MultiOrder(array('user_id' => $user->key));
	$orders->load();	

	
	//PERFORM MAINTENANCE ON THE ORDERS	
	foreach($orders as $order){
		$result = $stripe_helper->update_all_subscriptions_in_order($order);
	}
	

?>
