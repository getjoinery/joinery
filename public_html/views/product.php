<?php
require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
require_once(LibraryFunctions::get_theme_includes_path().'/FormWriterPublic.php');
require_once (LibraryFunctions::get_logic_file_path('product_logic.php'));


$page = new PublicPage(TRUE);
$page->public_header(array(
	'title' => $product->get('pro_name')
	));
	echo PublicPage::BeginPage('Add to Cart');

	?>
		<div class="section">
			<div class="container">
				<div class="row col-spacing-50">
					<div class="col-12 col-lg-7">

		<?php
	if (!$display_empty_form) {
		echo '<p>Is everything correct?</p>';
		$formwriter = new FormWriterPublic("product_form", TRUE);
		echo $formwriter->begin_form("uniForm", "POST", "/product"); 

		echo $formwriter->hiddeninput('product_id', $product_id);
		echo $formwriter->hiddeninput('product_key', $form_key);
		echo '<fieldset class="inlineLabels">';

		foreach($display_data as $key => $value) {
			echo $formwriter->text('<strong>' . $key . '</strong>', $value, 'ctrlHolder');
		}
			
		echo $formwriter->start_buttons();
		echo $formwriter->new_form_button('Next Step');
		echo $formwriter->end_buttons();

		echo '</fieldset>';
		echo $formwriter->end_form();
	} 
	else {
		if(!$product->get('pro_is_active')){
			echo '<p>Sorry, this item is currently not available for purchase/registration.</p>';		
		}
		else{
			$formwriter = new FormWriterPublic("product_form", TRUE);
			echo $formwriter->begin_form("uniForm", "POST", "/product"); 
			echo $formwriter->hiddeninput('product_id', $product_id);


			if ($product->output_product_form($formwriter, $user, $extra_data)) {
				echo $formwriter->start_buttons();
				
				echo $formwriter->new_form_button('Add to Cart', '');
				echo $formwriter->end_buttons();
			}


			echo $formwriter->end_form();				


			$product->output_javascript($extra_data);

	?>
						</div>
					<div class="col-12 col-lg-5">
						<!--<ul class="list-inline-slash font-small margin-bottom-10">
							<li><a href="#">Technology</a></li>
							<li><a href="#">Smart Watch</a></li>
						</ul>-->
						<h3 class="font-weight-normal margin-0"><?php echo $product->get('pro_name'); ?></h3>
						<?php
						if(!$product->num_versions() && !$product->get('pro_user_choose_price')){
							echo '<div class="product-price">
							<h5 class="font-weight-light"><ins>'.$product->get('pro_price').'</ins></h5>
							</div>';
						} 
						?>
						<p><?php echo $product->get('pro_description'); ?></p>
						<?php
						$formwriter = new FormWriterPublic("product_form", TRUE);
						echo $formwriter->begin_form("uniForm", "POST", "/product"); 
						exit;
						echo $formwriter->hiddeninput('product_id', $product_id);
						?>
						<form class="product-quantity margin-top-30">
							<!--
							<div class="qnt">
								<input type="number" id="quantity" name="quantity" min="1" max="10" value="1">
							</div>
							-->
							<button class="button button-md button-dark" type="submit">Add to Cart</button>
						</form>
						<!--
						<div class="margin-top-30">
							<p>SKU: 7777</p>
							<a class="button-text-1 margin-top-10" href="#">Add to Wishlist</a>
						</div>
						-->
					</div>

		<?php 
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