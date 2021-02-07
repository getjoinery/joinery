<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/address_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/stripe-php/init.php');

	$settings = Globalvars::get_instance();
	if(!$settings->get_setting('products_active')){
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();
	}
	
	$session = SessionControl::get_instance();
	$session->check_permission(0);
	
	$settings = Globalvars::get_instance();
	\Stripe\Stripe::setApiKey($settings->get_setting('stripe_api_key'));	

	if (!isset($_REQUEST['stripe_sid'])) {
		throw new SystemInvalidFormError('The subscription id is invalid.');
	}

	//TODO SECURITY CHECK THE USER HERE

	$sub = \Stripe\Subscription::retrieve($_REQUEST['stripe_sid']);
	$sub->cancel();

	//NOW REDIRECT
	$session = SessionControl::get_instance();
	$returnurl = $session->get_return();
	header("Location: $returnurl");
	exit();

?>