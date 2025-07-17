<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/EmailTemplate.php');
	PathHelper::requireOnce('includes/StripeHelper.php');
	PathHelper::requireOnce('data/address_class.php');
	PathHelper::requireOnce('data/users_class.php');
	PathHelper::requireOnce('data/order_items_class.php');
	
	$stripe_helper = new StripeHelper();
	
	$settings = Globalvars::get_instance();
	if(!$settings->get_setting('products_active')){
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();
	}
	
	$session = SessionControl::get_instance();
	$session->check_permission(0);
	
	$order_item_id = LibraryFunctions::fetch_variable('order_item_id', NULL,1,'order_item_id');
	$order_item = new OrderItem($order_item_id, TRUE);	
	$success = $order_item->cancel_subscription_order_item(true);


	//NOW REDIRECT
	$session = SessionControl::get_instance();
	$returnurl = $session->get_return();
	header("Location: $returnurl");
	exit();

?>