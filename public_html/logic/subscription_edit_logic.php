<?php
function subscription_edit_logic($get_vars, $post_vars){
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Pager.php');

	require_once($_SERVER['DOCUMENT_ROOT'].'/data/products_class.php');

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;


	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	$pricing_active = $settings->get_setting('pricing_page');
	if(!$pricing_active){
		//TURNED OFF
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();			
	}


	if($_GET['new_plan']){
		$new_plan = LibraryFunctions::fetch_variable_local($get_vars, 'new_plan', 0, 'notrequired', '', 'safemode', 'int');
		$page_vars['order_item_id'] = $order_item_id;
		$product = new Product($new_plan, TRUE);
		$page_vars['product'] = $product;
		
	}
	else if(isset($_POST['product_id'])){
		$product_id = LibraryFunctions::fetch_variable_local($post_vars, 'product_id', 0, 'notrequired', '', 'safemode', 'int');

		//SUBSCRIPTIONS
		$subscriptions = new MultiOrderItem(
		array('user_id' => $user->key, 'is_active_subscription' => true), //SEARCH CRITERIA
		array('order_item_id' => 'DESC'),  // SORT, SORT DIRECTION
		5, //NUMBER PER PAGE
		NULL //OFFSET
		);
		$subscriptions->load();	
		$order_item = $subscriptions->get(0);


		
		$product = new Product($product_id, TRUE);
		//$order_item = new OrderItem($order_item_id, TRUE);
		$subscription_id = $order_item->get('odi_stripe_subscription_id');

		$product_version = $product->get_product_version(array('product_version' => $_POST['product_version']));
		$price = $product->get_price($product_version, $_POST);
		$user = new User($session->get_user_id(), TRUE);


		//CHECK THE PERMISSIONS
		$order_item->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));

		$stripe_helper = new StripeHelper();
		//THIS PLAN CHANGE ONLY WORKS WITH SUBSCRIPTIONS AND DOES NOT WORK WITH TRIAL PERIODS
		//$plan = $stripe_helper->get_or_create_subscription_plan($price, 1, 0);
		$stripe_price = $stripe_helper->get_or_create_price($product, $price);	
		$new_price_id = $stripe_price['id']; 

		// Retrieve the subscription
		$subscription = $stripe_helper->get_subscription($subscription_id);

		// Find the subscription item to update
		$item_id_to_update = $subscription->items->data[0]->id; // Assuming you want to update the first item

		
		$subscription_result = $stripe_helper->change_subscription($subscription_id, $item_id_to_update, $new_price_id);



		//CANCEL THE OLD ONE
		$order_item->set('odi_subscription_cancelled_time', 'now()');
		$order_item->save();
		
		
		//CREATE A NEW ORDER
		$order = new Order(NULL);
		if($_SESSION['test_mode'] || $settings->get_setting('debug')){
			$order->set('ord_test_mode', true);
		}
		$order->set('ord_usr_user_id', $user->key);
		$order->set('ord_total_cost', $price);
		$order->set('ord_timestamp', 'now()');		
		$order->set('ord_status', Order::STATUS_PAID);
		$order->prepare();	
		$order->save();
		$order->load();		

		$order_item = new OrderItem(NULL);
		$order_item->set('odi_ord_order_id', $order->key);
		$order_item->set('odi_pro_product_id', $product->key);
		$order_item->set('odi_usr_user_id', $user->key);
		//$order_item->set('odi_product_info', base64_encode(serialize($data)));
		$order_item->set('odi_price', $price);	
		if ($product_version) {
			$order_item->set('odi_prv_product_version_id', $product_version->prv_product_version_id);
		}			
		$order_item->set('odi_status', OrderItem::STATUS_PAID);
		$order_item->set('odi_status_change_time', 'now()');
		$order_item->set('odi_is_subscription', true);
		$order_item->set('odi_stripe_subscription_id', $subscription_result['id']);
		$order_item->set('odi_subscription_status', 'active');
		$order_item->save();

		//RUN THE PRODUCT SCRIPTS
		$product->run_product_scripts($user, $order_item);

			
		LibraryFunctions::redirect('/profile');
		exit;

	}

	
	$sort = 'plan_order_month';
	$sdirection = 'ASC';
	
	
	$searches = array();
	$page_choice = $get_vars['page'];

	if($page_choice == 'year'){
		$page_vars['page_choice'] = 'year';
		$searches['is_yearly_plan'] = TRUE;
	}
	else{
		$page_vars['page_choice'] = 'month';
		$searches['is_monthly_plan'] = TRUE;
		
	}
	
	$searches['is_active'] = TRUE;
	$searches['deleted'] = FALSE;
	$searches['in_stock'] = true;	

	$products = new MultiProduct(
		$searches,
		array($sort=>$sdirection),
		10,
		0,
		'AND');
	$products->load();
	$page_vars['products'] = $products;
	$numrecords = $products->count_all();		
	$page_vars['numrecords'] = $numrecords;


	
	$page_vars['currency_symbol'] = Product::$currency_symbols[$settings->get_setting('site_currency')]; 
	
	//$page_vars['pager'] = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	
	return $page_vars;
}
?>

