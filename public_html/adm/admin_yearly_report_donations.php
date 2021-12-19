<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/orders_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/products_class.php');

	$PRODUCT_ID_TO_NAME_CACHE = array();

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();
	
	$settings = Globalvars::get_instance();
	$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')];

	$numperpage = 60;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'ord_order_id', 0, '');	
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

	

	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 4,
		'breadcrumbs' => array(
			'Orders'=>'', 
		),
		'page_title' => 'Orders',
		'readable_title' => 'Orders',
		'session' => $session,
	)
	);	

	$formwriter = new FormWriterMaster("form1");
	
	$headers = array('Name', 'Product', 'Total');
	$altlinks = array();
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Orders',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);
	
	$result_array = array();
	
	$users = new MultiUser(array('deleted' => FALSE), array('last_name' => ASC));
	$users->load();
	
	$results = array();
	foreach ($users as $user){
		$total_for_user = 0;
		$results[$user->key][name] = $user->display_name();
		$results[$user->key][email] = $user->get('usr_email');
		$results[$user->key][products] = array();
		
		//$rowvalues = array();
		//array_push($rowvalues, $user->display_name());
		
		$products = new MultiProduct();
		$products->load();
		foreach($products as $product) {
			$product_array = array();
			$product_array[id] = $product->key;
			$product_array[name] = $product->get('pro_name');
			$product_array[amount] = 0;
			$order_items = new MultiOrderItem(array('user_id' => $user->key, 'product_id' => $product->key, 'status' => Order::STATUS_PAID));
			$order_items->load();
			
			foreach ($order_items as $order_item){
				$product_array[amount] += ($order_item->get('odi_price') - $order_item->get('odi_refund_amount'));
			}
			
			
			$results[$user->key][products][] = $product_array;
			$total_for_user += $product_array[amount];
		
		}
		
		$results[$user->key][total] = $total_for_user;
	}

	foreach($results as $result){
		if($result[total] > 0){
			$rowvalues = array();
			array_push($rowvalues, $result[name]);
			$page->disprow($rowvalues);
			foreach($result[products] as $product){
				$rowvalues = array();
				array_push($rowvalues, '');
				array_push($rowvalues, $product[name]);
				array_push($rowvalues, '$'.$product[amount]);
				$page->disprow($rowvalues);				
			}
		
			$rowvalues = array();
			array_push($rowvalues, '');
			array_push($rowvalues, '<b>Total:</b>');
			array_push($rowvalues, '<b>$'.$result[total].'</b>');
			$page->disprow($rowvalues);		
		}		
	}
		
	$page->endtable($pager);		
	
	$page->admin_footer();

?>
