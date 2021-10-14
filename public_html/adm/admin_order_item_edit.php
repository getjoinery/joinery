<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/order_items_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/products_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_registrants_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	if (isset($_REQUEST['odi_order_item_id'])) {
		$order_item = new OrderItem($_REQUEST['odi_order_item_id'], TRUE);
	} else {
		$order_item = new OrderItem(NULL);
	}
	
	$user = new User($order_item->get('odi_usr_user_id'), TRUE);
	
	if($_POST){
		
		$order_item->set('odi_pro_product_id', $_POST['odi_pro_product_id']);
		
		$product = new Product($_POST['odi_pro_product_id'], TRUE);
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
		
		
		$order_item->set('odi_usr_user_id', $_POST['odi_usr_user_id']);
		
		$order_item->prepare();
		$order_item->save();
		$order = $order_item->get_order();
		
		LibraryFunctions::redirect('/admin/admin_order?ord_order_id='.$order->key);
		exit;
	}

	$breadcrumbs = array('Orders'=>'/admin/admin_orders');
	$breadcrumbs += array('Order Item Edit'=>'');


	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 2,
		'page_title' => 'Edit Order Item',
		'readable_title' => 'Edit Order Item',
		'breadcrumbs' => $breadcrumbs,
		'session' => $session,
	)
	);
	
	$pageoptions['title'] = "Edit Order Item";
	$page->begin_box($pageoptions);
	


	// Editing an existing order_item
	$formwriter = new FormWriterMaster('form1');	
	
	
	echo $formwriter->begin_form('form1', 'POST', '/admin/admin_order_item_edit');

	if($order_item->key){
		echo $formwriter->hiddeninput('odi_order_item_id', $order_item->key);
		echo $formwriter->hiddeninput('action', 'edit');
	}


	
	if($order_item->get('odi_usr_user_id')){
		$order_item_user = new User($order_item->get('odi_usr_user_id'), TRUE);
	}
	
	if($order_item->get('odi_evr_event_registrant_id')){
		$event_registrant = new EventRegistrant($order_item->get('odi_evr_event_registrant_id'), TRUE);
		$event = new Event($event_registrant->get('evr_evt_event_id'), TRUE);
		
		echo 'Current event registration:<br>  '. $order_item_user->display_name(). ' - ' . $event->get('evt_name').'<br>';
	}
	

	$users = new MultiUser(array('deleted' => FALSE), array('last_name' => ASC));
	$users->load();
	$optionvals = $users->get_dropdown_array();
	echo $formwriter->dropinput("User", "odi_usr_user_id", "ctrlHolder", $optionvals, $order_item->get('odi_usr_user_id'), '', TRUE, FALSE, '/ajax/user_search_ajax');	 
 	

	//$event_registrants = new MultiEventRegistrant(array('user_id' => $user->key), array('event_id'=> 'DESC'));
	//$event_registrants->load();
	$products = new MultiProduct(array('user_id'=> $user->key));
	$products->load();
	$optionvals = $products->get_dropdown_array();
	echo $formwriter->dropinput("Product purchased", "odi_pro_product_id", "ctrlHolder", $optionvals, $order_item->get('odi_pro_product_id'), '', TRUE);	
	

 
	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();

	echo $formwriter->end_form();


	$page->end_box();

	$page->admin_footer();

?>
