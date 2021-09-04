<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/stripe-php/init.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/orders_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');

$settings = Globalvars::get_instance();
\Stripe\Stripe::setApiKey($settings->get_setting('stripe_api_key'));


// You can find your endpoints secret in your webhook settings
$endpoint_secret = $settings->get_setting('stripe_endpoint_secret');
if(!$endpoint_secret){
	throw new SystemDisplayablePermanentError("Stripe endpoint secret is not present.");
	exit();			
}

$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
$event = null;

try {
  $event = \Stripe\Webhook::constructEvent(
    $payload, $sig_header, $endpoint_secret
  );
} 
catch(\UnexpectedValueException $e) {
  // Invalid payload
  http_response_code(400);
  echo 'Invalid payload';
  exit();
} 
catch(\Stripe\Error\SignatureVerification $e) {
  // Invalid signature
  http_response_code(400);
  echo 'Invalid signature';
  exit();
}


// Handle the checkout.session.completed event
if ($event->type == 'checkout.session.completed') {
	$sessionobject = $event->data->object;

	$total=0;
	foreach ($sessionobject->display_items as $item){
		for ($i=0; $i<(int)$item->quantity; $i++) {
			$total += $item->amount;
		}		
	}
	$total = $total / 100;

	$order = new Order(NULL);
	$order->set('ord_total_cost', $total);
	if($sessionobject->client_reference_id){
		$order->set('ord_usr_user_id', $sessionobject->client_reference_id);
	}
	$order->set('ord_stripe_session_id', $sessionobject->id);
	//$order->set('ord_stripe_customer_id', $sessionobject->customer); 
	$order->set('ord_raw_response', $sessionobject);
	$order->set('ord_stripe_payment_intent_id', $sessionobject->payment_intent);
	$order->set('ord_stripe_subscription_id', $sessionobject->subscription);
	$order->set('ord_status', Order::STATUS_PAID);

	$order->prepare();
	$order->save();	
	http_response_code(200);
}
else{
	http_response_code(400);
	echo 'Wrong checkout type.';
	exit();
}
?>