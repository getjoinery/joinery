<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

function subscription_cancel_logic($get_vars, $post_vars){
	require_once(PathHelper::getIncludePath('includes/EmailTemplate.php'));
	require_once(PathHelper::getIncludePath('includes/StripeHelper.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/order_items_class.php'));
	require_once(PathHelper::getIncludePath('plugins/controld/data/ctldaccounts_class.php'));

	$stripe_helper = new StripeHelper();

	$settings = Globalvars::get_instance();
	if(!$settings->get_setting('products_active')){
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();
	}

	$session = SessionControl::get_instance();
	$session->check_permission(0);

	if($post_vars['order_item_id']){

		$order_item_id = LibraryFunctions::fetch_variable_local($post_vars, 'order_item_id', NULL, 1, 'order_item_id');
		$order_item = new OrderItem($order_item_id, TRUE);
		$success = $order_item->cancel_subscription_order_item(true, 'immediate');

		if($success){
			//NOW UPDATE THE ACCOUNT ENDING DATE
			$account = CtldAccount::GetByColumn('cda_usr_user_id', $order_item->get('odi_usr_user_id'));
			if($account){
				$account->set('cda_period_end', $order_item->get('odi_subscription_period_end'));
				$account->save();
				$page_vars['account'] = $account;
			}
		}

		return LogicResult::redirect('/profile');
	}

	$page_vars = array();

	//SUBSCRIPTIONS
	$user_id = $session->get_user_id();

	// Find active subscription for this user
	$active_subscriptions = new MultiOrderItem([
		'user_id' => $user_id,
		'is_active_subscription' => true
	]);
	$active_subscriptions->load();

	if($active_subscriptions->count() > 0){
		$order_item = $active_subscriptions->get(0);
		$page_vars['current_order_item'] = $order_item;
	}
	else{
		throw new SystemDisplayablePermanentError("There is no subscription to cancel.");
	}

	$account = CtldAccount::GetByColumn('cda_usr_user_id', $order_item->get('odi_usr_user_id'));
	$page_vars['account'] = $account;

	return LogicResult::render($page_vars);
}

?>