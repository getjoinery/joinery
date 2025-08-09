<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/ShoppingCart.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/PathHelper.php');
PathHelper::requireOnce('includes/ThemeHelper.php');
	ThemeHelper::includeThemeFile('includes/PublicPage.php');

	if($receipts){
		LibraryFunctions::redirect('/profile/devices');
		exit;
	}

	$session = SessionControl::get_instance();
	$session_id = $_GET['session_id']; 

	$settings = Globalvars::get_instance();

	$cart = $session->get_shopping_cart();
	$receipts = $cart->last_receipt;
	
	$page = new PublicPage(TRUE);
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => "Checkout confirmation"
	));
	echo PublicPage::BeginPage('Checkout confirmation');
	//echo PublicPage::BeginPanel();	



	$settings = Globalvars::get_instance();
	$defaultemail = $settings->get_setting('defaultemail');

	if($receipts){
		//IN SCROLLDADDY WE ONLY HAVE ONE ITEM PURCHASED
		$receipt = $receipts[1];
		
		?>
		<section class="space">
			<div class="container">
				<div class="error-content">
					<h2 class="error-title">Welcome to ScrollDaddy!</h2>
					<p class="error-text">You purchased the <?php echo $receipt['pname']; ?> . We sent your password to the email you provided. </p>
					<a href="/profile/devices" class="th-btn"><i class="fal fa-home me-2"></i>Log in and set up your devices</a>
				</div>
			</div>
		</section>		
		
		
		<?php		
	}
	else{
		?>
		<p>Your recent purchase is not available.  It could be that it didn't go through, or perhaps it's been too much time since it was processed.</p>
		<p>If you think something is wrong, please contact us at <a href="mailto:<?php echo $defaultemail; ?>"><?php echo $defaultemail; ?></a>.</p>	

		<?php
	}
	


	//echo PublicPage::EndPanel();
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>