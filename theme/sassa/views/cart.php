<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPage.php', '/includes'));
	require_once (LibraryFunctions::get_logic_file_path('cart_logic.php'));

	$page_vars = cart_logic($_GET, $_POST);
	$cart = $page_vars['cart'];
	$currency_symbol = $page_vars['currency_symbol'];
	$currency_code = $page_vars['currency_code'];
	$session = $page_vars['session'];

	$page = new PublicPage(TRUE);
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Checkout'
	));

	echo PublicPage::BeginPage('Checkout');

	?>
<!--==============================
Contact Area   
==============================-->
    <div class="space">
        <div class="container">
            <div class="row gy-4 flex-row-reverse">
                <div class="col-lg-6 col-xl-7">
                    <div class="contact-item-wrap">

						
						
						
		<?php
		$settings = Globalvars::get_instance();
		if($settings->get_setting('coupons_active')){
			echo '<h4>Coupons</h4>';

			//DEBUG LIST ALL COUPONS
			if($session->get_permission() >= 8 && ($_SESSION['test_mode'] || $settings->get_setting('debug'))){
				echo '<div style="border: 3px solid blue; padding: 10px; margin: 10px;">Test mode:';
				foreach($page_vars['all_coupons'] as $coupon){
					$formwriter = LibraryFunctions::get_formwriter_object('form_test_coupon');
					echo $formwriter->begin_form("mt-6", "get", '/cart');
					echo $formwriter->hiddeninput('coupon_code',$coupon->get('ccd_code'));
					echo $formwriter->new_form_button('Add'.$coupon->get('ccd_code'), 'secondary');
					echo $formwriter->end_form();
				}
				echo '</div>';
			}

			
			$formwriter = LibraryFunctions::get_formwriter_object('form_coupon');
			echo $formwriter->begin_form("mt-6", "get", '/cart');
			echo $formwriter->textinput('Add Coupon Code', 'coupon_code', NULL, 64, NULL, '', 255, '');
			if($page_vars['coupon_error']){
				echo '<p>'.$page_vars['coupon_error'].'</p>';
			}
			//echo $formwriter->start_buttons();
			echo $formwriter->new_form_button('Add Coupon', 'secondary');
			//echo $formwriter->end_buttons();
			echo $formwriter->end_form();

			foreach($cart->coupon_codes as $coupon_code){
				$coupon_code_obj = CouponCode::GetByColumn('ccd_code', $coupon_code);
					?>
			
				<div class="media-body">
					<span class="contact-item_text">Coupon applied: <?php echo $coupon_code . ' ('.$coupon_code_obj->get_readable_discount(). ' discount ) <a href="/cart?rc='.$coupon_code.'">remove</a>'; ?></span>
				</div>
					
					
					<?php
				}			
		}
		
		if($session->get_permission() >= 8 && ($_SESSION['test_mode'] || $settings->get_setting('debug'))){
			echo '<div style="border: 3px solid red; padding: 10px; margin: 10px;">Using test mode with type '.$settings->get_setting('checkout_type').'</div>';
		}
		 ?>
		 </div>
		 <br>
		 <div class=" contact-item-wrap">
		<?php
		if($cart->get_total() > 0){			


				echo '<h4>Pay with Stripe</h4>';
				$formwriter = LibraryFunctions::get_formwriter_object('form_stripe');
				echo $page_vars['stripe_helper']->output_stripe_regular_form($formwriter, 'th-btn');	
			
			
			
		}			
				
		?>								
						
						
						
						
						
						
						
						
						
						<!--
                            <div class="form-group col-md-6">
                                <input type="text" class="form-control" name="name" id="name" placeholder="Your Name">
                                <i class="fal fa-user"></i>
                            </div>
                            <div class="form-group col-md-6">
                                <input type="email" class="form-control" name="email" id="email" placeholder="Email Address">
                                <i class="fal fa-envelope"></i>
                            </div>
                            <div class="form-group col-md-6">
                                <input type="tel" class="form-control" name="number" id="number" placeholder="Phone Number">
                                <i class="fal fa-phone"></i>
                            </div>
                            <div class="form-group col-md-6">
                                <select name="subject" id="subject" class="form-select nice-select">
                                    <option value="" disabled selected hidden>Select Service</option>
                                    <option value="Web Development">Web Development</option>
                                    <option value="Cyber Security">Cyber Security</option>
                                    <option value="App Development">App Development</option>
                                    <option value="Cloud Service">Cloud Service</option>
                                    <option value="Cloud Service">Cloud Service</option>
                                </select>
                            </div>
                            <div class="form-group col-12">
                                <textarea name="message" id="message" cols="30" rows="3" class="form-control" placeholder="Your Message"></textarea>
                                <i class="fal fa-pencil"></i>
                            </div>
                            <div class="form-btn col-12">
                                <button class="th-btn">Send Message</button>
                            </div>-->
                        </div>
                        <p class="form-messages mb-0 mt-3"></p>

                </div>
                <div class="col-lg-6 col-xl-5">
                    <div class="contact-item-wrap">
                        <div class="title-area mt-n2 mb-40">
							<h4>Cart</h4>

							<?php			
							$total_discount = 0;
							foreach($cart->items as $key => $cart_item) {
								list($quantity, $product, $data, $price, $discount) = $cart_item;
								$product_version = $product->get_product_versions(TRUE, $data['product_version']);

								$coupon_discount_words = '';
								//HANDLE COUPONS
								if($discount){
									$coupon_discount_words = ' ('.$currency_symbol.number_format($discount, 2, '.', ','). ' coupon)'; 
								}
								
								?>
                        <div class="contact-item">
                            <div class="contact-item_icon"><i class=""><img src="assets/img/icon/message.svg" alt=""></i>
                            </div>
                            <div class="media-body">
                                <span class="contact-item_title"><?php echo $product->get('pro_name').' '. $product_version->get('prv_version_name');?></span>
								<span class="contact-item_text"><?php echo $product->get_readable_price($product_version->key). $coupon_discount_words; ?></span>
                                <span class="contact-item_text"><a href="/cart?r=<?php echo $key; ?>">Remove</a></span>
								<p class=""><?php echo $product->get('pro_short_description'); ?></p>
                            </div>
                        </div>
				  
									  
								<?php
								$itemcount++;
							}	


							
							if($total_discount){
								
							?>
                        <div class="contact-item">
                            <div class="contact-item_icon"><i class=""><img src="assets/img/icon/message.svg" alt=""></i>
                            </div>
                            <div class="media-body">
                                <span class="contact-item_text">Discount <?php echo $currency_symbol.number_format($total_discount, 2, '.', ','); ?></span>
								<span class="contact-item_text"><?php echo $currency_symbol . number_format($price, 2, '.', ','). $coupon_discount_words; ?></span>
                                <span class="contact-item_title"><a href="/cart?r=<?php echo $key; ?>">Remove item</a></span>
                            </div>
                        </div>
						<?php 
							}
							?>

                            
                            
                     

                        <div class="contact-item">
                            
                            <div class="media-body">
                                <span class="contact-item_text">Total
                                    <?php echo $currency_symbol; ?><?php echo  number_format($cart->get_total() - $total_discount, 2, '.', ','); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>


           


					
	<?php
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>

