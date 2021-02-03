<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/ShoppingCart.php');

	$session = SessionControl::get_instance();
	
	$cart = $session->get_shopping_cart();

	$cart->clear_cart();

	LibraryFunctions::Redirect('/cart');
	exit();
	
?>