<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/stripe-php/init.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/orders_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/products_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/address_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/stripe_invoices_class.php');


	$session = SessionControl::get_instance();
	$session->check_permission(8);
	
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
	
	
	
	$headers = array('Date', 'Order #', 'Total Amount', 'Billing User', 'Billing Email', 'Address');
	//$altlinks = array('Print format' => '/admin/stripe_charges_synchronize?print-format=true&startdate='.$display_startdate.'&enddate='.$display_enddate);
	$altlinks = array();
	$box_vars =	array(
		'altlinks' => $altlinks,
		'title' => "Stripe charges synchronize"
	);
	$page->tableheader($headers, $box_vars);

	$pagenum = 1;
	$chargenum = 0;
	
	//SAFEGUARD, ONLY RUN 50 PAGES
	while($pagenum <= 50){
		echo '<b>Page '.$pagenum.', Count: '.count($charges[data]).'</b><br>';
		$pagenum++;
			
		foreach($charges as $charge) {
			$chargenum++;
			$error = '';
			if($charge->paid){
				$rowvalues = array();
				//print_r($charge);
				echo 'Order count: '.$chargenum.'<br>';
				
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
					echo 'Time: '.$order->get('ord_timestamp').'<br>';
					echo 'NEW ORDER<br>'; 
					$order->save();
					$order->load();
				}
				
				if(!$order->get('ord_amount_paid')){
					$order->set('ord_amount_paid', $charge->amount/100);
				}
				echo 'Amount: '.$order->get('ord_amount_paid').'<br>';
				
				if(!$order->get('ord_refund_amount')){
					$order->set('ord_refund_amount', $charge->amount_refunded/100);
				}
				echo 'Refund: '.$order->get('ord_refund_amount').'<br>';		
				
				if(!$order->get('ord_stripe_charge_id')){
					$order->set('ord_stripe_charge_id', $charge->id);			
				}
				echo 'Charge: '.$order->get('ord_stripe_charge_id').'<br>';	
				
				if(!$order->get('ord_stripe_payment_intent_id')){
					$order->set('ord_stripe_payment_intent_id', $charge->payment_intent);					
				}
				echo 'PI: '.$order->get('ord_stripe_payment_intent_id').'<br>';
		
				if(!$order->get('ord_stripe_invoice_id')){
					$order->set('ord_stripe_invoice_id', $charge->invoice);					
				}
				echo 'Invoice: '.$order->get('ord_stripe_invoice_id').'<br>';
		
				
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

				if(!$found_user && $order->get('ord_stripe_invoice_id')){
					$existing_invoices = new MultiStripeInvoice(array('stripe_foreign_invoice_id' => $order->get('ord_stripe_invoice_id')));
					foreach ($existing_invoices as $existing_invoice){
						if(!$found_user){
							if($existing_invoice->get('siv_usr_user_id')){
								$order_user = new User($existing_invoice->get('siv_usr_user_id'), TRUE);
								if($order_user->key){
									$found_user = TRUE; 
								}
							}
						}
					}
				}
				
				if($found_user && !$order->get('ord_usr_user_id')){
					$order->set('ord_usr_user_id', $order_user->key);			
				}
				
				if($order_user->key){
					echo 'User: '.$order_user->display_name().'<br>';

					//HANDLE Address
					$address_id = $order_user->get_default_address();
					if($address_id){
						$address = new Address($address_id, TRUE);
						echo 'Default address: '.$address->get_address_string().'<br>';
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
						$address->set('usa_usr_user_id', $order_user->key);
						$address->set('usa_is_default', TRUE);
						$address->set('usa_privacy', 2);
						print_r($address);
						$address->save();
						//$address->update_coordinates();
								
					}
				}
				else{
					$user_name = LibraryFunctions::doSplitName($charge->billing_details->name);
					if($charge['metadata']['customer_email']){
						echo '<b>NEW USER: '.$charge['metadata']['customer_email'].'</b><br>';
						$user = User::CreateNewUser($user_name['first'], $user_name['last'], $charge['metadata']['customer_email'], NULL, FALSE);
						//echo '<b>'.$print_r($user).'</b><br>'; 					
					}
					else if($charge->billing_details->email){
						echo '<b>NEW USER: '.$charge->billing_details->email.'</b><br>';
						$user = User::CreateNewUser($user_name['first'], $user_name['last'], $charge->billing_details->email, NULL, FALSE);
						//echo '<b>'.$print_r($user).'</b><br>'; 					
					}
					else{
						echo '<b>UNKNOWN USER, NO EMAIL</b><br>';
					}

				}
						

			
				$page->disprow($rowvalues);
				echo '<br><br>';
				$offset = $charge->id;
				$order->save();
			}
			else{
				echo 'NOT PAID: '.$charge->id.'<br>';
			}
		}
		

		if($charges->has_more){
			$created = array();
			$created[gte] = $startdate;
			$created[lte] = $enddate;
			$charges = \Stripe\Charge::all(['limit' => $numperpage, 'starting_after' => $offset, 'created' => $created]);
		}
		else{
			break;
		}	
	}
	$page->endtable();	
	
	//echo '<a style="text-align:center" href="/admin/admin_stripe_orders?offset='.$offset .'">Next 100 >></a>';


	if(!$_GET['print-format']){
		$page->admin_footer();
	}
?>
