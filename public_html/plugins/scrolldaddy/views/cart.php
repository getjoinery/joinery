<?php
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	// PathHelper is already loaded
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('cart_logic.php', 'logic'));

	$page_vars = process_logic(cart_logic($_GET, $_POST));
	$cart = $page_vars['cart'];
	$currency_symbol = $page_vars['currency_symbol'];
	$currency_code = $page_vars['currency_code'];
	$session = $page_vars['session'];
	$require_login = $page_vars['require_login'];
	$prefill_billing_user = $page_vars['prefill_billing_user'];

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
                        <div class="title-area mt-n2 mb-25">
                            <h3 class="sec-title">Cart</h3>
                        </div>

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
						
						



					<!-- Coupons Section -->
					<?php
					$settings = Globalvars::get_instance();
					if($settings->get_setting('coupons_active')){
					?>
					<div class="contact-item-wrap mt-40">
                        <div class="title-area mt-n2 mb-25">
                            <h3 class="sec-title">Coupons</h3>
                        </div>

						<?php
						//DEBUG LIST ALL COUPONS
						if($session->get_permission() >= 8 && ($_SESSION['test_mode'] || $settings->get_setting('debug'))){
							echo '<div style="border: 3px solid blue; padding: 10px; margin: 10px;">Test mode:';
							foreach($page_vars['all_coupons'] as $coupon){
								$formwriter = $page->getFormWriter('form_test_coupon', ['action' => '/cart', 'method' => 'GET']);
								$formwriter->begin_form();
								echo $formwriter->hiddeninput('coupon_code', $coupon->get('ccd_code'));
								echo $formwriter->submitbutton('btn_submit', 'Add '.$coupon->get('ccd_code'), ['class' => 'btn btn-secondary']);
								echo $formwriter->end_form();
							}
							echo '</div>';
						}


						$formwriter = $page->getFormWriter('form_coupon', ['action' => '/cart', 'method' => 'GET']);
						$formwriter->begin_form();
						echo '<div style="display: flex; align-items: center;">';
						echo $formwriter->textinput('coupon_code', 'Coupon Code', ['maxlength' => 255]);

						if($page_vars['coupon_error']){
							echo '<p>'.$page_vars['coupon_error'].'</p>';
						}
						echo $formwriter->submitbutton('btn_submit', 'Add', ['class' => 'btn btn-primary']);
						echo $formwriter->end_form();
						echo '</div>';

						foreach($cart->coupon_codes as $coupon_code){
							$coupon_code_obj = CouponCode::GetByColumn('ccd_code', $coupon_code);
						?>
						<div class="media-body">
							<span class="contact-item_text">Coupon applied: <?php echo $coupon_code . ' ('.$coupon_code_obj->get_readable_discount(). ' discount ) <a href="/cart?rc='.$coupon_code.'">remove</a>'; ?></span>
						</div>
						<?php
						}
						?>
					</div>
					<?php
					}
					?>
					
					
                    
                </div>
                <div class="col-lg-6 col-xl-5">
                    <div class="contact-item-wrap">
                        <div class="title-area mt-n2 mb-40">
                            <h3 class="sec-title">Billing User</h3>
                        </div>
						<?php
					if($require_login){
							echo '<div class="alert alert-warning" role="alert">
							  The email ('.strip_tags($cart->billing_user['billing_email']).') you entered already exists in our system.  <a href="/login">Log in</a> to continue checkout or <a href="/cart_clear">clear the cart</a>.
							</div>';
					}
					else{							
						if($cart->is_billing_user_complete()){
							echo '<p>'.$cart->billing_user['billing_first_name'] . ' ' . $cart->billing_user['billing_last_name'] . ' ('. $cart->billing_user['billing_email'].')</p>';
							echo '<a href="/cart?newbilling=1" class="btn btn-secondary">Change billing user</a>';
							echo '<br><br>';
						}
						else{
							/*
							?>
							<script>
							$(document).ready(function() {
								$("#usr_first_name").focus();
								$('#new_billing').hide();
								$('#existing_billing_email').change(function () {
									if ($('#existing_billing_email option:selected').text() == 'A different person') {
										$('#new_billing').show();
									}
									else $('#new_billing').hide(); // hide div if value is not "custom"
								});
							});
							</script>
							<?php	
							*/
							$formwriter = $page->getFormWriter('form2', ['action' => '/cart']);
							$formwriter->begin_form();
							echo '<div id="new_billing">';
							echo $formwriter->textinput('billing_first_name', 'First Name', ['value' => htmlspecialchars($cart->billing_user['billing_first_name'], ENT_QUOTES, 'UTF-8'), 'maxlength' => 255, 'required' => true]);
							echo $formwriter->textinput('billing_last_name', 'Last Name', ['value' => htmlspecialchars($cart->billing_user['billing_last_name'], ENT_QUOTES, 'UTF-8'), 'maxlength' => 255, 'required' => true]);
							echo $formwriter->textinput('billing_email', 'Email', ['value' => htmlspecialchars($cart->billing_user['billing_email'], ENT_QUOTES, 'UTF-8'), 'maxlength' => 255, 'required' => true, 'type' => 'email']);
							if(!$session->get_user_id()){
								echo $formwriter->passwordinput('password', 'Create Password', ['required' => true]);
								echo $formwriter->checkboxinput('privacy', 'I consent to the terms of use and privacy policy.', ['required' => true]);
							}
							echo '</div>';
							echo $formwriter->submitbutton('btn_submit', 'Submit Billing User', ['class' => 'btn btn-primary']);
							echo $formwriter->end_form();
							echo '<br><br>';
						}
					}
					?>
                    </div>




					<?php 
					if($cart->is_billing_user_complete()){
					?>
						<div class="contact-item-wrap mt-40">
                        <div class="title-area mt-n2 mb-25">
                            <h3 class="sec-title">Checkout</h3>
                        </div>
						<?php
						if($cart->get_total() > 0){			
							$formwriter = $page->getFormWriter('form_stripe');
							echo $page_vars['stripe_helper']->output_stripe_regular_form($formwriter, 'th-btn');					
						}		
						?>  
						</div>
					<?php
					}									
					?>




                    
					
					
                </div>
            </div>
        </div>
    </div>










           

					
	<?php
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>

