<?php
/* THIS FILE CONTAINS ALL SCRIPTS THAT ARE RUN UPON A PRODUCT PURCHASE
SET THE FUNCTION NAME WHEN CREATING THE PRODUCT  
ALL FUNCTIONS END WITH PRODUCT_SCRIPT
ALL FUNCTIONS MUST TAKE USER/ORDER_ITEM 
*/

function controld_subscription_product_script($user, $order_item){
	
	require_once(__DIR__ . '/../../../includes/PathHelper.php');
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	PathHelper::requireOnce('plugins/controld/data/ctldaccounts_class.php');


	$ctld_account = CtldAccount::GetByColumn('cda_usr_user_id', $user->key);
	if(!$ctld_account){
		$ctld_account = new CtldAccount(NULL);
		$ctld_account->set('cda_usr_user_id', $user->key);
	}
	
	$product = new Product($order_item->get('odi_pro_product_id'), TRUE);

	if($product->key == 19){
		$ctld_account->set('cda_plan', CtldAccount::BASIC_PLAN);
		$ctld_account->set('cda_plan_max_devices', CtldAccount::BASIC_PLAN_MAX_DEVICES);
		$ctld_account->set('cda_is_active', true);
		$ctld_account->set('cda_period_end', NULL);
	}
	else if($product->key == 20){
		$ctld_account->set('cda_plan', CtldAccount::PREMIUM_PLAN);
		$ctld_account->set('cda_plan_max_devices', CtldAccount::PREMIUM_PLAN_MAX_DEVICES);
		$ctld_account->set('cda_period_end', NULL);
	}
	else if($product->key == 21){
		$ctld_account->set('cda_plan', CtldAccount::PRO_PLAN);
		$ctld_account->set('cda_plan_max_devices', CtldAccount::PRO_PLAN_MAX_DEVICES);
		$ctld_account->set('cda_period_end', NULL);
	}
	
	
	$ctld_account->prepare();
	$ctld_account->save();
	return true;

}
?>

