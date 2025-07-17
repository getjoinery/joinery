<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	require_once (LibraryFunctions::get_logic_file_path('products_logic.php'));
	require_once(LibraryFunctions::get_theme_file_path('PublicPage.php', '/includes'));

	//OVERRIDE GET VARS
	$_GET['numperpage'] = 100;
	$_GET['sdirection'] = 'ASC';
	$_GET['subscriptions'] = 'all';
	$page_vars = products_logic($_GET, $_POST);
	
	$session = SessionControl::get_instance();
	$session->check_permission(5);
	
	$page = new PublicPage();
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Products'
	));
	echo PublicPage::BeginPage('Products');
	echo PublicPage::BeginPanel();
	
	?>
	<script language="javascript">
	 $(document).ready(function() {	
		$('input[name="full_name_first"]').val('Jeremy');
		$('input[name="full_name_last"]').val('Test');
		$('input[name="email"]').val('jeremy.tunnell@gmail.com');
	});
	</script>
	<?php
	
	if($_SESSION['test_mode'] || $settings->get_setting('debug')){
		echo '<div style="border: 3px solid red; padding: 10px; margin: 10px;">Using test mode with type '.$settings->get_setting('checkout_type').'.</div>';
	}
	
	echo 'Checkout type:  '. $settings->get_setting('checkout_type').'<br>';
	
	if($settings->get_setting('use_paypal_checkout')){
		echo 'Paypal checkout enabled'.'<br>'; 
	}
	else{
		echo 'Paypal checkout disabled'.'<br>'; 
	}

	foreach ($page_vars['products'] as $product){ 
		echo '<h1 style="margin-top: 40px;"><b>'.$product->get('pro_name').'</b></h1>'; 
		if($product->get_url()){
			echo '<a href="'.$product->get_url().'">Product link</a><br>';
		}
		if($product->get_readable_price()){
			echo $product->get_readable_price();
		}  

		if($product->is_sold_out()){
			echo 'sold out<br>';
		}
		else{
			$settings = Globalvars::get_instance();
			$formwriter = LibraryFunctions::get_formwriter_object("product_form".$product->key, $settings->get_setting('form_style'));
			echo $formwriter->begin_form("product-quantity", "POST", "/product", true); 
			echo $formwriter->hiddeninput('product_id', $product->key);
			if ($product->output_product_form($formwriter, $page_vars['user'], null)) {
				echo $formwriter->new_form_button('Add to Cart', 'primary','full');
			}
			echo $formwriter->end_form(true);
			$product->output_javascript(NULL, "product_form".$product->key, $formwriter);
	
		}
		echo '<hr>';
	}
	
  
	echo PublicPage::EndPanel();
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>

