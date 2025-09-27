<?php
	
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	require_once(PathHelper::getThemeFilePath('cart_charge_logic.php', 'logic'));

	$page_vars = cart_charge_logic($_GET, $_POST);
// Handle LogicResult return format
if ($page_vars->redirect) {
    LibraryFunctions::redirect($page_vars->redirect);
    exit();
}
$page_vars = $page_vars->data;
	
	//NOW REDIRECT TO CONFIRMATION PAGE
	LibraryFunctions::Redirect('/cart_confirm'); 
?>