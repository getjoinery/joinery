<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/includes/FulfillmentRegistry.php'));
	require_once(PathHelper::getIncludePath('plugins/store/admin/logic/admin_products_logic.php'));

	$page_vars = process_logic(admin_products_logic(array_merge($_GET, $_POST)));
	extract($page_vars);

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'products-list',
		'page_title' => 'Products',
		'readable_title' => 'Products',
		'breadcrumbs' => $breadcrumb_array,
		'session' => $session,
	)
	);

		$headers = array('Product', 'Event', 'Active', 'Orders', 'Revenue');
		if($_SESSION['permission'] >= 8){
			$altlinks = array('New Product'=>'/plugins/store/admin/admin_product_edit');
		}
		else{
			$altlinks = array();
		}
		$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
		$table_options = array(
			'sortoptions'=>array("Product ID"=>"product_id", "Product Name"=>"name"),
			'filteroptions'=>array("Active"=>"active", "All Products"=>"all"),
			'altlinks' => $altlinks,
			'title' => 'Products',
			'search_on' => TRUE
		);
		$page->tableheader($headers, $table_options, $pager);

		foreach($products as $product) {

			$deleted_status = '';
			if($product->get('pro_delete_time')) {
				$deleted_status = ' DELETED ';
			}

			$order_items = new MultiOrderItem(array('product_id' => $product->get('pro_product_id'), 'status' => OrderItem::STATUS_PAID));
			$order_items->load();
			$product_order_count = count($order_items);
			$product_rev = array_sum($order_items->get_prices());

			$event_name = '';
			if($product->get('pro_fulfillment_provider')){
				$fp = FulfillmentRegistry::get($product->get('pro_fulfillment_provider'));
				if($fp){
					$event_name = $fp->displayReference((int)$product->get('pro_fulfillment_ref'));
				}
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
				$editlink = '<a href="/plugins/store/admin/admin_product?pro_product_id=' . $product->get('pro_product_id') . '">'.$product->get('pro_name').'</a>';
			}
			else{
				$editlink = $product->get('pro_name');
			}

			$page->disprow(array(
				$editlink. $deleted_status ,
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
