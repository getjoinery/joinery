<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/orders_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormattingFunctions.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/product_details_class.php');

	//https://github.com/leadthread/php-calendly
	$settings = Globalvars::get_instance();
	$composer_dir = $settings->get_setting('composerAutoLoad');	
	require $composer_dir.'autoload.php'; 	
	use Zenapply\Calendly\Calendly;

	$settings = Globalvars::get_instance();
	$c = new Calendly($settings->get_setting('calendly_api_key'));
	$response = $c->registerInviteeCreated("https://empoweredhealthtn.com/ajax/calendly_webhook");
	print_r($response);
	$response = $c->registerInviteeCanceled("https://empoweredhealthtn.com/ajax/calendly_webhook_cancel");
	print_r($response);	
	
?>