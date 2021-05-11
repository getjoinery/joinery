<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/EmailTemplate.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/address_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/order_items_class.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/stripe-php/init.php');

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
	
	$settings = Globalvars::get_instance();
	\Stripe\Stripe::setApiKey($settings->get_setting('stripe_api_key'));	

	$sub = \Stripe\Subscription::retrieve($order_item->get('odi_stripe_subscription_id'));
	$sub->cancel();
	
	$stripe_subscription = \Stripe\Subscription::retrieve($order_item->get('odi_stripe_subscription_id'));	
	if($stripe_subscription[status] == 'canceled'){
		$canceled_at = gmdate("c", $stripe_subscription[canceled_at]);
		//IF SUBSCRIPTION ENDED, REMOVE 

		$order_item->set('odi_subscription_cancelled_time', $canceled_at);
		$order_item->save();
		
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
					$error = "";
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