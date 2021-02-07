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

if (isset($_REQUEST['extra_data']) && is_numeric($_REQUEST['extra_data'])) {
	$extra_data = array('url_param' => $_REQUEST['extra_data']);
} else {
	$extra_data = array();
}



$display_empty_form = TRUE;

if ($_POST || isset($_GET['cart'])) {
	if (isset($_POST['product_key'])) {
		$form_data = $session->get_saved_item($_POST['product_key']);
		// At this point we know the data is valid, lets store it in the cart
		// and redirect to the payment page!

		try {
			$session->get_shopping_cart()->add_item($product, $form_data);
			LibraryFunctions::redirect('/cart');
			exit;
		} catch (ShoppingCartException $e) {
			$errorhandler = new ErrorHandler(TRUE);
			$errorhandler->handle_general_error($e->getMessage());
		}
	} else {
		try {
			list($form_data, $display_data) = $product->validate_form($_POST, $session);
			

			
		}	catch (ProductRequirementException $e) {
			$errorhandler = new ErrorHandler(TRUE);
			$errorhandler->handle_general_error($e->getMessage());
		}

		//if (!$display_data) {  //CONFIRM TURNED OFF, GO STRAIGHT TO CHECKOUT
			// If there is nothing to confirm, go straight to checkout
			try {
				$session->get_shopping_cart()->add_item($product, $form_data);
				LibraryFunctions::redirect('/cart');
				exit;
			} catch (ShoppingCartException $e) {
				$errorhandler = new ErrorHandler(TRUE);
				$errorhandler->handle_general_error($e->getMessage());
			}
		//}

		$form_key = md5(serialize($form_data) . time());
		$session->save_item($form_key, $form_data);
		$display_empty_form = FALSE;  
	}
}

if ($session->get_user_id()) {
	$user = new User($session->get_user_id(), TRUE);
}
else{
	$user = NULL;
}

?>