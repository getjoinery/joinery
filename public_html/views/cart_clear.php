<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	PathHelper::requireOnce('/includes/LibraryFunctions.php');
	require_once(PathHelper::getThemeFilePath('cart_clear_logic.php', 'logic'));

	$page_vars = cart_clear_logic($_GET, $_POST);
	
	//NOW REDIRECT TO CONFIRMATION PAGE
	LibraryFunctions::Redirect('/cart');
?>