<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/order_items_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/orders_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/products_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_registrants_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	if (isset($_REQUEST['odi_order_item_id'])) {
		$order_item = new OrderItem($_REQUEST['odi_order_item_id'], TRUE);
		$order = new Order($order_item->get('odi_ord_order_id'), TRUE);
		$user = new User($order_item->get('odi_usr_user_id'), TRUE);
	} 
	else {
		$order_id = LibraryFunctions::fetch_variable('ord_order_id', NULL,1,'You must pass an order id');
		$order_item = new OrderItem(NULL);
		$order = new Order($order_id, TRUE);
	}
	
	
	
	if($_POST){
		
		if($order_item->key){
			$order_item->set('odi_price', $_POST['odi_price']);	
		}
		else{
			$order_item->set('odi_price', $_POST['odi_price']);	
			$order_item->set('odi_status', OrderItem::STATUS_PAID);
			$order_item->set('odi_status_change_time', 'now()');
			$order_item->set('odi_ord_order_id', $order->key);	
		}
		
		$order_item->set('odi_usr_user_id', $_POST['odi_usr_user_id']);
		$user = new User($_POST['odi_usr_user_id'], TRUE);
		
		$order_item->set('odi_pro_product_id', $_POST['odi_pro_product_id']);
		
		
		$product = new Product($_POST['odi_pro_product_id'], TRUE);
		//IF PRODUCT IS AN EVENT
		if($product->get('pro_evt_event_id')){
			$event_registrant = EventRegistrant::check_if_registrant_exists($user->key, $product->get('pro_evt_event_id'));
			if($event_registrant){
				//CHANGE THE EXISTING ONE
				$order_item->set('odi_evr_event_registrant_id', $event_registrant->key);
			}
			else{
				//CREATE AN EVENT REGISTRANT IF DOESN'T EXIST
				$event = new Event($product->get('pro_evt_event_id'), TRUE);
				$event_registrant = $event->add_registrant($user->key);
				$order_item->set('odi_evr_event_registrant_id', $event_registrant->key);			
			} 	
		}
		else{
			//JUST REMOVE THE EVENT REGISTRANT REFERENCE HERE
			$order_item->set('odi_evr_event_registrant_id', NULL);
		}

		$order_item->set('odi_comment', $_POST['odi_comment']);	
		
		$order_item->prepare();
		$order_item->save();
		
		LibraryFunctions::redirect('/admin/admin_order?ord_order_id='.$order->key);
		exit;
	}

	$breadcrumbs = array('Orders'=>'/admin/admin_orders');
	$breadcrumbs += array('Order Item Edit'=>'');


	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'orders-list',
		'page_title' => 'Edit Order Item',
		'readable_title' => 'Edit Order Item',
		'breadcrumbs' => $breadcrumbs,
		'session' => $session,
	)
	);
	
	$pageoptions['title'] = "Edit Order Item";
	$page->begin_box($pageoptions);
	

	$formwriter = new FormWriterMaster('form1');

	$validation_rules = array();
	if(!$order_item->key || !$order->is_stripe_order()){
		$validation_rules['odi_price']['required']['value'] = 'true';
	}
	$validation_rules['odi_usr_user_id']['required']['value'] = 'true';
	$validation_rules['odi_pro_product_id']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);	
	
	echo $formwriter->begin_form('form1', 'POST', '/admin/admin_order_item_edit');

	if($order_item->key){
		echo $formwriter->hiddeninput('odi_order_item_id', $order_item->key);
		echo $formwriter->hiddeninput('action', 'edit');
	}
	else{
		echo $formwriter->hiddeninput('ord_order_id', $_GET['ord_order_id']);
	}


	echo $formwriter->textinput('Price', 'odi_price', NULL, 100, $order_item->get('odi_price'), '', 255, '');
	
	if($order_item->get('odi_usr_user_id')){
		$order_item_user = new User($order_item->get('odi_usr_user_id'), TRUE);
	}
	$users = new MultiUser(array('deleted' => FALSE), array('last_name' => ASC));
	$users->load();
	$optionvals = $users->get_dropdown_array();
	echo $formwriter->dropinput("User", "odi_usr_user_id", "ctrlHolder", $optionvals, $order_item->get('odi_usr_user_id'), '', TRUE, FALSE, '/ajax/user_search_ajax');	 
 	

	$products = new MultiProduct(array('user_id'=> $user->key));
	$products->load();
	$optionvals = $products->get_dropdown_array();
	echo $formwriter->dropinput("Product purchased", "odi_pro_product_id", "ctrlHolder", $optionvals, $order_item->get('odi_pro_product_id'), '', TRUE);	


	$event_registrants = new MultiEventRegistrant(array('user_id' => $order_item->get('odi_usr_user_id')), array('event_id'=> 'DESC'));
	$num_events = $event_registrants->count_all();
	if($num_events){
		$event_registrants->load();	
		$optionvals = $event_registrants->get_dropdown_array();
		echo $formwriter->dropinput("Event registration", "odi_evr_event_registrant_id", "ctrlHolder", $optionvals, $order_item->get('odi_evr_event_registrant_id'), '', TRUE);			
	}
			

	
	echo $formwriter->textinput('Comment/note', 'odi_comment', NULL, 100, $order_item->get('odi_comment'), '', 255, '');

 
	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();

	echo $formwriter->end_form();


	$page->end_box();

	$page->admin_footer();

?>
