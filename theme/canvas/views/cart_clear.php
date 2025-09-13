<?php
	// Core files (PathHelper, Globalvars, SessionControl) are guaranteed available
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	ThemeHelper::includeThemeFile('logic/cart_clear_logic.php');

	$page_vars = cart_clear_logic($_GET, $_POST);
	
	//NOW REDIRECT TO CONFIRMATION PAGE
	LibraryFunctions::Redirect('/cart');
?>