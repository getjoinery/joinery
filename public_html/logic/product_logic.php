<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ShoppingCart.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/data/products_class.php');

$settings = Globalvars::get_instance();
if(!$settings->get_setting('products_active')){
	header("HTTP/1.0 404 Not Found");
	echo 'This feature is turned off';
	exit();
}
	
$session = SessionControl::get_instance();
//$session->check_permission(0);
	
if (!isset($_REQUEST['product_id']) || !is_numeric($_REQUEST['product_id'])) {
	$product_id = 3;
} else {
	$product_id = $_REQUEST['product_id'];
}

$product = Product::GetProductById($product_id);


$display_empty_form = TRUE;

if ($_POST || isset($_GET['cart'])) {
	try {
		list($form_data, $display_data) = $product->validate_form($_POST, $session);
		
	}	
	catch (ProductRequirementException $e) {
		$errorhandler = new ErrorHandler(TRUE);
		$errorhandler->handle_general_error($e->getMessage());
	}


	try {
		$cart = $session->get_shopping_cart();
		if($product->get('pro_price_type') == Product::PRICE_TYPE_USER_CHOOSE && $_REQUEST['user_price_override']){
			//REMOVE EVERYTHING BUT DECIMALS AND INTEGERS (ALLOW FOR EUROPEAN COMMAS)
			$form_data['user_price_override'] = (int)str_replace(',', '.', preg_replace("/[^0-9\.,]/", "", $_REQUEST['user_price_override'])); 	
			if(!$form_data['user_price_override']){
				throw new ProductRequirementException(
					'You must enter an amount in the "Price to pay" field.');
			}
			$cart->add_item($product, $form_data);
			unset($form_data['user_price_override']);
		}
		else{ 
			//IF USER ENTERED AN EXTRA DONATION CREATE THAT ITEM
			if($_REQUEST['user_price']){
				//REMOVE EVERYTHING BUT DECIMALS AND INTEGERS (ALLOW FOR EUROPEAN COMMAS)
				$form_data['user_price'] = (int)str_replace(',', '.', preg_replace("/[^0-9\.,]/", "", $_REQUEST['user_price']));
				$extra_donation = new Product(Product::PRODUCT_ID_OPTIONAL_DONATION, TRUE);
				$cart->add_item($extra_donation, $form_data);
			}	
			unset($form_data['user_price']);
			$cart->add_item($product, $form_data);
		}

		
		LibraryFunctions::redirect('/cart');
		exit;
	} 
	catch (ShoppingCartException $e) {
		$errorhandler = new ErrorHandler(TRUE);
		$errorhandler->handle_general_error($e->getMessage());
	}


	$form_key = md5(serialize($form_data) . time());
	$session->save_item($form_key, $form_data);
	$display_empty_form = FALSE;  
	
}

if ($session->get_user_id()) {
	$user = new User($session->get_user_id(), TRUE);
}
else{
	$user = NULL;
}

?>