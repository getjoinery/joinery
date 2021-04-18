<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/ShoppingCart.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/PublicPage.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublic.php');



	$session = SessionControl::get_instance();
	//$session->check_permission(0);
	$session_id = $_GET['session_id']; 

	$settings = Globalvars::get_instance();

	$cart = $session->get_shopping_cart();
	$receipts = $cart->last_receipt;
	
	$page = new PublicPage(TRUE);
	$page->public_header(array(
	'title' => "Checkout confirmation"
	));
	echo PublicPage::BeginPage('Checkout confirmation');	
	?>

	<div class="section-lg padding-top-20">
		<div class="container">
			<div class="row col-spacing-40">

					<?php	
	if($receipts){
		
		?>
		<p>Thank you for your purchase.  An email has been sent to the email address of all registrants with your purchase confirmation and a link to provide any further info that we need to finalize registrations.</p>
		<?php
		
		$headers = array('Cart item', 'Item', 'Price');
		$page->tableheader($headers);

		$total = 0;
		foreach($receipts as $rkey => $receipt) {
			$total += $receipt[price];
			$rowvalues = array();
			
			array_push($rowvalues, $rkey);
			array_push($rowvalues, $receipt[pname] . ' ('. $receipt[name]. ') ');
			array_push($rowvalues, '$' . money_format('%i', $receipt[price]));
			$page->disprow($rowvalues);
		}	
		$page->endtable();
			
		?><p class="cart-total">$<?php echo  money_format('%i', $total); ?></p> 
		<?php
		echo '<div><br><br>All of your courses, and events can be found in the <a href="/profile">My Profile</a> section of the website.';
		echo '<p><a class="et_pb_button" href="/profile" >See all of your courses and events</a></p></div>';		
	}
	else{
		$settings = Globalvars::get_instance();
		$defaultemail = $settings->get_setting('defaultemail');

		?>
		<p>Your recent purchase is not available.  It could be that it didn't go through, or perhaps it's been too much time since it was processed.</p>
		<p>If you think something is wrong, please contact us at <a href="mailto:<?php echo $defaultemail; ?>"><?php echo $defaultemail; ?></a>.</p>
		<br><br><a href="/events">Back to shopping</a>			
		<?php
		
	}
	?>
					

			</div><!-- end row -->
		</div><!-- end container -->
	</div>
	<?php

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>