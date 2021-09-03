<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/address_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/product_groups_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/order_items_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/orders_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/products_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();
	
	$settings = Globalvars::get_instance();
	$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')];

	$order_id = LibraryFunctions::fetch_variable('ord_order_id', 0, 0, TRUE);
	$order = new Order($order_id, TRUE);

	if($order->get('ord_usr_user_id')){
		$order_user = new User($order->get('ord_usr_user_id'), TRUE);
	}
	else{
		$order_user = new User(NULL);		
	}

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 4,
		'breadcrumbs' => array(
			'Orders'=>'/admin/admin_orders', 
			'Order '.$order->key => '',
		),
		//'page_title' => 'Event Sessions',
		//'readable_title' => 'Event Sessions',
		'session' => $session,
	)
	);	


	

		$options['title'] = 'Order (' . $order->key . ')';
		if ($_SESSION['permission'] == 10) {
			$options['altlinks']['Delete'] = '/admin/admin_order_delete?ord_order_id=' . $order->key;
			$options['altlinks']['Edit'] = '/admin/admin_order_edit?ord_order_id=' . $order->key;
		}
		$page->begin_box($options);

	echo '<h2>Order Info</h2>';

	echo 'Order ID - '.  $order->key. '<br />';
	echo 'Amount - '. $currency_symbol.$order->get('ord_total_cost'). '<br />';
	if($order->get('ord_refund_amount')){
		echo '<b>'.$currency_symbol.$order->get('ord_refund_amount') . ' refunded.  <br />Last refund: '.LibraryFunctions::convert_time($order->get('ord_refund_time'), "UTC", $session->get_timezone()).'<br />Refund note: '.$order->get('ord_refund_note').'</b>';
	}
	echo '<br />';
	echo 'Order Time - ' . LibraryFunctions::convert_time($order->get('ord_timestamp'), "UTC", $session->get_timezone(), 'M j, Y'). '<br />';
	echo 'User - '.'('. $order_user->key .') <a href="/admin/admin_user?usr_user_id='. $order_user->key .'">' . $order_user->display_name() . '</a> '. '<br />';

	if($_SESSION['permission'] == 10){
		if($order->get('ord_status') == 1){
			echo 'Status - INCOMPLETE';
		}
		else if($order->get('ord_status') == 2){
			echo 'Status - Complete';
		}
		else{
			echo 'Status - Unknown';
		}
	}


	echo '<h2>Items in Order</h2>';
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
			$this_out .= ' (Note: '.$product_data['comment'].')';
		}

		if($order_item->get('odi_refunded')){
			$this_out .= ' REFUNDED $'.$order_item->get('odi_refunded');
		}
		
		if($order_item->get('odi_subscription_cancelled_time')){
			$this_out .= ' CANCELLED AT '.LibraryFunctions::convert_time($order_item->get('odi_subscription_cancelled_time'), 'UTC', $session->get_timezone());	
		}
		
			
		if($_SESSION['permission'] >= 8){
			if(($order->get('ord_stripe_payment_intent_id') || $order->get('ord_stripe_charge_id')) && ($order->get('ord_refund_amount') < $order->get('ord_total_cost'))){
				$this_out .= ' | <a href="/admin/admin_order_refund?oi=' . $order_item->key . '">[refund]</a>';
			}
		}
		
		$this_out .= ' | <a href="/admin/admin_order_item_edit?odi_order_item_id=' . $order_item->key . '">[edit]</a>';
	
		$order_items_out[] = $this_out;

	}
	echo implode('<br />', $order_items_out);


	if($_SESSION['permission'] == 10){
		
		echo "<h2>Raw Cart:</h2><br><pre>";
		echo $order->get('ord_raw_cart'). '</pre>';		
	}


	$page->end_box();
	$page->admin_footer();

?>

