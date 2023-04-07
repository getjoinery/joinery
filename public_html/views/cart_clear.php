<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	require_once (LibraryFunctions::get_logic_file_path('cart_clear_logic.php'));

	$page_vars = cart_clear($_GET, $_POST);
	
	//NOW REDIRECT TO CONFIRMATION PAGE
	LibraryFunctions::Redirect('/cart');
?>