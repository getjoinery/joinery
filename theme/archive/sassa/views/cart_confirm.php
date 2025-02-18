<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/ShoppingCart.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageSassa.php', '/includes'));



	$session = SessionControl::get_instance();
	$session_id = $_GET['session_id']; 

	$settings = Globalvars::get_instance();

	$cart = $session->get_shopping_cart();
	$receipts = $cart->last_receipt;
	
	$page = new PublicPageSassa(TRUE);
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => "Checkout confirmation"
	));
	echo PublicPageSassa::BeginPage('Checkout confirmation');
	echo PublicPageSassa::BeginPanel();	

	if($receipts){
		
		LibraryFunctions::redirect('/profile/devices');
	}
	else{
		$settings = Globalvars::get_instance();
		$defaultemail = $settings->get_setting('defaultemail');

		?>
		<p>Your recent purchase is not available.  It could be that it didn't go through, or perhaps it's been too much time since it was processed.</p>
		<p>If you think something is wrong, please contact us at <a href="mailto:<?php echo $defaultemail; ?>"><?php echo $defaultemail; ?></a>.</p>	
	
		<?php
		
	}


	echo PublicPageSassa::EndPanel();
	echo PublicPageSassa::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>