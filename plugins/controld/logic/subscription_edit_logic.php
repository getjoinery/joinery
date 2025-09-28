<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

function subscription_edit_logic($get_vars, $post_vars){
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/Pager.php'));
	require_once(PathHelper::getIncludePath('includes/StripeHelper.php'));
	require_once(PathHelper::getIncludePath('data/products_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/orders_class.php'));
	require_once(PathHelper::getIncludePath('data/order_items_class.php'));
	require_once(PathHelper::getIncludePath('plugins/controld/data/ctldaccounts_class.php'));

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

	// Get current plan for the user
	$user = new User($session->get_user_id(), TRUE);
	$ctld_account = CtldAccount::GetByColumn('cda_usr_user_id', $user->key);

	if($ctld_account){
		$page_vars['current_plan_id'] = $ctld_account->get('cda_plan');
	} else {
		$page_vars['current_plan_id'] = null;
	}

	if($_GET['new_version']){
		$new_version = LibraryFunctions::fetch_variable_local($get_vars, 'new_version', 0, 'notrequired', '', 'safemode', 'int');
		$page_vars['order_item_id'] = $order_item_id ?? null;
		$product_version = new ProductVersion($new_version, TRUE);
		$product = new Product($product_version->get('prv_pro_product_id'), TRUE);
		$page_vars['product'] = $product;

	}
	else if(isset($_POST['product_id'])){
		$product_id = LibraryFunctions::fetch_variable_local($post_vars, 'product_id', 0, 'notrequired', '', 'safemode', 'int');
		$user = new User($session->get_user_id(), TRUE);

		// Find the current subscription order item for this user
		$order_items = new MultiOrderItem([
			'user_id' => $user->key,
			'is_active_subscription' => true
		]);
		$order_items->load();

		if($order_items->count() > 0){
			$order_item = $order_items->get(0);
		} else {
			// No active subscription found
			return LogicResult::redirect('/pricing');
		}

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
		if(StripeHelper::isTestMode()){
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

		//SEND THE EMAIL CONFIRMING THE SUBSCRIPTION CHANGE
		LibraryFunctions::redirect('/profile/subscription_edit?change=1');
		exit();
	}
	else{
		$page_vars['product'] = null;
	}

	return $page_vars;
}
?>