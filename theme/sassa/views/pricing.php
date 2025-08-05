<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once (LibraryFunctions::get_logic_file_path('pricing_logic.php'));
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/PathHelper.php');
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

	$page_vars = pricing_logic($_GET, $_POST);
	$page_choice = $page_vars['page_choice'];
	$products = $page_vars['products'];
	$product_versions = $page_vars['product_versions'];


	$page = new PublicPage(TRUE);
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Pricing'
	));
	echo PublicPage::BeginPage('Pricing');

	//echo PublicPage::BeginPanel();
?>
<!--==============================
Price Area  
==============================-->
    <section class="space">
        <div class="container">
            <div class="title-area text-center">
                <!--<span class="sub-title">
                    Our Pricing
                </span>-->
                <h2 class="sec-title">Plans</h2>
                <!--<p>Choose a plan that suits your business needs</p>-->
                <div class="pricing-tabs">
                    <div class="switch-area">
                        <label class="toggler toggler--is-active ms-0" id="filt-monthly">Monthly</label>



						<div class="toggle">
							<?php

							if(!$page_choice || $page_choice == 'month'){
								echo '<input type="checkbox" id="switcher" class="check">';
							}
							else{
								
								echo '<input type="checkbox" id="switcher" class="check" checked>';
							}
                            ?>
                            <b class="b switch"></b>
							
                        </div>
						
                        <label class="toggler" id="filt-yearly">Yearly</label>
                    </div>
					
					
					   <script>
						// Get the toggle input
						const toggle = document.getElementById('switcher');

						// Add an event listener for change
						toggle.addEventListener('change', function () {
							
						  if (this.checked) {
							  
							// Redirect to the new URL when toggled on
							window.location.href = '/pricing?page=year';
						  } else {
							 
							// Redirect to a different URL or stay on the same page when toggled off
							window.location.href = '/pricing';
						  }
						});
					  </script>
                    <div class="discount-tag">
                        <svg width="54" height="41" viewBox="0 0 54 41" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path fill-rule="evenodd" clip-rule="evenodd" d="M15.5389 7.99353C15.4629 8.44111 15.3952 8.82627 15.3583 9.02966C15.1309 10.2666 14.942 13.4078 14.062 15.5433C13.3911 17.1727 12.3173 18.2233 10.6818 17.8427C9.19525 17.4967 8.26854 16.0251 7.82099 13.9916C6.85783 9.61512 8.00529 2.6265 8.90147 0.605294C8.99943 0.384693 9.25826 0.284942 9.48075 0.382666C9.70224 0.479891 9.80333 0.737018 9.70537 0.957619C8.84585 2.89745 7.75459 9.6061 8.67913 13.8076C9.04074 15.4498 9.68015 16.7144 10.881 16.9937C12.0661 17.2698 12.7622 16.3933 13.2485 15.2121C14.1054 13.134 14.273 10.0757 14.4938 8.87118C14.6325 8.11613 15.0798 5.22149 15.1784 4.9827C15.3016 4.68358 15.5573 4.69204 15.641 4.70108C15.7059 4.708 16.0273 4.76322 16.0423 5.15938C16.2599 10.808 20.5327 19.3354 26.8096 25.0475C33.0314 30.7095 41.2522 33.603 49.4783 28.0026C49.6784 27.8669 49.9521 27.9178 50.0898 28.1157C50.2269 28.3146 50.1762 28.5863 49.9762 28.7219C41.3569 34.5897 32.7351 31.6217 26.217 25.6902C20.7234 20.6913 16.7462 13.5852 15.5389 7.99353Z" fill="var(--theme-color)" />
                            <path fill-rule="evenodd" clip-rule="evenodd" d="M49.2606 28.5952C48.2281 28.5096 47.1974 28.4571 46.1708 28.2919C43.4358 27.8522 40.6863 26.8206 38.4665 25.1551C38.2726 25.0089 38.2345 24.7355 38.3799 24.5438C38.5267 24.3517 38.8021 24.3145 38.9955 24.4592C41.1013 26.0411 43.7143 27.0136 46.3092 27.4305C47.4844 27.6191 48.6664 27.6581 49.8489 27.7714C49.9078 27.7778 50.4232 27.8114 50.53 27.8482C50.7793 27.9324 50.8288 28.1252 50.8402 28.2172C50.8506 28.2941 50.8446 28.3885 50.7944 28.4939C50.7528 28.5801 50.6349 28.7253 50.4357 28.886C49.7992 29.4029 48.1397 30.3966 47.8848 30.5884C44.9622 32.7862 42.6161 35.3187 40.0788 37.9235C39.9097 38.0958 39.6311 38.1004 39.4566 37.9332C39.2821 37.766 39.2778 37.49 39.4459 37.3172C42.0151 34.6792 44.3946 32.1179 47.353 29.8939C47.5278 29.7615 48.5366 29.0813 49.2606 28.5952Z" fill="var(--theme-color)" />
                        </svg>
                        Save 17%
                    </div>
                </div>
            </div>
            <div id="monthly" class="wrapper-full">
                <div class="row justify-content-center">
					<?php foreach ($product_versions as $product_version){ 
						$product = new Product($product_version->get('prv_pro_product_id'), TRUE);
					?>
                    <div class="col-xl-4 col-md-6">
                        <div class="price-box th-ani ">
                            <div class="price-title-wrap">
                                <h3 class="box-title"><?php echo $product->get('pro_name'); ?></h3>
                                <!--<p class="subtitle">FREE</p>-->
                            </div>
                            <!--<p class="box-text">Perfect plan to get started</p>-->
							<?php echo $product->get('pro_short_description'); ?>
                            <h4 class="box-price">
								<?php 
								echo $product->get_readable_price($product_version->key);		
								?>
							<span class="duration">/<?php 
							
							echo $page_choice; 
							?></span></h4>
                            
                            <div class="box-content">
								<!--<p class="box-text2">A free plan grants you access to some cool features of Spend.</p>-->
                                <div class="available-list">
									<?php echo $product->get('pro_description'); ?>
                                    <!--<ul>
                                        <li>Limited Access Library</li>
                                        <li>Commercia License</li>
                                        <li>Hotline Support 24/7</li>
                                        <li class="unavailable">100+ HTML UI Elements</li>
                                        <li class="unavailable">WooCommerce Builder</li>
                                        <li class="unavailable">Updates for 1 Year</li>
                                    </ul>-->
                                </div>
                                <a href="<?php echo $product->get_url(). '?product_version_id='.$product_version->key; ?>" class="th-btn btn-fw style-radius">Get Started</a>
                            </div>
                        </div>
                    </div>
					<?php } ?>
					
                </div>
            </div>
			<!--
            <div id="yearly" class="wrapper-full hide">
                <div class="row justify-content-center">

                    <div class="col-xl-4 col-md-6">
                        <div class="price-box th-ani ">
                            <div class="price-title-wrap">
                                <h3 class="box-title">Basic Plan</h3>
                                <p class="subtitle">FREE</p>
                            </div>
                            <p class="box-text">Perfect plan to get started</p>
                            <h4 class="box-price">$199.00<span class="duration">/month</span></h4>
                            <p class="box-text2">A free plan grants you access to some cool features of Spend.</p>
                            <div class="box-content">
                                <div class="available-list">
                                    <ul>
                                        <li>Limited Access Library</li>
                                        <li>Commercia License</li>
                                        <li>Hotline Support 24/7</li>
                                        <li class="unavailable">100+ HTML UI Elements</li>
                                        <li class="unavailable">WooCommerce Builder</li>
                                        <li class="unavailable">Updates for 1 Year</li>
                                    </ul>
                                </div>
                                <a href="contact.html" class="th-btn btn-fw style-radius">Get Your Free Plan</a>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4 col-md-6">
                        <div class="price-box th-ani active">
                            <div class="price-title-wrap">
                                <h3 class="box-title">Standard Plan</h3>
                                <p class="subtitle">ULTIMATE</p>
                            </div>
                            <p class="box-text">Best suits for great company!</p>
                            <h4 class="box-price">$249.00<span class="duration">/month</span></h4>
                            <p class="box-text2">If you a finance manager at big company, this plan is a perfect match.</p>
                            <div class="box-content">
                                <div class="available-list">
                                    <ul>
                                        <li>Limited Access Library</li>
                                        <li>Commercia License</li>
                                        <li>Hotline Support 24/7</li>
                                        <li class="unavailable">100+ HTML UI Elements</li>
                                        <li class="unavailable">WooCommerce Builder</li>
                                        <li class="unavailable">Updates for 1 Year</li>
                                    </ul>
                                </div>
                                <a href="contact.html" class="th-btn btn-fw style-radius">Get Your Standard plan</a>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4 col-md-6">
                        <div class="price-box th-ani ">
                            <div class="price-title-wrap">
                                <h3 class="box-title">Premium Plan</h3>
                                <p class="subtitle">PRO</p>
                            </div>
                            <p class="box-text">Perfect plan for professionals!</p>
                            <h4 class="box-price">$299.00<span class="duration">/month</span></h4>
                            <p class="box-text2">For professional only! Start arranging your expenses with our best templates.</p>
                            <div class="box-content">
                                <div class="available-list">
                                    <ul>
                                        <li>Limited Access Library</li>
                                        <li>Commercia License</li>
                                        <li>Hotline Support 24/7</li>
                                        <li class="unavailable">100+ HTML UI Elements</li>
                                        <li class="unavailable">WooCommerce Builder</li>
                                        <li class="unavailable">Updates for 1 Year</li>
                                    </ul>
                                </div>
                                <a href="contact.html" class="th-btn btn-fw style-radius">Get Your Premium Plan</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
			-->
        </div>
    </section>











		<?php
 
	//echo PublicPage::EndPanel();
	echo PublicPage::EndPage(); 
	$page->public_footer($foptions=array('track'=>TRUE));

?>

