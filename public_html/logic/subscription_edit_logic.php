<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function subscription_edit_logic($get_vars, $post_vars){
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	PathHelper::requireOnce('includes/Pager.php');

	PathHelper::requireOnce('data/products_class.php');
	PathHelper::requireOnce('plugins/controld/data/ctldaccounts_class.php');

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


	if($_GET['new_version']){
		$new_version = LibraryFunctions::fetch_variable_local($get_vars, 'new_version', 0, 'notrequired', '', 'safemode', 'int');
		$page_vars['order_item_id'] = $order_item_id;
		$product_version = new ProductVersion($new_version, TRUE);
		$product = new Product($product_version->get('prv_pro_product_id'), TRUE);
		$page_vars['product'] = $product;
		
	}
	else if(isset($_POST['product_id'])){
		$product_id = LibraryFunctions::fetch_variable_local($post_vars, 'product_id', 0, 'notrequired', '', 'safemode', 'int');
		$user = new User($session->get_user_id(), TRUE);
		$order_item = CtldAccount::GetPlanOrderItem($user->key);

		$product = new Product($product_id, TRUE);
		$subscription_id = $order_item->get('odi_stripe_subscription_id');

		$product_version = $product->get_product_versions(TRUE, $_POST['product_version']);
		$price = $product->get_price($product_version, $_POST);
		


		//CHECK THE PERMISSIONS
		$order_item->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));

		$stripe_helper = new StripeHelper();
		//THIS PLAN CHANGE ONLY WORKS WITH SUBSCRIPTIONS AND DOES NOT WORK WITH TRIAL PERIODS
		$stripe_price = $stripe_helper->get_or_create_price($product_version, $price);	
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
			$order_item->set('odi_prv_product_version_id', $product_version->key);
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



	//SUBSCRIPTIONS
	$user_id = $session->get_user_id();
	$order_item = CtldAccount::GetPlanOrderItem($user_id);
	if($order_item){
		$current_plan_id = $order_item->get('odi_pro_product_id');
		$page_vars['current_plan_id'] = $current_plan_id;
	}
	else{
		$page_vars['current_plan_id'] = NULL;
	}	
	
	
	$page_vars['currency_symbol'] = Product::$currency_symbols[$settings->get_setting('site_currency')]; 
	
	//$page_vars['pager'] = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	
	return $page_vars;
}
?>

