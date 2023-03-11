<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ShoppingCart.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/data/questions_class.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/data/products_class.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/data/product_requirements_class.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/data/product_requirement_instances_class.php');

$settings = Globalvars::get_instance();
if($_POST['product_id']){
	$product_id = (int)$_POST['product_id'];
}

if(!$product_id){
	header("HTTP/1.0 404 Not Found");
	echo 'Product does not exist.';
	exit();	
}

if(!$settings->get_setting('products_active')){
	header("HTTP/1.0 404 Not Found");
	echo 'This feature is turned off';
	exit();
}

if(!$product_id){
	header("HTTP/1.0 404 Not Found");
	echo 'Did not pass a product id';
	exit();
}


	
$session = SessionControl::get_instance();
//$session->check_permission(0);
/*
if (!isset($_REQUEST['product_id']) || !is_numeric($_REQUEST['product_id'])) {
	$product_id = 3;
} 
else {
	$product_id = LibraryFunctions::fetch_variable('product_id', '', TRUE, '', TRUE, 'int');
}
*/
$product = Product::GetProductById($product_id);

//IF NO ITEMS REMAINING, SHOW ERROR
if($product->get('pro_max_purchase_count') > 0){
	$remaining = $product->get('pro_max_purchase_count') - $product->get_number_purchased();
	if(!$remaining){
		throw new SystemDisplayableError(
					'This item is sold out.');
	}
}

$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')]; 


$display_empty_form = TRUE;

if ($_POST || isset($_GET['cart'])) {
	try {
		list($form_data, $display_data) = $product->validate_form($_POST, $session);
	}	
	catch (BasicProductRequirementException $e) {
		$errorhandler = new ErrorHandler(TRUE);
		$errorhandler->handle_general_error($e->getMessage());
	}

	//NOW VALIDATE THE ADDITIONAL PRODUCT REQUIREMENTS
	$instances = $product->get_requirement_instances();
	foreach($instances as $instance){
		$requirement = new ProductRequirement($instance->get('pri_prq_product_requirement_id'), TRUE);
		$question = new Question($requirement->get('prq_qst_question_id'), TRUE);
		$valid = $question->validate_answers($_REQUEST['question_'.$question->key]);
		if($valid == 'valid'){
			$question_info = array('name' => 'question_'.$question->key, 'requirement_id' => $instance->get('pri_prq_product_requirement_id'), 'question_id' => $question->key, 'question' => $question->get('qst_question'), 'answer' => $question->get_answer_readable($_REQUEST['question_'.$question->key], false));
			$form_data['question_'.$question->key] = $question_info;
		}
		else{
			$errorhandler = new ErrorHandler(TRUE);
			$errorhandler->handle_general_error($valid);
			exit();
		}
	}

	try {
		$cart = $session->get_shopping_cart();
		if($product->get('pro_price_type') == Product::PRICE_TYPE_USER_CHOOSE && isset($_REQUEST['user_price_override'])){
			//REMOVE EVERYTHING BUT DECIMALS AND INTEGERS (ALLOW FOR EUROPEAN COMMAS)
			$form_data['user_price_override'] = (int)str_replace(',', '.', preg_replace("/[^0-9\.,]/", "", $_REQUEST['user_price_override'])); 	
			if(!$form_data['user_price_override']){
				throw new SystemDisplayableError(
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