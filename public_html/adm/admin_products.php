<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/products_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/order_items_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();


	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'products-list',
		'page_title' => 'Products',
		'readable_title' => 'Products',
		'breadcrumbs' => array(
			'Products'=>'', 
		),
		'session' => $session,
	)
	);
	
		$numperpage = 30;
		$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
		$sort = LibraryFunctions::fetch_variable('sort', 'product_id', 0, '');
		$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
		$search_criteria = array();

		$products = new MultiProduct(
			$search_criteria,
			array($sort=>$sdirection),
			$numperpage,
			$offset,
			'AND'
		);
		$numrecords = $products->count_all();
		$products->load();	
			
		$headers = array('Product', 'Event', 'Active', 'Orders', 'Revenue');
		if($_SESSION['permission'] >= 8){
			$altlinks = array('New Product'=>'/admin/admin_product_edit');
		}
		else{
			$altlinks = array();
		}
		$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
		$table_options = array(
			//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
			'altlinks' => $altlinks,
			'title' => 'Products',
			//'search_on' => TRUE
		);
		$page->tableheader($headers, $table_options, $pager);

		foreach($products as $product) {
			$order_items = new MultiOrderItem(array('product_id' => $product->get('pro_product_id'), 'status' => OrderItem::STATUS_PAID));
			$order_items->load();
			$product_order_count = count($order_items);
			$product_rev = array_sum($order_items->get_prices());
	
			if($product->get('pro_evt_event_id')){
				$event = new Event($product->get('pro_evt_event_id'), TRUE);
				$event_name = '';
				if($event->get('evt_start_time')){
					$event_name = '('.LibraryFunctions::convert_time($event->get('evt_start_time'), "UTC", "UTC", 'M j, Y'). ') ';
				}
				$event_name .= '<a href="/admin/admin_event?evt_event_id='.$event->key.'">'.$event->get('evt_name').'</a>';
			}
			else{
				$event_name = '';
			}
	
			/*
			if($product->get('pro_prg_product_group_id')){
				$product_group = new ProductGroup($product->get('pro_prg_product_group_id'), TRUE);
				$pname = $product_group->get('prg_name');
			}
			else{
				$pname = 'No Product Group';
			}
			*/
			
			if($product->get('pro_is_active')){
				$active = 'Active';
			}
			else{
				$active = 'Disabled';
			}
			
			if($_SESSION['permission'] >7){
				$editlink = '<a href="/admin/admin_product?pro_product_id=' . $product->get('pro_product_id') . '">'.$product->get('pro_name').'</a>';
			}
			else{
				$editlink = $product->get('pro_name');
			}
			
			$page->disprow(array(
				'('.$product->get('pro_product_id').') ' . $editlink ,
				$event_name,
				$active,
				$product_order_count ?: '<strong>0</strong>',
				'$' . $product_rev ?: '0'
			));
			//$total_orders += $product_order_count;
			//$total_revenue += $product_rev;
		}
		//$page->disprow(array(
		//	'Totals', '', $total_orders, '$' . $total_revenue));
		$page->endtable($pager);		
		


	$page->admin_footer();

?>
