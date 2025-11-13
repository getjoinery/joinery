<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function cart_clear_logic($get_vars, $post_vars){
	require_once(PathHelper::getIncludePath('includes/ShoppingCart.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	
	$page_vars = array();

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	if(!$settings->get_setting('products_active')){
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();
	}
	
	$cart = $session->get_shopping_cart();
	$cart->clear_cart();
	
	return LogicResult::render($page_vars);
}

?>