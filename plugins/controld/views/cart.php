<?php
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	// PathHelper is already loaded
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('cart_logic.php', 'logic', 'system', null, 'controld'));

	$page_vars = cart_logic($_GET, $_POST);
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
                <div class="col-lg-6 col-xl-7 order-2">
                    <div class="contact-item-wrap">

	
					<?php
					$settings = Globalvars::get_instance();
					if($settings->get_setting('coupons_active')){
						echo '<h4>Coupons</h4>';

						//DEBUG LIST ALL COUPONS
						if($session->get_permission() >= 8 && ($_SESSION['test_mode'] || $settings->get_setting('debug'))){
							echo '<div style="border: 3px solid blue; padding: 10px; margin: 10px;">Test mode:';
							foreach($page_vars['all_coupons'] as $coupon){
								$formwriter = $page->getFormWriter('form_test_coupon');
								echo $formwriter->begin_form("mt-6", "get", '/cart');
								echo $formwriter->hiddeninput('coupon_code',$coupon->get('ccd_code'));
								echo $formwriter->new_form_button('Add'.$coupon->get('ccd_code'), 'secondary', '', 'th-btn');
								echo $formwriter->end_form();
							}
							echo '</div>';
						}

						
						$formwriter = $page->getFormWriter('form_coupon');
						
						echo $formwriter->begin_form("mt-6", "get", '/cart');
						echo '<div style="display: flex; align-items: center;">';
						echo $formwriter->textinput('Coupon Code', 'coupon_code', NULL, 64, NULL, '', 255, '');
						
						if($page_vars['coupon_error']){
							echo '<p>'.$page_vars['coupon_error'].'</p>';
						}
						//echo $formwriter->start_buttons();
						echo $formwriter->new_form_button('Add', 'secondary', 'standard', 'th-btn ms-3');
						//echo $formwriter->end_buttons();
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
					}
					
					if($session->get_permission() >= 8 && ($_SESSION['test_mode'] || $settings->get_setting('debug'))){
						echo '<div style="border: 3px solid red; padding: 10px; margin: 10px;">Using test mode with type '.$settings->get_setting('checkout_type').'</div>';
					}
					?>
					</div>
					<br>
					 
					<div class="contact-item-wrap">
						<h4>Billing User</h4>
					
					
						<?php
					if($require_login){
							echo '<div class="alert alert-warning" role="alert">
							  The email ('.strip_tags($cart->billing_user['billing_email']).') you entered already exists in our system.  <a href="/login">Log in</a> to continue checkout or <a href="/cart_clear">clear the cart</a>.
							</div>';
					}
					else{							
						if($cart->is_billing_user_complete()){	
							echo '<p>'.$cart->billing_user['first_name'] . ' ' . $cart->billing_user['last_name'] . ' ('. $cart->billing_user['email'].')</p>';
							$formwriter = $page->getFormWriter('form_billing_user');
							
							//echo $formwriter->start_buttons();
							echo $formwriter->new_button('Change billing user', '/cart?newbilling=1', 'secondary', '', 'th-btn');
							//echo $formwriter->end_buttons();
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
							$formwriter = $page->getFormWriter('form2');
							$validation_rules = array();
							$validation_rules['billing_first_name']['required']['value'] = 'true';
							$validation_rules['billing_last_name']['required']['value'] = 'true';
							$validation_rules['billing_email']['required']['value'] = 'true';
							/*
							$validation_rules['billing_email']['required']['value'] = "function(element) { return $('#existing_billing_email option:selected').text() == 'A different person'; }";
							
							$validation_rules['billing_first_name']['required']['value'] = "function(element) { return $('#existing_billing_email option:selected').text() == 'A different person'; }";
							$validation_rules['billing_last_name']['required']['value'] = "function(element) { return $('#existing_billing_email option:selected').text() == 'A different person'; }";	
							*/
							if(!$session->get_user_id()){
								$validation_rules['password']['required']['value'] = 'true';
								$validation_rules['privacy']['required']['value'] = 'true';
							}
							

							echo $formwriter->set_validate($validation_rules);									

							/*
							$optionvals = array();
							$selected = '';
							foreach($cart->items as $key => $cart_item) {
								list($quantity, $product, $data, $price, $discount) = $cart_item;
								$name = $data['full_name_first'] . ' ' . $data['full_name_last'];
								$optionvals[$name] = $data['email'];
								if(!$selected){
									$selected = $name;
								}				
							}
							$optionvals['A different person'] = 'A different person';
							echo $formwriter->dropinput("Billing User", "existing_billing_email", NULL, $optionvals, $selected, '', FALSE);
							*/
							echo $formwriter->begin_form("", "post", "/cart");
							
							echo '<div id="new_billing">';

							echo $formwriter->textinput("First Name", "billing_first_name", NULL, 30, $cart->billing_user['first_name'], "", 255, "");
							echo $formwriter->textinput("Last Name", "billing_last_name", NULL, 30, $cart->billing_user['last_name'], "", 255, "");
							echo $formwriter->textinput("Email", "billing_email", NULL, 30, $cart->billing_user['email'], "", 255, ""); 
							if(!$session->get_user_id()){
								echo $formwriter->passwordinput("Create Password", "password", '', 20, "" , "", 255,"");
							
								echo $formwriter->checkboxinput("I consent to the terms of use and privacy policy.", "privacy", "", "left", NULL, 1, "");
							}
							echo '</div>';
							echo $formwriter->start_buttons();
							//echo $formwriter->new_button('Cancel', 'secondary');
							echo $formwriter->new_form_button('Submit Billing User', 'primary', '', 'th-btn');
							echo $formwriter->end_buttons();
							echo $formwriter->end_form();
							echo '<br><br>';
								
						}	
					}
					?>
					</div>
					<br>
					<?php 
					if($cart->is_billing_user_complete()){
					?>
						<div class=" contact-item-wrap">
						<?php
						if($cart->get_total() > 0){			
							echo '<h4>Pay with Stripe</h4>';
							$formwriter = $page->getFormWriter('form_stripe');
							echo $page_vars['stripe_helper']->output_stripe_regular_form($formwriter, 'th-btn');					
						}		
						?> </div> <?php
					}								

					?>
                    <p class="form-messages mb-0 mt-3"></p>			
					
                </div>
                <div class="col-lg-6 col-xl-5 order-1">
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

