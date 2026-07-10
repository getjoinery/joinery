<?php
	
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('cart_charge_logic.php', 'logic', 'system', null, 'store', false));

	$page_vars = process_logic(cart_charge_logic(array_merge($_GET, $_POST, $params ?? [])));
	
	//NOW REDIRECT TO CONFIRMATION PAGE
	LibraryFunctions::Redirect('/cart_confirm'); 
?>