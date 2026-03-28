<?php
	error_reporting(E_ERROR | E_PARSE);
	require_once( __DIR__ . '/../includes/Globalvars.php');
	require_once( __DIR__ . '/../includes/AdminPage.php');
	require_once( __DIR__ . '/../includes/LibraryFunctions.php');
	require_once( __DIR__ . '/../includes/StripeHelper.php');


	require_once( __DIR__ . '/../data/stripe_invoices_class.php');
	require_once( __DIR__ . '/../data/orders_class.php');
	require_once( __DIR__ . '/../data/users_class.php');
	require_once( __DIR__ . '/../data/event_logs_class.php');
	
	$event_log = new EventLog(NULL);
	$event_log->set('evl_event', 'stripe_invoice_synchronize');
	$event_log->set('evl_usr_user_id', User::USER_SYSTEM);
	$event_log->save();
	$event_log->load();
	
	$settings = Globalvars::get_instance();

	$stripe_helper = new StripeHelper();
	
	$numperpage = 100;
	$currpage = LibraryFunctions::fetch_variable('currpage', 1, 0, '');
	$nextpage = $currpage + 1;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$startdate = LibraryFunctions::fetch_variable('startdate', NULL, 0, '');	
	$enddate = LibraryFunctions::fetch_variable('enddate', NULL, 0, '');
	$verbose = LibraryFunctions::fetch_variable('verbose', NULL, 0, '');	
	
	if($startdate){
		$display_startdate = $startdate;	
		$startdate = strtotime($startdate . '00:00:01');
	}
	else{
		//DEFAULT
		if($_GET['html-format']){
			$startdate = strtotime('-1 month',time());
		}
		else{
			$startdate = strtotime('-1 year',time());
		}
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
		$stripe_invoices = $stripe_helper->get_invoices(['limit' => $numperpage, 'starting_after' => $offset, 'created' => $created, 'status' => 'paid']);
	}
	else{
		$stripe_invoices = $stripe_helper->get_invoices(['limit' => $numperpage, 'created' => $created, 'status' => 'paid']);
	}


	
	if($_GET['html-format']){
		$session = SessionControl::get_instance();
		$page = new AdminPage();
		$page->admin_header(
		array(
			'menu-id'=> 'stripe-payments',
			'page_title' => 'Stripe invoices',
			'readable_title' => 'Stripe invoices',
		'breadcrumbs' => array(
			'Orders'=>'/admin/admin_orders', 
			'Stripe Invoices' => '',
		),
			'session' => $session,
		)
		);	
				
		

	
		$formwriter = $page->getFormWriter('form1', ['action' => '/utils/admin_stripe_invoices_synchronize', 'method' => 'GET']);
		$formwriter->begin_form();
		$formwriter->dateinput("startdate", "Start Date", ['value' => $display_startdate]);
		$formwriter->dateinput("enddate", "End Date", ['value' => $display_enddate]);
		$formwriter->hiddeninput('source', 'form');
		$formwriter->submitbutton('btn_submit', 'Submit');
		$formwriter->end_form();

		if($verbose){
			$headers = array('ID', 'Customer', 'Amount', 'Subscription', 'Time', 'Description', 'Sync');
			//$altlinks = array('Print format' => '/admin/admin_stripe_orders?print-format=true&startdate='.$display_startdate.'&enddate='.$display_enddate);
			$altlinks = array();
			$box_vars =	array(
				'altlinks' => $altlinks,
				'title' => "Stripe invoices"
			);
			$page->tableheader($headers, $box_vars);
		}
		else{
			$pageoptions['title'] = 'Stripe invoices synchronize';
			$page->begin_box($pageoptions);
		}
	}

	$num_processed = 0;
	$pagenum = 1;
	$stripe_invoicenum = 0;
	//SAFEGUARD, ONLY RUN 50 PAGES
	while($pagenum <= 50){
		echo '<b>Page '.$pagenum.', Count: '.count($stripe_invoices['data']).'</b><br>';
		$pagenum++;
			
		foreach($stripe_invoices as $stripe_invoice) {
			$stripe_invoicenum++;
			if($verbose){
				$rowvalues = array();
				array_push($rowvalues, '('.$stripe_invoicenum . ') '.$stripe_invoice->id);
				array_push($rowvalues, $stripe_invoice->customer_email);	
				array_push($rowvalues, $stripe_invoice->amount_paid/100);
				array_push($rowvalues, $stripe_invoice->subscription);
				array_push($rowvalues, gmdate("c", $stripe_invoice->created));  //or "Y-m-d"
				array_push($rowvalues, $stripe_invoice->description);
			}
			else{
				echo "Processing invoice ". $stripe_invoicenum."...<br>\n";
			}

			
			$existing_invoice = new MultiStripeInvoice(array('stripe_foreign_invoice_id' => $stripe_invoice->id));
			if(!$existing_invoice->count_all()){
					
				$invoice = new StripeInvoice(NULL);
				$invoice->set('siv_stripe_foreign_invoice_id', $stripe_invoice->id);
				$invoice->set('siv_amount_paid', $stripe_invoice->amount_paid/100);
				$invoice->set('siv_stripe_subscription_id', $stripe_invoice->subscription);
				$invoice->set('siv_timestamp', gmdate("c", $stripe_invoice->created));
				$invoice->set('siv_description', $stripe_invoice->description);
				$invoice->set('siv_stripe_charge_id', $stripe_invoice->charge);
				$invoice->set('siv_stripe_payment_intent_id', $stripe_invoice->payment_intent);

				// Flag refunded invoices in the description
				if ($stripe_invoice->status === 'void' || $stripe_invoice->status === 'uncollectible') {
					$invoice->set('siv_description', ($stripe_invoice->description ?: '') . ' [' . strtoupper($stripe_invoice->status) . ']');
				} else if ($stripe_invoice->amount_paid == 0 && $stripe_invoice->total > 0) {
					$invoice->set('siv_description', ($stripe_invoice->description ?: '') . ' [REFUNDED]');
				}

				//FIND THE USER.  TRY SUBSCRIPTION ID FIRST, THEN TRY EMAIL
				$found=0;
				if($stripe_invoice->subscription){
					$order_items = new MultiOrderItem(array('stripe_subscription_id' => $stripe_invoice->subscription));
					$order_items->load();
					$count = $order_items->count_all();
					if($count){
						$order_item = $order_items->get(0);
						$found=1;
						$invoice->set('siv_usr_user_id', $order_item->get('odi_usr_user_id'));
					}
					
				}
				
				if($found == 0){
					$user = User::GetByEmail($stripe_invoice->customer_email);
					if($user){
						$found = 1;
						$invoice->set('siv_usr_user_id', $user->key);
					}
				}
				
				if($found == 0){
					//COULD NOT FIND THE USER FOR THE INVOICE.  JUST STORE IT WITHOUT A USER
				}
				
				$invoice->prepare();
				$invoice->save();
				if($verbose){
					array_push($rowvalues, 'saved');
				}
			}
			else{
				if($verbose){
					array_push($rowvalues, 'skipped');
				}
			}
			if($_GET['html-format']){
				$page->disprow($rowvalues);
			}
			else{
				print_r($rowvalues);
			}
			$offset = $stripe_invoice->id;
			$num_processed++;
		}
		
		if($stripe_invoices->has_more){
			$created = array();
			$created['gte'] = $startdate;
			$created['lte'] = $enddate;
			$stripe_invoices = $stripe_helper->get_invoices(['limit' => $numperpage, 'starting_after' => $offset, 'created' => $created]);
		}
		else{
			break;
		}
	}

	if($_GET['html-format']){
		if($verbose){
			$page->endtable();	
		}
		else{
			echo "<p>Invoices updated.  <a href=\"/admin/admin_orders\">Return to orders</a></p>\n";
			$page->end_box();
		}

		$page->admin_footer();
	}
	else{
		echo "Invoices updated.\n";
	}
	
	$event_log->set('evl_was_success', 1);
	$event_log->set('evl_note', 'Invoices processed: '.$num_processed);
	$event_log->save();	
?>
