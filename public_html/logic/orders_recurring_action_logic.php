<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/EmailTemplate.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/StripeHelper.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/address_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/order_items_class.php');
	
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
	$order_user = new User($order_item->get('odi_usr_user_id'), TRUE);
	$order = $order_item->get_order();
	$order_item->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));

	$result = $stripe_helper->update_subscription_in_order_item($order_item);
	
	//REFRESH THE ORDER ITEM
	$order_item = new OrderItem($order_item_id, TRUE);

	if(!$order_item->get('odi_subscription_cancelled_time')){

		try {
			$stripe_subscription = $stripe_helper->get_subscription($order_item->get('odi_stripe_subscription_id'));
			
		}					
		catch (Exception $e) {
			throw new SystemDisplayablePermanentError("We were unable to retrieve that subscription (".$order_item->get('odi_stripe_subscription_id').") Please contact the webmaster.");
			exit;
		}	
				
		if(!$stripe_subscription->canceled_at){
		
			try {
				$response = $stripe_subscription->cancel();
			}					
			catch (Exception $e) {
				throw new SystemDisplayablePermanentError("We were unable to cancel that subscription (".$order_item->get('odi_stripe_subscription_id').").  Please contact the webmaster.");
				exit;
			}	
		}
		
		$result = $stripe_helper->update_subscription_in_order_item($order_item);
		
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