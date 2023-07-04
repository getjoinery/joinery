<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/EmailTemplate.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/address_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/order_items_class.php');
	
	$settings = Globalvars::get_instance();
	$composer_dir = $settings->get_setting('composerAutoLoad');	
	require_once $composer_dir.'autoload.php';
	
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
	$order_user = new User($order_item->get('odi_usr_user_id'), TRUE);
	$order = $order_item->get_order();
	$order_item->authenticate_write($session);

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
	
	$stripe = new \Stripe\StripeClient([
		'api_key' => $api_key,
		'stripe_version' => '2022-11-15'
	]);

	$stripe_subscription = $stripe->subscriptions->retrieve($order_item->get('odi_stripe_subscription_id'));
	if($stripe_subscription[canceled_at]){
		//SUBSCRIPTION HAD ALREADY ENDED
		$canceled_at = gmdate("c", $stripe_subscription[canceled_at]);
		$order_item->set('odi_subscription_cancelled_time', $canceled_at);
		
		//ONLY SAVE TO DATABASE IF IN DEBUG MODE OR REGULAR MODE
		//DO NOT SAVE TO DATABASE IF TEMPORARILY IN TEST MODE
		if($settings->get_setting('debug') || (!$_SESSION['test_mode'] && !$settings->get_setting('debug'))){
			$order_item->save();
		}
		else{
			echo 'TEST MODE: Subscription is already cancelled.';
		}
		
	}
	else{
		try {
			$response =$stripe_subscription->cancel();
		}					
		catch (Exception $e) {
			//DO NOTHING
			$error = "We were unable to cancel that subscription.  Please contact the webmaster.";
			echo 'There was an error canceling the subscription: '. $error;
			exit;
		}	
		
		if($response[canceled_at]){
			$canceled_at = gmdate("c", $response[canceled_at]);
			//IF SUBSCRIPTION ENDED, REMOVE 
			$order_item->set('odi_subscription_cancelled_time', $canceled_at);
			
			
			//ONLY SAVE TO DATABASE IF IN DEBUG MODE OR REGULAR MODE
			//DO NOT SAVE TO DATABASE IF TEMPORARILY IN TEST MODE
			if($settings->get_setting('debug') || (!$_SESSION['test_mode'] && !$settings->get_setting('debug'))){
				$order_item->save();
			}
			else{
				echo 'TEST MODE: Subscription would be cancelled.';
			}			
		}
		
		//SEND NOTIFICATION
		if($settings->get_setting('subscription_notification_emails')){
			$notify_emails = explode(',', $settings->get_setting('subscription_notification_emails'));
			foreach($notify_emails as $notify_email){
				try {
					$notify_user = User::GetByEmail($notify_email);
					$body = 'Subscription '.$order_item->get('odi_stripe_subscription_id').' (Order '. $order->key .') was cancelled for user '.$order_user->display_name().' ('.$order_user->get('usr_email').')';
					$email_inner_template = $settings->get_setting('individual_email_inner_template');
					$email = new EmailTemplate($email_inner_template, $notify_user);
					$email->fill_template(array(
						'subject' => 'Cancelled Subscription',
						'body' => $body,
					));	
					$result = $email->send();
				}					
				catch (Exception $e) {
					//DO NOTHING
					$error2 = "";
				}
			}
		}
	}


	//NOW REDIRECT
	$session = SessionControl::get_instance();
	$returnurl = $session->get_return();
	header("Location: $returnurl");
	exit();

?>