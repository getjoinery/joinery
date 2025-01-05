<?php
/* THIS FILE CONTAINS ALL SCRIPTS THAT ARE RUN UPON A PRODUCT PURCHASE
SET THE FUNCTION NAME WHEN CREATING THE PRODUCT  
ALL FUNCTIONS END WITH PRODUCT_SCRIPT
ALL FUNCTIONS MUST TAKE USER/PRODUCT/ORDER/ORDER_ITEM/CART  
*/

function controld_subscription_product_script($user, $product, $order, $order_item, $cart){
	
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');

	require_once(__DIR__ . '/../data/ctldaccounts_class.php');

	$ctld_account = new CtldAccount(NULL);
	$ctld_account->set('cda_usr_user_id', $user->key);
	$ctld_account->set('cda_is_active', true);
	
	if($product->key == 18){
		$ctld_account->set('cda_plan', CtldAccount::BASIC_PLAN);
		$ctld_account->set('cda_plan_max_devices', CtldAccount::BASIC_PLAN_MAX_DEVICES);
	}
	
	
	$ctld_account->prepare();
	$ctld_account->save();
	return true;

}
?>

