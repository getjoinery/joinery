<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
// PathHelper is already loaded
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('product_logic.php', 'logic'));

	// Always call product_logic - it contains essential business logic
	$page_vars = process_logic(product_logic($_GET, $_POST, $product));
	$product = $page_vars['product'];
	$product_version = $page_vars['product_version'];
	$cart = $page_vars['cart'];

	// Set product_id for the form
	$product_id = $product ? $product->key : null;

	$page = new PublicPage();
	$page->public_header(array(
	'is_valid_page' => $is_valid_page,
	'title' => $product->get('pro_name')
	));
	

	if(!$product->get('pro_is_active')){
		PublicPage::OutputGenericPublicPage('Product not available', 'Product not available', '<p>Sorry, this item is currently not available for purchase/registration.</p>');	
	}
	
	echo PublicPage::BeginPage('Add to Cart');
	
	if (!$page_vars['display_empty_form']) {
		echo '<p>Is everything correct?</p>';
		$settings = Globalvars::get_instance();
		$formwriter = $page->getFormWriter('product_form');
		echo $formwriter->begin_form("", "POST", "/product"); 

		echo $formwriter->hiddeninput('product_id', $product_id);
		echo $formwriter->hiddeninput('product_key', $form_key);

		foreach($page_vars['display_data'] as $key => $value) {
			echo $formwriter->text('<strong>' . $key . '</strong>', $value, 'ctrlHolder');
		}

		echo $formwriter->new_form_button('Next Step');
		echo $formwriter->end_form();
		echo PublicPage::EndPage();
		$page->public_footer($foptions=array('track'=>TRUE));

	} 

	?>
	<!--==============================
Career Area
==============================-->
    <section class="overflow-hidden space">
        <div class="container">
            <div class="row">
                <div class="col-xxl-8 col-lg-7">
                    <div class="job-single mb-0">
                        <div class="job-author-wrapp">
                            <!--<div class="job-author">
                                <img src="assets/img/normal/career-logo7.png" alt="Image">
                            </div>-->
                            <div class="author-info">
                                <h2 class="sec-title page-title mb-10"><?php echo $product->get('pro_name'); ?></h2>
                                <!--<div class="job-meta">
                                    <span class="location"><i class="fa-regular fa-location-dot me-2"></i>United
                                        States</span>
                                    <span class="location"><i class="fa-light fa-briefcase me-2"></i>Full Time</span>
                                    <span class="date"><i class="fa-regular fa-clock me-2"></i>1 Days Ago</span>
                                </div>-->
                            </div>
                        </div>
                        <div class="job-description mb-40">
                            <?php echo $product->get('pro_description'); ?>

                        </div>
                       
                    </div>
                </div>
                <div class="col-xxl-4 col-lg-5">
                    <aside class="sidebar-area">
                        <div class="widget widget_info  ">
                            <h3 class="widget_title">			<?php
			if($product->is_sold_out()){
				echo '<p>Sorry, this item is currently sold out.</p>';		
			}
			else if($product->get_readable_price()){
				echo 'Your Info'; //echo $product->get_readable_price();
			} 
			?></h3>
							<?php
						
					//DO NOT DISPLAY THE PRODUCT IF IT IS SOLD OUT 
				if(!$product->is_sold_out()){
					$formwriter = $page->getFormWriter('product_form');
					// Post back to the same product URL (with slug)
					$product_url = '/product/' . $product->get('pro_link');
					echo $formwriter->begin_form("product-quantity", "POST", $product_url, true); 
					echo $formwriter->hiddeninput('product_id', $product_id);

					if ($product->output_product_form($formwriter, $page_vars['user'], null, $product_version->key)) {


						echo $formwriter->new_form_button('Add to Cart' , 'primary', 'full', 'th-btn');
					}
					echo $formwriter->end_form(true);
					$product->output_javascript($formwriter, array());
				}
				?>
							
							
							
							
                            <!--<div class="info-list">
                                <ul>
                                    <li>
                                        <strong>Position: </strong>
                                        <span>Senior UI/UX Designer </span>
                                    </li>
                                    <li>
                                        <strong>Vacancy: </strong>
                                        <span>02 </span>
                                    </li>
                                    <li>
                                        <strong>Location: </strong>
                                        <span>Fully Remotely Based</span>
                                    </li>
                                    <li>
                                        <strong>Salary Range: </strong>
                                        <span>$800 - $1000 (Based on your experience)</span>
                                    </li>
                                    <li>
                                        <strong>Deadline: </strong>
                                        <span>3rd June, 2024</span>
                                    </li>
                                </ul>
                            </div>-->
                           <!-- <a class="th-btn btn-fw" href="job-details.html">Apply Now</a>-->
                        </div>
                        
                    </aside>
                </div>
            </div>
        </div>
    </section>

	

		<?php
	echo PublicPage::EndPage();
$page->public_footer($foptions=array('track'=>TRUE));
?>