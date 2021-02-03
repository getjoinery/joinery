<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');

	$settings = Globalvars::get_instance();
	$site_template = $settings->get_setting('site_template');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/includes/PublicPage.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/includes/FormWriterPublic.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/stripe-php/init.php');

	$session = SessionControl::get_instance();	
	$session->check_permission(0);
	
	$user = new User($session->get_user_id(), TRUE);
	
	$page = new PublicPage();
	
	$customer_ids = array();
	try{	
		$settings = Globalvars::get_instance();
		\Stripe\Stripe::setApiKey($settings->get_setting('stripe_api_key'));

		if($user->get('usr_stripe_customer_id')){
			$customer_ids[] = $user->get('usr_stripe_customer_id');
		}

		$stripe_customers = \Stripe\Customer::all(["email" => $user->get('usr_email')]);	

		foreach($stripe_customers[data] as $stripe_customer){
			if(!in_array($stripe_customer[id], $customer_ids)){
				$customer_ids[] = $stripe_customer[id];
			}
		}
	}
	catch(Exception $e){
		$customer_ids = array();
	}
	
		
	echo '<h2>Recurring donations</h2>';

	$headers = array('Amount', 'Started on', 'Status');
	$page->tableheader($headers, "admin_table");		
		
	foreach($customer_ids as $customer_id){	
		try{
			$subs = \Stripe\Subscription::all(['limit' => 5, 'customer' => $customer_id, 'status' => 'all']);
		}
		catch(Exception $e){
			//TODO: DISPLAY ERROR NOTICE IN TABLE BELOW
			$subs = array();
		}
		
		foreach($subs as $sub) {
			$gmtime = gmdate("Y-m-d\TH:i:s\Z", $sub['created']);
			
			$cancelled = 'Active (<a href="/profile/orders_recurring_action?stripe_sid='. $sub['id']. '">Cancel subscription</a>)';
			if($sub['ended_at']){
				$cancelled = 'Canceled at '. LibraryFunctions::convert_time(gmdate("Y-m-d\TH:i:s\Z", $sub['ended_at']), 'UTC', $session->get_timezone());
			}
			/*
			if($sub['status'] != 'canceled'){
				$actions = '';
			}
			else{
				$actions = 'Canceled';
			}
			*/
			
			$rowvalues = array();
			//array_push($rowvalues, $sub['id']);
			array_push($rowvalues,  '$'.$sub['plan']['amount']/100 .'/month'); 
			array_push($rowvalues, LibraryFunctions::convert_time($gmtime, 'UTC', $session->get_timezone()));
			array_push($rowvalues, $cancelled);
			array_push($rowvalues, $actions);
			$page->disprow($rowvalues);
		}
	}
	$page->endtable();
	?>