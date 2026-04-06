<?php
	require_once(PathHelper::getIncludePath('includes/ShoppingCart.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	// PathHelper is already loaded
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

	if($receipts){
		LibraryFunctions::redirect('/profile/scrolldaddy/devices');
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
					<a href="/profile/scrolldaddy/devices" class="th-btn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em;margin-right:0.5rem"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9,22 9,12 15,12 15,22"/></svg>Log in and set up your devices</a>
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