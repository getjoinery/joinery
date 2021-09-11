<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/stripe-php/init.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/orders_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/products_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/address_class.php');


	$session = SessionControl::get_instance();
	$session->check_permission(5);
	
	$settings = Globalvars::get_instance();

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
	
	$numperpage = 100;
	$currpage = LibraryFunctions::fetch_variable('currpage', 1, 0, '');
	$nextpage = $currpage + 1;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$startdate = LibraryFunctions::fetch_variable('startdate', NULL, 0, '');	
	$enddate = LibraryFunctions::fetch_variable('enddate', NULL, 0, '');	
	
	if($startdate){
		$display_startdate = $startdate;	
		$startdate = strtotime($startdate . '00:00:01');
	}
	else{
		//DEFAULT
		$startdate = strtotime('-1 month',time());
		$display_startdate = gmdate("Y-m-d", $startdate);
	}

	
	if($enddate){
		$display_enddate = $enddate;
		$enddate = strtotime($enddate . '23:59:59');	
	}	
	else{
		//DEFAULT
		$enddate = time();
		$display_enddate = gmdate("Y-m-d", $enddate);
	}

	



	
	$created = array();
	$created[gte] = $startdate;
	$created[lte] = $enddate;
	
	if($offset){
		$charges = \Stripe\Charge::all(['limit' => $numperpage, 'starting_after' => $offset, 'created' => $created]);
	}
	else{
		$charges = \Stripe\Charge::all(['limit' => $numperpage, 'created' => $created]);
	}


	
	/*
	$search_criteria = NULL;
	if($user_id){
		$search_criteria = array();
		if($user_id){
			$search_criteria['user_id'] = $user_id;
		}
	}

	$orders = new MultiOrder(
		$search_criteria,
		array($consort=>$consdirection),
		$numperpage,
		$conoffset);
	$numrecords = $orders->count_all();
	$orders->load();
	*/
	if(!$_GET['print-format']){
		$page = new AdminPage();
		$page->admin_header(
		array(
			'menu-id'=> 4,
			'page_title' => 'Stripe charges synchronize',
			'readable_title' => 'Stripe charges synchronize',
		'breadcrumbs' => array(
			'Orders'=>'/admin/admin_orders', 
			'Stripe charges synchronize' => '',
		),
			'session' => $session,
		)
		);	
				
		
	}
	
	$formwriter = new FormWriterMaster("form1");
	echo $formwriter->begin_form("", "get", "/utils/stripe_charges_synchronize");
	echo $formwriter->dateinput("Start Date", "startdate", "dateinput", 30, $display_startdate, "", 10);
	echo $formwriter->dateinput("End Date", "enddate", "dateinput", 30, $display_enddate, "", 10);
	echo $formwriter->hiddeninput('source', 'form');
	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();	
	
	//PAGINATION
	echo '<br /><br />';
	if($offset){
		echo '<a href="/admin/stripe_charges_synchronize?startdate='.$display_startdate.'&enddate='.$display_enddate.'"><< Back to page 1</a>';
		echo ' <strong>Page '.$currpage.'</strong> ';
	}
	
	if(count($charges[data]) == $numperpage){
		$last_charge_number = $numperpage - 1;
		$last_charge = $charges[data][$last_charge_number];
		echo 'Multiple pages of results:  <a href="/admin/stripe_charges_synchronize?offset='.$last_charge->id.'&startdate='.$display_startdate.'&enddate='.$display_enddate.'&currpage='.$nextpage .'">Next page >></a> ';
	}

	
	$headers = array('Date', 'Order #', 'Total Amount', 'Billing User', 'Billing Email', 'Address');
	//$altlinks = array('Print format' => '/admin/stripe_charges_synchronize?print-format=true&startdate='.$display_startdate.'&enddate='.$display_enddate);
	$altlinks = array();
	$box_vars =	array(
		'altlinks' => $altlinks,
		'title' => "Stripe charges synchronize"
	);
	$page->tableheader($headers, $box_vars);

	$chargenum = 0;
	foreach($charges as $charge) {
		$chargenum++;
		$error = '';
		if($charge->paid){
			$rowvalues = array();
			$conoffset = $charge->id;
			//print_r($charge);
			
			$found_order = FALSE;
			if($charge->metadata['ord_order_id']){
				try{
					$order = new Order($charge->metadata['ord_order_id'], TRUE);
					$found_order = TRUE;
				}
				catch (exception $e){
					$found_order = FALSE;
				}	
			}
			
			if(!$found_order && $charge->payment_intent){
				$order = Order::GetByStripePaymentIntent($charge->payment_intent);
				if($order->key){
					$found_order = TRUE;
				}
			}
			
			if(!$found_order && $charge->id){
				$order = Order::GetByStripeCharge($charge->id);
				if($order->key){
					$found_order = TRUE;
				}
			}			
			
			
			if(!$found_order){
				$order = new Order(NULL);
				$order->set('ord_timestamp', gmdate("c", $charge->created));
				echo 'Time:'.$order->get('ord_timestamp').'<br>';
				echo '<b>NO ORDER</b><br>'; 
				echo '<b>Invoice: '.$charge->invoice.'</b><br>';
			}
			
			if(!$order->get('ord_amount_paid')){
				$order->set('ord_amount_paid', $charge->amount/100);
			}
			echo 'Amount:'.$order->get('ord_amount_paid').'<br>';
			
			if(!$order->get('ord_refund_amount')){
				$order->set('ord_refund_amount', $charge->amount_refunded/100);
			}
			echo 'Refund:'.$order->get('ord_refund_amount').'<br>';		
			
			if(!$order->get('ord_stripe_charge_id')){
				$order->set('ord_stripe_charge_id', $charge->id);			
			}
			echo 'Charge:'.$order->get('ord_stripe_charge_id').'<br>';	
			
			if(!$order->get('ord_stripe_payment_intent_id')){
				$order->set('ord_stripe_payment_intent_id', $charge->payment_intent);					
			}
			echo 'PI:'.$order->get('ord_stripe_payment_intent_id').'<br>';
			
			
			//HANDLE THE ORDER USER
			$found_user = FALSE;
			if($order->get('ord_usr_user_id')){
				$order_user = new User($order->get('ord_usr_user_id'), TRUE);
				if($order_user->key){
					$found_user = TRUE;
				}
			}

			if(!$found_user && $charge->customer){
				$order_user = User::GetByStripeCustomerId($charge->customer);
				if($order_user->key){
					$found_user = TRUE;
				}
			}
			
			if(!$found_user && $charge['metadata']['customer_email']){
				$order_user = User::GetByEmail($charge['metadata']['customer_email']);
				if($order_user->key){
					$found_user = TRUE;
				}
			}
			
			if(!$found_user && $charge->billing_details->email){
				$order_user = User::GetByEmail($charge->billing_details->email);
				if($order_user->key){
					$found_user = TRUE;
				}
			}			
			
			
			if(!$found_user){
				$order_user = new User(NULL);
				echo '<b>NO USER</b><br>'; 
			}
			
			if($found_user && !$order->get('ord_usr_user_id')){
				$order->set('ord_usr_user_id', $order_user->key);			
				//$order->save();
			}
			
			if($order_user->key){
				echo 'User:'.$order_user->display_name().'<br>';

				//HANDLE Address
				if($address_id = $order_user->get_default_address()){
					//echo $address->get_address_string().'<br>';
				}
				else{
					$address = new Address(NULL);
					$address->set('usa_is_default', FALSE);
					$address->set('usa_usr_user_id', $user_id);
					$address->set('usa_is_bad', FALSE);
					$address->set('usa_address1', $charge->billing_details->address->line1);
					$address->set('usa_city', $charge->billing_details->address->city);
					$address->set('usa_state', $charge->billing_details->address->state);
					$address->set('usa_zip_code_id', $charge->billing_details->address->postal_code);
					//print_r( $charge->billing_details->address->country).'<br>';
					$address->set('usa_cco_country_code_id', Address::GetCountryCodeFromCountryAbbr($charge->billing_details->address->country));
					$address->set('usa_type', 'HM');
					$address->set('usa_usr_user_id', $order_user->key);
					$address->set('usa_is_default', TRUE);
					$address->set('usa_privacy', 2);
					print_r($address);
					//$address->save();
					//$address->update_coordinates();
							
				}
			}
			else{
				echo 'No user<br>';
			}
					

		
			$page->disprow($rowvalues);
			echo '<br><br>';
		}
	}
	$page->endtable();	
	
	//echo '<a style="text-align:center" href="/admin/admin_stripe_orders?offset='.$offset .'">Next 100 >></a>';


	if(!$_GET['print-format']){
		$page->admin_footer();
	}
?>
