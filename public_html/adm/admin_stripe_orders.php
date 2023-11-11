<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/StripeHelper.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/orders_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/products_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/address_class.php');


	$session = SessionControl::get_instance();
	$session->check_permission(5);
	
	$settings = Globalvars::get_instance();
	
	$stripe_helper = new StripeHelper();
	
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
	$created['gte'] = $startdate;
	$created['lte'] = $enddate;
	
	if($offset){
		$charges = $stripe_helper->get_charges(['limit' => $numperpage, 'starting_after' => $offset, 'created' => $created]);
		//$charges = $stripe->charges->all(['limit' => $numperpage, 'starting_after' => $offset, 'created' => $created]);
	}
	else{
		$charges = $stripe_helper->get_charges(['limit' => $numperpage, 'created' => $created]);
		//$charges = $stripe->charges->all(['limit' => $numperpage, 'created' => $created]);
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
			'menu-id'=> 'stripe-payments',
			'page_title' => 'Stripe orders',
			'readable_title' => 'Stripe orders',
		'breadcrumbs' => array(
			'Orders'=>'/admin/admin_orders', 
			'Stripe Orders' => '',
		),
			'session' => $session,
		)
		);	
				
		
	}
	
	$formwriter = new FormWriterMaster("form1");
	echo $formwriter->begin_form("", "get", "/admin/admin_stripe_orders");
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
		echo '<a href="/admin/admin_stripe_orders?startdate='.$display_startdate.'&enddate='.$display_enddate.'"><< Back to page 1</a>';
		echo ' <strong>Page '.$currpage.'</strong> ';
	}
	
	if(count($charges['data']) == $numperpage){
		$last_charge_number = $numperpage - 1;
		$last_charge = $charges['data'][$last_charge_number];
		echo 'Multiple pages of results:  <a href="/admin/admin_stripe_orders?offset='.$last_charge->id.'&startdate='.$display_startdate.'&enddate='.$display_enddate.'&currpage='.$nextpage .'">Next page >></a> ';
	}

	
	$headers = array('Date', 'Order #', 'Total Amount', 'Billing User', 'Billing Email', 'Address');
	//$altlinks = array('Print format' => '/admin/admin_stripe_orders?print-format=true&startdate='.$display_startdate.'&enddate='.$display_enddate);
	$altlinks = array();
	$box_vars =	array(
		'altlinks' => $altlinks,
		'title' => "Stripe payments"
	);
	$page->tableheader($headers, $box_vars);

	$chargenum = 0;
	foreach($charges as $charge) {
		$chargenum++;
		if($charge->paid){
			$rowvalues = array();
			$conoffset = $charge->id;
			//array_push($rowvalues, $conoffset);
			
			$order_items = NULL;
			if($charge->metadata['ord_order_id']){
				try{
					$order = new Order($charge->metadata['ord_order_id'], TRUE);
					$order_user = new User($order->get('ord_usr_user_id'), TRUE);
					$order_items = $order->get_order_items();
				}
				catch (exception $e){
					$order = new Order(NULL);
					$order_user = new User(NULL);
				}
			}
			
			array_push($rowvalues, '('.$chargenum . ') '.gmdate("Y-m-d", $charge->created));
			
			if(strpos($charge->description, 'Integral Zen - Order') !== FALSE){
				//print_r($charge['metadata']);
				$description = 'Old Website Recurring Donation';
			}
			else if(strpos($charge->description, 'Invoice') !== FALSE){
				$description =  $charge->description . ' - New Website Recurring Donation' ;
			}
			else if($charge->description){
				$description =  $charge->description;
			}
			else{
				$description = 'One time donation or event registration';
			}		
			
			if($order_items){
				foreach($order_items as $order_item) {
					$product_data = $order_item->get_data();
					if($product_data['comment']){
						$description .= '<br /><b> (Note: '.$product_data['comment'].')</b>';
					}
				}
			}
			
			if($charge->metadata['ord_order_id']){
				array_push($rowvalues, '<a href="/admin/admin_order?ord_order_id='.$charge->metadata['ord_order_id'].'">Order '.$charge->metadata['ord_order_id'].'</a> - ' . $description);
			}
			else if($charge->payment_intent){
				$order = Order::GetByStripePaymentIntent($charge->payment_intent); 
				if($order){
					array_push($rowvalues, '<a href="/admin/admin_order?ord_order_id='.$order->key.'">Order '.$order->key.'</a> - ' . $description);
				}
				else{		
					//TODO NOT WORKING				
					//$payment_intent = $stripe->paymentIntents->retrieve($charge->payment_intent);
					array_push($rowvalues, $description.' <a href="/admin/admin_orders">Details here</a>');
				}			
			}
			else{
				array_push($rowvalues, $description);
			}
			
			
			//array_push($rowvalues, $charge->payment_intent);
			$refund = '';
			if($charge->amount_refunded){
				$refund = ' ($'.$charge->amount_refunded/100 . ' REFUNDED)';
			}
			array_push($rowvalues, '$'.$charge->amount/100 .'.00'. $refund);


			if(strpos($charge->description, 'Integral Zen - Order') !== FALSE){
				array_push($rowvalues, $charge['metadata']['customer_name']);
				array_push($rowvalues, $charge['metadata']['customer_email']);
				$user = User::GetByEmail(strtolower($charge['metadata']['customer_email']));
				if($user){
					$address_id = $user->get_default_address();
					$address = new Address($address_id, TRUE);
					array_push($rowvalues, $address->get_address_string());
				}
				else{
					array_push($rowvalues, '');
				}
			}
			else if($charge->metadata['ord_order_id']){
				array_push($rowvalues, $order_user->display_name());
				array_push($rowvalues,  $order_user->get('usr_email'));
				array_push($rowvalues, $charge->billing_details->address->line1. ' ' . $charge->billing_details->address->line2. ' ' . $charge->billing_details->address->city. ' ' . $charge->billing_details->address->state. ' ' . $charge->billing_details->address->postal_code. ' ' . $charge->billing_details->address->country);				
			}
			else{
				array_push($rowvalues, $charge->billing_details->name);
				array_push($rowvalues, $charge->billing_details->email);
				array_push($rowvalues, $charge->billing_details->address->line1. ' ' . $charge->billing_details->address->line2. ' ' . $charge->billing_details->address->city. ' ' . $charge->billing_details->address->state. ' ' . $charge->billing_details->address->postal_code. ' ' . $charge->billing_details->address->country);

			}				


			//array_push($rowvalues, $charge->created);
			

			

		
			$page->disprow($rowvalues);
		}
	}
	$page->endtable();	
	
	//echo '<a style="text-align:center" href="/admin/admin_stripe_orders?offset='.$offset .'">Next 100 >></a>';


	if(!$_GET['print-format']){
		$page->admin_footer();
	}
?>
