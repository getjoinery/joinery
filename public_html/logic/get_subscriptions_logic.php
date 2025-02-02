<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/StripeHelper.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPage.php', '/includes'));
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');

	$settings = Globalvars::get_instance();

	
	$stripe_helper = new StripeHelper();
	
	$session = SessionControl::get_instance();	
	$session->check_permission(0);
	
	if(!$settings->get_setting('products_active')){
		exit;
	}
	
	$user = new User($session->get_user_id(), TRUE);
	
	$page = new PublicPage();
	
	$stripe_customer_id = $stripe_helper->get_or_create_stripe_customer($user);
	
	/*
	$customer_ids = array();
	try{	
		
		if($user->get('usr_stripe_customer_id')){
			$customer_ids[] = $user->get('usr_stripe_customer_id');
		}

		$stripe_customers = $stripe_helper->get_customers(["email" => $user->get('usr_email')]);	

		foreach($stripe_customers['data'] as $stripe_customer){
			if(!in_array($stripe_customer['id'], $customer_ids)){
				$customer_ids[] = $stripe_customer['id'];
			}
		}
	}
	catch(Exception $e){
		$customer_ids = array();
	}
	*/
	?>
	<div class="sidebar-box">
		<h6 class="font-small font-weight-normal uppercase">Recurring Donations</h6>
		<ul class="list-category">
	<?php

	$active = 0;
	//foreach($customer_ids as $customer_id){	
		try{
			$subs = $stripe_helper->get_subscriptions(['limit' => 5, 'customer' => $stripe_customer_id, 'status' => 'all']);
		}
		catch(Exception $e){
			//TODO: DISPLAY ERROR NOTICE IN TABLE BELOW
			$subs = array();
		}
		
		foreach($subs as $sub) {
			$gmtime = gmdate("Y-m-d\TH:i:s\Z", $sub['created']);
			
			if($sub['ended_at']){
				$status = ' canceled on '. LibraryFunctions::convert_time(gmdate("Y-m-d", $sub['ended_at']), 'UTC', $session->get_timezone());
			}
			else{
				$status = '<a href="/profile/orders_recurring_action?stripe_sid='. $sub['id']. '">cancel</a>';
				$active = 1;
			}
			?>
			<li><?php echo '$'.$sub['plan']['amount']/100 .'/month'; ?><span><?php echo $status; ?></span></li>
			<?php
			/*
			if($sub['status'] != 'canceled'){
				$actions = '';
			}
			else{
				$actions = 'Canceled';
			}
			*/
		}
	//}

	if(!$active){
		echo '<a class="button button-dark" href="/product/recurring-donation">Start a new recurring donation</a>';
	}
	?>
			</ul>
	</div>