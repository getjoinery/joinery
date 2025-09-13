<?php
	// LibraryFunctions is now guaranteed available - line removed
	require_once(PathHelper::getThemeFilePath('cart_charge_logic.php', 'logic'));

	$page_vars = cart_charge_logic($_GET, $_POST);
	
	//NOW REDIRECT TO CONFIRMATION PAGE
	LibraryFunctions::Redirect('/cart_confirm'); 
?>