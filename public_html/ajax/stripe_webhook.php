<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
PathHelper::requireOnce('includes/StripeHelper.php');
PathHelper::requireOnce('data/events_class.php');
PathHelper::requireOnce('data/orders_class.php');

try {
    // StripeHelper handles ALL Stripe setup internally
    $stripe_helper = new StripeHelper();
    $event = $stripe_helper->process_webhook();
    
} catch(StripeHelperException $e) {
    // Stripe configuration errors
    error_log("Stripe webhook configuration error: " . $e->getMessage());
    http_response_code(500);
    echo 'Stripe configuration error';
    exit();
} catch(\UnexpectedValueException $e) {
    // Invalid payload
    http_response_code(400);
    echo 'Invalid payload';
    exit();
} catch(\Stripe\Exception\SignatureVerificationException $e) {
    // Invalid signature
    http_response_code(400);
    echo 'Invalid signature';
    exit();
} catch(\Exception $e) {
    // Any other unexpected errors
    error_log("Stripe webhook unexpected error: " . $e->getMessage());
    http_response_code(500);
    echo 'Webhook processing error';
    exit();
}

// Handle the checkout.session.completed event
if ($event->type == 'checkout.session.completed') {
	$sessionobject = $event->data->object;

	$order = new Order(NULL);
	$order->set('ord_total_cost', $sessionobject->amount_total / 100);
	if($sessionobject->client_reference_id){
		$order->set('ord_usr_user_id', $sessionobject->client_reference_id);
	}
	$order->set('ord_stripe_session_id', $sessionobject->id);
	$order->set('ord_raw_response', $sessionobject);
	$order->set('ord_stripe_payment_intent_id', $sessionobject->payment_intent);
	$order->set('ord_stripe_subscription_id_temp', $sessionobject->subscription);  //TEMPORARILY STORING THIS TO MOVE IT TO THE ORDER ITEM IN CART_CHARGE_LOGIC
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