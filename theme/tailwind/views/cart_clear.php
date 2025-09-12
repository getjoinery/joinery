<?php
	// LibraryFunctions is now guaranteed available - line removed
	ThemeHelper::includeThemeFile('logic/cart_clear_logic.php');

	$page_vars = cart_clear_logic($_GET, $_POST);
	
	//NOW REDIRECT TO CONFIRMATION PAGE
	LibraryFunctions::Redirect('/cart');
?>