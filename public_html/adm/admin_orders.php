<?php
	
	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/orders_class.php'));
	require_once(PathHelper::getIncludePath('data/products_class.php'));

	$PRODUCT_ID_TO_NAME_CACHE = array();

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();
	
	$settings = Globalvars::get_instance();
	$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')];

	$numperpage = 60;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'timestamp', 0, '');	
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	
	$user_id = LibraryFunctions::fetch_variable('u', NULL, 0, '');
	$startdate = LibraryFunctions::fetch_variable('startdate', NULL, 0, '');	
	$enddate = LibraryFunctions::fetch_variable('enddate', NULL, 0, '');	

	$search_criteria = NULL;
	$search_criteria = array();
	
	if($startdate){
		$display_startdate = $startdate;	
		$time_combined = $startdate . ' ' . LibraryFunctions::toDBTime('12:01:00 am');
		$utc_time = LibraryFunctions::convert_time($time_combined, $session->get_timezone(),  'UTC', 'c');
		$search_criteria['created_after'] = $utc_time;
	}

	if($enddate){
		$display_enddate = $enddate;
		$time_combined = $enddate . ' ' . LibraryFunctions::toDBTime('12:59:59 pm');
		$utc_time = LibraryFunctions::convert_time($time_combined, $session->get_timezone(),  'UTC', 'c');
		$search_criteria['created_before'] = $utc_time;		
	}	

	if($user_id){
		if($user_id){
			$search_criteria['user_id'] = $user_id;
		}
	}
	
	//ONLY SHOW DELETED TO SUPER ADMINS
	if($_SESSION['permission'] < 10){
		//$search_criteria['deleted'] = false;
		$search_criteria['test_mode'] = false;
	}

	$orders = new MultiOrder(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset);
	$numrecords = $orders->count_all();
	$orders->load();
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'orders-list',
		'breadcrumbs' => array(
			'Orders'=>'', 
		),
		'page_title' => 'Orders',
		'readable_title' => 'Orders',
		'session' => $session,
	)
	);	

	$formwriter = $page->getFormWriter('form1', [
		'method' => 'GET',
		'action' => '/admin/admin_orders'
	]);
	$formwriter->begin_form();
	$formwriter->dateinput('startdate', 'Start Date', [
		'value' => $display_startdate
	]);
	$formwriter->dateinput('enddate', 'End Date', [
		'value' => $display_enddate
	]);
	$formwriter->hiddeninput('source', ['value' => 'form']);
	$formwriter->submitbutton('submit_button', 'Submit');
	$formwriter->end_form();	
	
	$headers = array('Order ID', 'User', 'Order Time', 'Products', 'Total');
	$altlinks = array('Sync Invoices' => '/utils/admin_stripe_invoices_synchronize?html-format=1', 'Sync Orders' => '/utils/stripe_charges_synchronize?html-format=1', 'New Order' => '/admin/admin_order_edit');
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Orders',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);
	
	foreach($orders as $order) {
		$rowvalues = array();
		
		if($order->get('ord_usr_user_id')){
			$order_user = new User($order->get('ord_usr_user_id'), TRUE);
		}
		else{
			$order_user = new User(NULL);
		}

		$min_status = NULL;

		$order_items = $order->get_order_items();
		$order_items_out = array();
		foreach($order_items as $order_item) {
			$product_data = $order_item->get_data();
			
			if($order_item->get('odi_usr_user_id')){
				$order_item_user = new User($order_item->get('odi_usr_user_id'), TRUE);
				$address_id = $order_item_user->get_default_address();
			}

			if (array_key_exists($order_item->get('odi_pro_product_id'), $PRODUCT_ID_TO_NAME_CACHE)) {
				$title = $PRODUCT_ID_TO_NAME_CACHE[$order_item->get('odi_pro_product_id')];
			} else {
				$product = new Product($order_item->get('odi_pro_product_id'), TRUE);
				$title = $product->get('pro_name');
				$PRODUCT_ID_TO_NAME_CACHE[$product->key] = $title;
			}
			
			if($order_item->get('odi_usr_user_id')){
				$this_out = $title . ' - <a href="/admin/admin_user?usr_user_id=' . $order_item_user->key . '">' . $order_item_user->display_name() . '</a>';
				if($address_id){
					$address = new Address($address_id, TRUE);
					$this_out .= ' '.$address->get('usa_city') . ', '.$address->get('usa_zip_code_id'). ' ' . Address::GetCountryAbbrFromCountryCode($address->get('usa_cco_country_code_id'));
				}
				$this_out .= ' ('.$currency_symbol. $order_item->get('odi_price') .')';
			}
			else{
				$this_out = $title . ' ('.$currency_symbol. $order_item->get('odi_price') .')';
			}
			
			if($product_data['comment']){
				$this_out .= '<br /><b> (Note: '.$product_data['comment'].')</b>';
			}

			$order_items_out[] = $this_out;

		}
		
		if($order->get('ord_error')){
			$order_items_out[] = '<b>ERROR - '.$order->get('ord_error').'</b>';
		}
		else if($order->get('ord_test_mode')){
			$order_items_out[] = '<b>TEST TRANSACTION</b>';
		}

		array_push($rowvalues,'<a href="/admin/admin_order?ord_order_id='.$order->key.'">Order '.$order->key.'</a>');

		array_push($rowvalues, '<a href="/admin/admin_user?usr_user_id=' . $order_user->key . '">' . $order_user->display_name() . '</a>');

		array_push($rowvalues,  LibraryFunctions::convert_time($order->get('ord_timestamp'), "UTC", $session->get_timezone(), 'M j, Y'));
		array_push($rowvalues, implode('<br>',$order_items_out));
		
		$refund_text = '';
		if($order->get('ord_refund_amount')){
			$refund_text = ' *'.$currency_symbol.$order->get('ord_refund_amount') .' refunded*';
		}
		
		if($order->get('ord_status') == 1){
			array_push($rowvalues, 'NOT BILLED');
		}
		else{
			array_push($rowvalues, $currency_symbol.$order->get('ord_total_cost') .$refund_text);
		}
		
		$page->disprow($rowvalues);

	}
	$page->endtable($pager);		
	
	$page->admin_footer();

?>
