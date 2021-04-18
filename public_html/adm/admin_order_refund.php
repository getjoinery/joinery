<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/address_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/stripe-php/init.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	if($_SESSION['test_mode'] || $settings->get_setting('debug')){
		$api_key = $settings->get_setting('stripe_api_key_test');
		$api_secret_key = $settings->get_setting('stripe_api_pkey_test');
	}
	else{
		$api_key = $settings->get_setting('stripe_api_key');
		$api_secret_key = $settings->get_setting('stripe_api_pkey');		
	}

	if(!$api_key || !$api_secret_key){
		throw new SystemDisplayablePermanentError("Stripe api keys are not present.");
		exit();			
	}

	\Stripe\Stripe::setApiKey($api_key);
	if (!isset($_REQUEST['stripe_pi'])) {
		throw new TTInvalidFormError('The payment intent id is invalid.');
	}

	//TODO SECURITY CHECK THE USER HERE
	
	//TODO GET THE ORDER AND SEE IF THERE ARE MULTIPLE ITEMS
	
	if (!isset($_POST['amount'])) {
		
		//HOW TO REFUND PART OF A CHARGE https://stripe.com/docs/refunds#issuing
		if($_POST['amount'] == 'all'){
			try{
				$intent = \Stripe\PaymentIntent::retrieve($_REQUEST['stripe_pi']);
				$re = \Stripe\Refund::create([
				'charge' => $intent->charges->data[0]->id
				]);
			}
			catch(\Stripe\Exception $e) {
				  echo 'Status is:' . $e->getHttpStatus() . '\n';
				  echo 'Type is:' . $e->getError()->type . '\n';
				  echo 'Code is:' . $e->getError()->code . '\n';
			}
		}
		else{
			try{
				$intent = \Stripe\PaymentIntent::retrieve($_REQUEST['stripe_pi']);
				$re = \Stripe\Refund::create([
				'charge' => $intent->charges->data[0]->id,
				'amount' => $_POST['amount']
				]);
			}
			catch(\Stripe\Exception $e) {
				  echo 'Status is:' . $e->getHttpStatus() . '\n';
				  echo 'Type is:' . $e->getError()->type . '\n';
				  echo 'Code is:' . $e->getError()->code . '\n';
			}			
		}

		//NOW REDIRECT
		$session = SessionControl::get_instance();
		$returnurl = $session->get_return();
		header("Location: $returnurl");
		exit();
	}
	else{
		//IF MULTIPLE ITEMS
		
		
	}

?>




