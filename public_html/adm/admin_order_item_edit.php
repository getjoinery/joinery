<?php
	
	require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));
	
	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('/data/order_items_class.php'));
	require_once(PathHelper::getIncludePath('/data/orders_class.php'));
	require_once(PathHelper::getIncludePath('/data/products_class.php'));
	require_once(PathHelper::getIncludePath('/data/event_registrants_class.php'));
	require_once(PathHelper::getIncludePath('/data/events_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	// FormWriter V2 uses 'edit_primary_key_value' for the primary key hidden field
	// Check POST first (form submission), then GET (initial page load)
	$item_id = isset($_POST['edit_primary_key_value']) ? $_POST['edit_primary_key_value'] : (isset($_GET['odi_order_item_id']) ? $_GET['odi_order_item_id'] : NULL);

	if ($item_id) {
		$order_item = new OrderItem($item_id, TRUE);
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
			// Editing existing order item - preserve the order ID relationship
			if(isset($_POST['odi_price'])){
				$order_item->set('odi_price', $_POST['odi_price']);
			}
			// Ensure odi_ord_order_id is preserved (not in form but required in DB)
			if(!$order_item->get('odi_ord_order_id')){
				$order_item->set('odi_ord_order_id', $order->key);
			}
		}
		else{
			// Creating new order item
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
		return;
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

	$formwriter = $page->getFormWriter('form1', 'v2', [
		'model' => $order_item,
		'edit_primary_key_value' => $order_item->key
	]);

	$formwriter->begin_form();

	// Always pass order ID (required for new items, helpful for existing)
	$order_id_value = $order_item->key ? $order->key : (isset($_GET['ord_order_id']) ? $_GET['ord_order_id'] : $order->key);
	$formwriter->hiddeninput('ord_order_id', ['value' => $order_id_value]);

	if(!$order_item->key || !$order->is_stripe_order()){
		$validation_opts = [];
		if(!$order_item->key || !$order->is_stripe_order()){
			$validation_opts['validation'] = ['required' => true];
		}
		$formwriter->textinput('odi_price', 'Price', $validation_opts);
	}

	if($order_item->get('odi_usr_user_id')){
		$order_item_user = new User($order_item->get('odi_usr_user_id'), TRUE);
	}
	$users = new MultiUser(array('deleted' => FALSE), array('last_name' => 'ASC'));
	$users->load();
	$optionvals = $users->get_dropdown_array();
	$formwriter->dropinput('odi_usr_user_id', 'User', [
		'options' => $optionvals,
		'ajaxendpoint' => '/ajax/user_search_ajax',
		'validation' => ['required' => true]
	]);

	$products = new MultiProduct(array('user_id'=> $user->key));
	$products->load();
	$optionvals = $products->get_dropdown_array();
	$formwriter->dropinput('odi_pro_product_id', 'Product purchased', [
		'options' => $optionvals,
		'validation' => ['required' => true]
	]);

	$event_registrants = new MultiEventRegistrant(array('user_id' => $order_item->get('odi_usr_user_id')), array('event_id'=> 'DESC'));
	$num_events = $event_registrants->count_all();
	if($num_events){
		$event_registrants->load();
		$optionvals = $event_registrants->get_dropdown_array();
		$formwriter->dropinput('odi_evr_event_registrant_id', 'Event registration', [
			'options' => $optionvals
		]);
	}

	$formwriter->textinput('odi_comment', 'Comment/note');

	$formwriter->submitbutton('submit_button', 'Submit');

	$formwriter->end_form();

	$page->end_box();

	$page->admin_footer();

?>
