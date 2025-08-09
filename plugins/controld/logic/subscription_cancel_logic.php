<?php
function subscription_cancel_logic($get_vars, $post_vars){
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/EmailTemplate.php');
	PathHelper::requireOnce('includes/StripeHelper.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/order_items_class.php');
	PathHelper::requireOnce('plugins/controld/data/ctldaccounts_class.php');
	
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
		
		$order_item_id = LibraryFunctions::fetch_variable_local($post_vars, 'order_item_id', NULL,1,'order_item_id');
		$order_item = new OrderItem($order_item_id, TRUE);	
		$success = $order_item->cancel_subscription_order_item(true, 'immediate');

		if($success){
			//NOW UPDATE THE ACCOUNT ENDING DATE 
			$account = CtldAccount::GetByColumn('cda_usr_user_id', $order_item->get('odi_usr_user_id'));
			$account->set('cda_period_end', $order_item->get('odi_subscription_period_end'));
			$account->save();
			$page_vars['account'] = $account;
		}
		
		
		LibraryFunctions::redirect('/profile');
		exit;
	}
	
	//SUBSCRIPTIONS
	$user_id = $session->get_user_id();
	$order_item = CtldAccount::GetPlanOrderItem($user_id);
	
	if($order_item){
		$page_vars['current_order_item'] = $order_item;
	}
	else{
		throw new SystemDisplayablePermanentError("There is no subscription to cancel.");
		exit;		
	}
	
	$account = CtldAccount::GetByColumn('cda_usr_user_id', $order_item->get('odi_usr_user_id'));
	$page_vars['account'] = $account;	
	
	return $page_vars;
}

?>