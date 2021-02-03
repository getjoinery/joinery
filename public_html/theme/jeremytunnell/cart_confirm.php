<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/Activation.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/ShoppingCart.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/TableHelper.php');
	require_once('includes/PublicPage.php');
	require_once('includes/FormWriterPublic.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/orders_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/products_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/product_details_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_registrants_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/EmailTemplate.php');


	$session = SessionControl::get_instance();
	//$session->check_permission(0);
	$session_id = $_GET['session_id']; 

	$settings = Globalvars::get_instance();

	$cart = $session->get_shopping_cart();
	$receipts = $cart->last_receipt;
	
	if($receipts){
		
		$page = new PublicPage(TRUE);
		$page->public_header(array(
		'title' => "Checkout confirmation",
		'currentmain' => 'Tools',
		));
		
		echo PublicPage::BeginPage('Checkout confirmation');
		?>
		<p>Thank you for your purchase.  An email has been sent to the email address of all registrants with your purchase confirmation and a link to provide any further info that we need to finalize registrations.</p>
		<?php
		

		$checkout_table = new GenericTable(new RowAlternate(array('even', 'odd')));
		$checkout_table->add_headers(array('Cart item', 'Item', 'Price'));	
		
		
		$total = 0;
		foreach($receipts as $rkey => $receipt) {
			$total += $receipt[price];
			
			$checkout_table->add_row(array(
				$rkey,
				$receipt[pname] . ' ('. $receipt[name]. ') ',
				'$' . money_format('%i', $receipt[price]),
			));

		}	

		
		$checkout_table->end_table();	
		?><p class="cart-total">$<?php echo  money_format('%i', $total); ?></p> 
		<?php
		echo '<br><br><p>All of your courses, retreats, and events can be found in the <a href="/profile">My Profile</a> section of the website.';
		echo '<p><a class="et_pb_button" href="/profile" >See all of your courses and events</a></p>';		
	}
	else{
		
		$page = new PublicPage(TRUE);
		$page->public_header(array(
		'title' => "Checkout confirmation",
		'currentmain' => 'Tools',
		));
		
		$settings = Globalvars::get_instance();
		$defaultemail = $settings->get_setting('defaultemail');
		echo PublicPage::BeginPage('Checkout confirmation');
		?>
		<p>Your recent purchase is not available.  It could be that it didn't go through, or perhaps it's been too much time since it was processed.</p>
		<p>If you think something is wrong, please contact us at <a href="mailto:<?php echo $defaultemail; ?>"><?php echo $defaultemail; ?></a>.</p>
		<br><br><a href="/events">Back to shopping</a>			
		<?php
		
	}

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>