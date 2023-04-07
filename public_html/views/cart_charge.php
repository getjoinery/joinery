<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	require_once (LibraryFunctions::get_logic_file_path('cart_charge_logic.php'));

	$page_vars = cart_charge($_GET, $_POST);
	
	//NOW REDIRECT TO CONFIRMATION PAGE
	LibraryFunctions::Redirect('/cart_confirm'); 
?>