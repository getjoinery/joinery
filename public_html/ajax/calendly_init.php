<?php
	require_once( __DIR__ . '/../includes/Globalvars.php');
	require_once( __DIR__ . '/../includes/DbConnector.php');
	require_once( __DIR__ . '/../includes/FormattingFunctions.php');
	require_once( __DIR__ . '/../data/orders_class.php');
	require_once( __DIR__ . '/../data/product_details_class.php');

	header("HTTP/1.0 404 Not Found");
	echo 'Feature turned off';
	exit;

	//https://github.com/leadthread/php-calendly
	require_once(PathHelper::getComposerAutoloadPath());

	/*
	$settings = Globalvars::get_instance();
	// Calendly integration code removed - package not installed
	// $c = new Calendly($settings->get_setting('calendly_api_key'));
	// $response = $c->registerInviteeCreated("https://empoweredhealthtn.com/ajax/calendly_webhook");
	// print_r($response);
	// $response = $c->registerInviteeCanceled("https://empoweredhealthtn.com/ajax/calendly_webhook_cancel");
	// print_r($response);
	*/	
	
?>