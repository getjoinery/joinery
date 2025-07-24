<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	PathHelper::requireOnce('/includes/AdminPage.php');
	
	PathHelper::requireOnce('/includes/LibraryFunctions.php');

	PathHelper::requireOnce('/data/orders_class.php');
	PathHelper::requireOnce('/data/orders_class.php');
	PathHelper::requireOnce('/data/products_class.php');


	$session = SessionControl::get_instance();
	$session->check_permission(8);

	if (isset($_REQUEST['ord_order_id'])) {
		$order = new Order($_REQUEST['ord_order_id'], TRUE);
	} else {
		$order = new Order(NULL);
	}
	
	
	if($_POST){

		$order->set('ord_usr_user_id', $_POST['ord_usr_user_id']);
		
		if(!$order->key || !$order->is_stripe_order()){
			$order->set('ord_total_cost', $_POST['ord_total_cost']);
			$order->set('ord_status', Order::STATUS_PAID);

			if($_POST['ord_timestamp_date'] && $_POST['ord_timestamp_time']){
				$time_combined = $_POST['ord_timestamp_date'] . ' ' . LibraryFunctions::toDBTime($_POST['ord_timestamp_time']);
				$utc_time = LibraryFunctions::convert_time($time_combined, $session->get_timezone(),  'UTC', 'c');
				$order->set('ord_timestamp', $utc_time);
				//$event->set('evt_start_time_local', $time_combined);
			}
		}
		
		$order->prepare();
		$order->save();
		
		LibraryFunctions::redirect('/admin/admin_order?ord_order_id='.$order->key);
		return;
	}

	$breadcrumbs = array('Orders'=>'/admin/admin_orders');
	$breadcrumbs += array('Order Edit'=>'');


	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'orders-list',
		'page_title' => 'Edit Order',
		'readable_title' => 'Edit Order',
		'breadcrumbs' => $breadcrumbs,
		'session' => $session,
	)
	);
	
	$pageoptions['title'] = "Edit Order";
	$page->begin_box($pageoptions);
	


	// Editing an existing order
	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');	
	
	
	echo $formwriter->begin_form('form1', 'POST', '/admin/admin_order_edit');

	if($order->key){
		echo $formwriter->hiddeninput('ord_order_id', $order->key);
		echo $formwriter->hiddeninput('action', 'edit');
	}

	
	if($order->get('ord_usr_user_id')){
		$order_user = new User($order->get('ord_usr_user_id'), TRUE);
	}
	
	$users = new MultiUser(array('deleted' => FALSE), array('last_name' => 'ASC'));
	$users->load();
	$optionvals = $users->get_dropdown_array();
	
	echo $formwriter->dropinput("Billing User", "ord_usr_user_id", "ctrlHolder", $optionvals, $order_user->key, '', TRUE, FALSE, '/ajax/user_search_ajax');	

	//ALLOW THESE OTHER FIELDS IF IT IS A NEW ORDER OR NOT A STRIPE ORDER
	if(!$order->key || !$order->is_stripe_order()){
		echo $formwriter->textinput('Order total', 'ord_total_cost', NULL, 100, $order->get('ord_total_cost'), '', 255, '');

		echo $formwriter->datetimeinput('Order time', 'ord_timestamp', 'ctrlHolder', LibraryFunctions::convert_time($order->get('ord_timestamp'), 'UTC', $session->get_timezone(), 'Y-m-d h:ia'), '', '', '');	
	}		

 
	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();

	echo $formwriter->end_form();


	$page->end_box();

	$page->admin_footer();

?>
