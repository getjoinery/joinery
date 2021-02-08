<?php
require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
require_once(LibraryFunctions::get_theme_includes_path().'/FormWriterPublic.php');
require_once (LibraryFunctions::get_logic_file_path('product_logic.php'));


$page = new PublicPage(TRUE);
$page->public_header(array(
	'title' => $product->get('pro_name')
	));
	echo PublicPage::BeginPage($product->get('pro_name'));

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
		if($product->get('pro_is_active')){
			if(!$product->num_versions() && !$product->get('pro_user_choose_price')){
				echo '<p>Price: <strong class="font16">$' . $product->get('pro_price') . '</strong></p>';
			}

			echo '<p>' . $product->get('pro_description'). '</p>';

			$formwriter = new FormWriterPublic("product_form", TRUE);
			echo $formwriter->begin_form("uniForm", "POST", "/product"); 

			echo $formwriter->hiddeninput('product_id', $product_id);
			echo '<fieldset class="inlineLabels">';

			if ($product->output_product_form($formwriter, $user, $extra_data)) {
				echo $formwriter->start_buttons();
				
				echo $formwriter->new_form_button('Add to Cart', '');
				echo $formwriter->end_buttons();
			}

			echo '</fieldset>';
			echo $formwriter->end_form();					
		}
		else{
			echo '<p>Sorry, this item is currently not available for purchase/registration.</p>';
		}
	}

	$product->output_javascript($extra_data);

	echo PublicPage::EndPage();
$page->public_footer($foptions=array('track'=>TRUE));
?>