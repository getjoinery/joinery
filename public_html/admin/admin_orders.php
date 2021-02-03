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

	$numperpage = 60;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'ord_order_id', 0, '');	
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	
	$user_id = LibraryFunctions::fetch_variable('u', NULL, 0, '');
	
	$search_criteria = NULL;
	if($user_id){
		$search_criteria = array();
		if($user_id){
			$search_criteria['user_id'] = $user_id;
		}
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
		'menu-id'=> 4,
		'breadcrumbs' => array(
			'Orders'=>'', 
		),
		//'page_title' => 'Event Sessions',
		//'readable_title' => 'Event Sessions',
		'session' => $session,
	)
	);	

	
	$headers = array('Order ID', 'User', 'Order Time', 'Products', 'Total');
	$altlinks = array();
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
			}

			if (array_key_exists($order_item->get('odi_pro_product_id'), $PRODUCT_ID_TO_NAME_CACHE)) {
				$title = $PRODUCT_ID_TO_NAME_CACHE[$order_item->get('odi_pro_product_id')];
			} else {
				$product = new Product($order_item->get('odi_pro_product_id'), TRUE);
				$title = $product->get('pro_name');
				$PRODUCT_ID_TO_NAME_CACHE[$product->key] = $title;
			}
			
			if($order_item->get('odi_usr_user_id')){
				$this_out = $title . ' - <a href="/admin/admin_user?usr_user_id=' . $order_item_user->key . '">' . $order_item_user->display_name() . '</a>' . ' ($'. $order_item->get('odi_price') .')';
			}
			else{
				$this_out = $title . ' ($'. $order_item->get('odi_price') .')';
			}
			
			if($product_data['comment']){
				$this_out .= '<br /><b> (Note: '.$product_data['comment'].')<b>';
			}

			
			$order_items_out[] = $this_out;

		}

		array_push($rowvalues,'<a href="/admin/admin_order?ord_order_id='.$order->key.'">Order '.$order->key.'</a>');

		
		array_push($rowvalues, '<a href="/admin/admin_user?usr_user_id=' . $order_user->key . '">' . $order_user->display_name() . '</a>');

		array_push($rowvalues,  LibraryFunctions::convert_time($order->get('ord_timestamp'), "UTC", $session->get_timezone(), 'M j, Y'));
		array_push($rowvalues, implode($order_items_out, '<br>'));
		if($order->get('ord_status') == 1){
			array_push($rowvalues, 'NOT BILLED');
		}
		else{
			array_push($rowvalues, '$'.$order->get('ord_total_cost'));
		}
		
		$page->disprow($rowvalues);
		if($order->get('ord_error')){
			$rowvalues = array();
			array_push($rowvalues, '<b>Order: '.$order->key.' error - '.$order->get('ord_error').'</b>');
			$page->disprow($rowvalues);
		}
	}
	$page->endtable($pager);		
	
	$page->admin_footer();

?>
