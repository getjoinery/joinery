<?php
	
	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('cart_clear_logic.php', 'logic', 'system', null, 'store', false));

	$page_vars = process_logic(cart_clear_logic(array_merge($_GET, $_POST, $params ?? [])));
	
	//NOW REDIRECT TO CONFIRMATION PAGE
	LibraryFunctions::Redirect('/cart');
?>