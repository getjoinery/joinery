<?php
	
	require_once(PathHelper::getIncludePath('/includes/ShoppingCart.php'));
	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

	$session = SessionControl::get_instance();
	$session_id = $_GET['session_id']; 

	$settings = Globalvars::get_instance();

	$cart = $session->get_shopping_cart();
	$receipts = $cart->last_receipt;
	
	$page = new PublicPage();
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => "Checkout confirmation"
	));
	echo PublicPage::BeginPage('Checkout confirmation');
	echo PublicPage::BeginPanel();	

	if($receipts){
		
		?>
		<p class="mt-3 text-base text-gray-500">Thank you for your purchase.  An email has been sent to the email address of all registrants with your purchase confirmation and a link to provide any further info that we need.</p>
		<?php
		
		$headers = array('Item', 'Price');
		$page->tableheader($headers);

		$total = 0;
		foreach($receipts as $rkey => $receipt) {
			$total += $receipt['price'];
			$rowvalues = array();

			//array_push($rowvalues, $rkey);
			array_push($rowvalues, $receipt['pname'] . ' ('. $receipt['name']. ') ');
			array_push($rowvalues, '$' . number_format($receipt['price'], 2, '.', ','));
			//array_push($rowvalues, '<a href="'.$receipt['link'].'">'.$receipt['link'].'</a>');
			$page->disprow($rowvalues);
		}
			$rowvalues = array();	
			array_push($rowvalues, '<b>Total</b>');
			array_push($rowvalues, '<b>$' . number_format($total, 2, '.', ',').'</b>');
			$page->disprow($rowvalues);		
		$page->endtable();

		echo '<div class="mt-3 text-base text-gray-500"><br><br>All of your purchases can be found in the <a href="/profile">My Profile</a> section of the website.';
		echo '<p><a href="/profile" >See all of your purchases</a></p></div>';		
	}
	else{
		$settings = Globalvars::get_instance();
		$defaultemail = $settings->get_setting('defaultemail');

		?>
		<p class="mt-3 text-base text-gray-500">Your recent purchase is not available.  It could be that it didn't go through, or perhaps it's been too much time since it was processed.</p>
		<p class="mt-3 text-base text-gray-500">If you think something is wrong, please contact us at <a href="mailto:<?php echo $defaultemail; ?>"><?php echo $defaultemail; ?></a>.</p>	
	
		<?php
		
	}

	echo PublicPage::EndPanel();
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>