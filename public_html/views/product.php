<?php
require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
require_once(LibraryFunctions::get_theme_path().'/includes/PublicPage.php');
require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublic.php');
require_once (LibraryFunctions::get_logic_file_path('product_logic.php'));


$page = new PublicPage(TRUE);
$page->public_header(array(
	'title' => $product->get('pro_name')
	));
	echo PublicPage::BeginPage('Add to Cart');

	?>
		<div class="section padding-top-20">
			<div class="container">
				<div class="row col-spacing-50">
					<div class="col-12 col-lg-7">

		<?php
	if (!$display_empty_form) {
		echo '<p>Is everything correct?</p>';
		$formwriter = new FormWriterPublic("product_form", TRUE);
		echo $formwriter->begin_form("", "POST", "/product"); 

		echo $formwriter->hiddeninput('product_id', $product_id);
		echo $formwriter->hiddeninput('product_key', $form_key);

		foreach($display_data as $key => $value) {
			echo $formwriter->text('<strong>' . $key . '</strong>', $value, 'ctrlHolder');
		}

		echo $formwriter->new_form_button('Next Step', 'button button-lg button-dark');
		echo $formwriter->end_form();
	} 
	else {
		if(!$product->get('pro_is_active')){
			echo '<p>Sorry, this item is currently not available for purchase/registration.</p>';		
		}
		else{
				?>
				<!--<ul class="list-inline-slash font-small margin-bottom-10">
					<li><a href="#">Technology</a></li>
					<li><a href="#">Smart Watch</a></li>
				</ul>-->
				<h3 class="font-weight-normal margin-0"><?php echo $product->get('pro_name'); ?></h3>
				<?php
				if(!$product->num_versions() && $product->get('pro_price_type') != Product::PRICE_TYPE_USER_CHOOSE){
					echo '<div class="product-price">
					<h5 class="font-weight-light"><ins>$'.$product->get('pro_price').'</ins></h5>
					</div>';
				} 
				?>
				<p><?php echo $product->get('pro_description'); ?></p>
				<?php

				?>
					<!--
					<div class="qnt">
						<input type="number" id="quantity" name="quantity" min="1" max="10" value="1">
					</div>
					-->
				<!--
				<div class="margin-top-30">
					<p>SKU: 7777</p>
					<a class="button-text-1 margin-top-10" href="#">Add to Wishlist</a>
				</div>
				-->
			</div>
			<div class="col-12 col-lg-5">
				<?php
				$formwriter = new FormWriterPublic("product_form", TRUE);
				echo $formwriter->begin_form("product-quantity margin-top-30", "POST", "/product"); 
				echo $formwriter->hiddeninput('product_id', $product_id);
				if($product->get('pro_price_type') == Product::PRICE_TYPE_USER_CHOOSE){
					echo $formwriter->textinput('Amount to pay ($)', 'user_price_override', 'ctrlHolder', 100, NULL, '', 5, '');
				}
				if ($product->output_product_form($formwriter, $user, $extra_data)) {
					echo $formwriter->new_form_button('Add to Cart', 'button button-md button-dark');
				}
				echo $formwriter->end_form();
				?>
			</div>
			<?php 
			$product->output_javascript($extra_data);
		}
	}
	?>
				</div><!-- end row -->
			</div><!-- end container -->
		</div>
		<?php
	echo PublicPage::EndPage();
$page->public_footer($foptions=array('track'=>TRUE));
?>