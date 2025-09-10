<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	ThemeHelper::includeThemeFile('logic/cart_logic.php');

	$page_vars = cart_logic($_GET, $_POST);
	$cart = $page_vars['cart'];
	$currency_symbol = $page_vars['currency_symbol'];
	$page_vars['currency_code'] = $currency_code;
	$settings = Globalvars::get_instance();
	$require_login = $page_vars['require_login'];


	$page = new PublicPage();
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Checkout'
	));

	echo PublicPage::BeginPage('Checkout');

	?>


<div class="bg-white">
  <div class="max-w-7xl mx-auto px-4 pt-4 pb-16 sm:px-6 sm:pt-8 sm:pb-24 lg:px-8 xl:px-2 xl:pt-14">
    <h1 class="sr-only">Checkout</h1>

    <div class="max-w-lg mx-auto grid grid-cols-1 gap-x-8 gap-y-16 lg:max-w-none lg:grid-cols-2">
      <div class="max-w-lg mx-auto w-full">
        <h2 class="sr-only">Order summary</h2>

        <div class="flow-root">
          <ul role="list" class="-my-6 divide-y divide-gray-200">
		  
			<?php			
			$total_discount = 0;
			foreach($cart->items as $key => $cart_item) {
				list($quantity, $product, $data, $price, $discount) = $cart_item;
				$coupon_discount_words = '';
				//HANDLE COUPONS
				if($discount){
					$coupon_discount_words = ' ('.$currency_symbol.number_format($discount, 2, '.', ','). ' discount)'; 
				}
				
				?>
				<li class="py-6 flex space-x-6">
				  <!--<img src="https://tailwindui.com/img/ecommerce-images/checkout-page-05-product-01.jpg" alt="Front of women&#039;s basic tee in heather gray." class="flex-none w-24 h-24 object-center object-cover bg-gray-100 rounded-md">-->
				  <div class="flex-auto">
					<div class="space-y-1 sm:flex sm:items-start sm:justify-between sm:space-x-6">
					  <div class="flex-auto text-sm font-medium space-y-1">
						<h3 class="text-gray-900">
							<?php
						  echo '<a href="#">'.htmlspecialchars($product->get('pro_name'), ENT_QUOTES, 'UTF-8').' '. htmlspecialchars($product_version->get('prv_version_name'), ENT_QUOTES, 'UTF-8') . ' ('. htmlspecialchars($data['full_name_first'], ENT_QUOTES, 'UTF-8'). ' ' .htmlspecialchars($data['full_name_last'], ENT_QUOTES, 'UTF-8'). ') '.'</a>';
							?>
						</h3>
						<?php echo '<p class="text-gray-900">'.$currency_symbol . number_format($price, 2, '.', ','). $coupon_discount_words.'</p>'; ?>
						<!--<p class="hidden text-gray-500 sm:block">Gray</p>
						<p class="hidden text-gray-500 sm:block">S</p>-->
					  </div>
					  <div class="flex-none flex space-x-4">
						<!--<button type="button" class="text-sm font-medium text-indigo-600 hover:text-indigo-500">Edit</button>-->
						<div class="flex border-l border-gray-300 pl-4">
						  <a href="/cart?r=<?php echo $key; ?>"<button type="button" class="text-sm font-medium text-indigo-600 hover:text-indigo-500">Remove</button></a>
						</div>
					  </div>
					</div>
				  </div>
				</li>
				<?php
				$itemcount++;
			}	
			?>


            <!-- More products... -->
          </ul>
        </div>

        <dl class="text-sm font-medium text-gray-500 mt-10 space-y-6">
          <div class="flex justify-between">
            <dt>Subtotal</dt>
            <dd class="text-gray-900"><?php echo $currency_symbol; ?><?php echo  number_format($cart->get_total() - $total_discount, 2, '.', ','); ?></dd>
          </div>
		  <?php
		  if($total_discount){
			  echo '
			  <div class="flex justify-between">
				<dt>Taxes</dt>
				<dd class="text-gray-900">'.$currency_symbol.number_format($total_discount, 2, '.', ',').'</dd>
			  </div>';
		  }
		  ?>
          <!--<div class="flex justify-between">
            <dt>Shipping</dt>
            <dd class="text-gray-900">$14.00</dd>
          </div>-->
          <div class="flex justify-between border-t border-gray-200 text-gray-900 pt-6">
            <dt class="text-base">Total</dt>
            <dd class="text-base"><?php echo $currency_symbol; ?><?php echo  number_format($cart->get_total() - $total_discount, 2, '.', ','); ?></dd>
          </div>
        </dl>
      </div>

      <div class="max-w-lg mx-auto w-full">
	  

		<?php									
		if($cart->billing_user){	
			echo '<h2 class="text-lg font-medium text-gray-900">Billing User</h2>';
			echo '<p>'.htmlspecialchars($cart->billing_user['billing_first_name'], ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars($cart->billing_user['billing_last_name'], ENT_QUOTES, 'UTF-8') . ' ('. htmlspecialchars($cart->billing_user['billing_email'], ENT_QUOTES, 'UTF-8').')</p>';
			$formwriter = $page->getFormWriter('form_billing_user');
			
			//echo $formwriter->start_buttons();
			echo $formwriter->new_button('Change billing user', '/cart?newbilling=1', 'secondary');
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
			$validation_rules['billing_email']['required']['value'] = "function(element) { return $('#existing_billing_email option:selected').text() == 'A different person'; }";
			$validation_rules['billing_email']['required']['value'] = 'true';
			$validation_rules['billing_first_name']['required']['value'] = "function(element) { return $('#existing_billing_email option:selected').text() == 'A different person'; }";
			$validation_rules['billing_last_name']['required']['value'] = "function(element) { return $('#existing_billing_email option:selected').text() == 'A different person'; }";		
			$validation_rules['password']['required']['value'] = 'true';
			echo $formwriter->set_validate($validation_rules);									

			echo $formwriter->begin_form("mt-6", "post", "/cart");
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
			
			echo $formwriter->dropinput("Choose one", "existing_billing_email", NULL, $optionvals, $selected, '', FALSE);
			*/
			echo '<div id="new_billing">';
			echo $formwriter->textinput("Billing First Name", "billing_first_name", NULL, 30, htmlspecialchars($cart->billing_user['first_name'], ENT_QUOTES, 'UTF-8'), "", 255, "");
			echo $formwriter->textinput("Billing Last Name", "billing_last_name", NULL, 30, htmlspecialchars($cart->billing_user['last_name'], ENT_QUOTES, 'UTF-8'), "", 255, "");
			echo $formwriter->textinput("Billing Email", "billing_email", NULL, 30, htmlspecialchars($cart->billing_user['email'], ENT_QUOTES, 'UTF-8'), "", 255, ""); 
			echo $formwriter->passwordinput("Create Password", "password", 'sm:col-span-2', 20, "" , "", 255,"");
			
			echo $formwriter->checkboxinput("I consent to the terms of use and privacy policy.", "privacy", "sm:col-span-6", "left", NULL, 1, "");

			echo '</div>';
			echo $formwriter->start_buttons();
			//echo $formwriter->new_button('Cancel', 'secondary');
			echo $formwriter->new_form_button('Submit Billing User');
			echo $formwriter->end_buttons();
			echo $formwriter->end_form();
			echo '<br><br>';
				
		}

		
		if($settings->get_setting('coupons_active')){
			echo '<h2 class="text-lg font-medium text-gray-900">Coupon Codes</h2>';

			/*echo ' <div class="relative mt-8">
			  <div class="absolute inset-0 flex items-center" aria-hidden="true">
				<div class="w-full border-t border-gray-200"></div>
			  </div>
			  <div class="relative flex justify-center">
				<span class="px-4 bg-white text-sm font-medium text-gray-500">
				  <>
				</span>
			  </div>
			</div>';*/
			foreach($cart->coupon_codes as $coupon_code){
				echo 'Applied: '.$coupon_code.' <a href="/cart?clear_coupon_code='.$coupon_code.'">remove</a><br><br>';
			}


			//DEBUG LIST ALL COUPONS
			if(StripeHelper::isTestMode()){
				echo '<div style="border: 3px solid blue; padding: 10px; margin: 10px;">Test mode:';
				foreach($page_vars['all_coupons'] as $coupon){

					$formwriter = $page->getFormWriter('form_test_coupon');
					echo $formwriter->begin_form("mt-6", "get", '/cart');

					echo $formwriter->hiddeninput('coupon_code',$coupon->get('ccd_code'));
					
					echo $formwriter->new_form_button('Add'.$coupon->get('ccd_code'), 'secondary');


					echo $formwriter->end_form();
				}
				echo '</div>';
			}

			$formwriter = $page->getFormWriter('form_coupon');
			echo $formwriter->begin_form("mt-6", "get", '/cart');

			echo $formwriter->textinput('Add Coupon Code', 'coupon_code', NULL, 64, NULL, '', 255, '');
			if($page_vars['coupon_error']){
				echo '<p>'.htmlspecialchars($page_vars['coupon_error'], ENT_QUOTES, 'UTF-8').'</p>';
			}
			//echo $formwriter->start_buttons();
			echo $formwriter->new_form_button('Add Coupon', 'secondary');
			//echo $formwriter->end_buttons();

			echo $formwriter->end_form();
			
		}
		
		if(StripeHelper::isTestMode()){
			echo '<div style="border: 3px solid red; padding: 10px; margin: 10px;">Using test mode with type '.$settings->get_setting('checkout_type').'</div>';
		}
		
		if($require_login){
				echo '<div class="alert alert-warning" role="alert">
				  The email ('.htmlspecialchars($cart->billing_user['billing_email'], ENT_QUOTES, 'UTF-8').') you entered already exists in our system.  <a href="/login">Log in</a> to continue checkout or <a href="/cart_clear">clear the cart</a>.
				</div>';
		}
		else{
			if($cart->get_total() > 0 && $cart->billing_user['billing_email']){			


				if($settings->get_setting('checkout_type') == 'stripe_checkout'){	
					echo '<h2 class="text-lg mb-3 font-medium text-gray-900">Pay with Stripe</h5>';
					echo $page_vars['stripe_helper']->output_stripe_checkout_form($cart->get_hash());									
					
				}
				else{	
				/*
					?>	
					 <div class="relative mt-8">
					  <div class="absolute inset-0 flex items-center" aria-hidden="true">
						<div class="w-full border-t border-gray-200"></div>
					  </div>
					  <div class="relative flex justify-center">
						<span class="px-4 bg-white text-sm font-medium text-gray-500">
						  <>
						</span>
					  </div>
					</div>
					<?php*/
					echo '<h2 class="text-lg mb-3 font-medium text-gray-900">Pay with Stripe</h5>';
					echo $page_vars['stripe_helper']->output_stripe_regular_form($formwriter, '');	
				}
				
				if($settings->get_setting('use_paypal_checkout') && $page_vars['paypal_helper']){

					if($cart->get_num_recurring() == 1 && $cart->get_num_non_recurring() == 0){
						//PAYPAL
						echo '<h2 class="text-lg mb-3 font-medium text-gray-900">Pay with Paypal</h5>';
						echo $page_vars['paypal_helper']->output_paypal_subscription_checkout_code($page_vars['plan_id']);
					}
					else if($cart->get_num_recurring() == 0){
						//PAYPAL
						echo '<h2 class="text-lg mb-3 font-medium text-gray-900">Pay with Paypal</h5>';
						echo $page_vars['paypal_helper']->output_paypal_checkout_code($page_vars['paypal_item_list']);
					}
					else{
						//PAYPAL
						echo '<h2 class="text-lg mb-3 font-medium text-gray-900">Pay with Paypal</h5>';
						echo '<p><b>Paypal subscriptions must be purchased individually.  Remove all other items from your cart to pay with Paypal.</b></p>'; 				
					}
				}
			}			
			else if($cart->billing_user){					
				$formwriter = $page->getFormWriter('form4');
				echo $formwriter->begin_form("mt-6", "post", '/cart_charge');
				echo $formwriter->hiddeninput('novalue', '');
				echo $formwriter->start_buttons();
				echo $formwriter->new_form_button('Submit', 'primary', 'full');
				echo $formwriter->end_buttons();
				echo $formwriter->end_form();						
			}		
		}
		?>			

      </div>
    </div>
  </div>
</div>

					
	<script>
	$(document).ready(function() {
		// Disable all submit buttons after first click to prevent duplicate submissions
		$('form').on('submit', function() {
			var $form = $(this);
			var $submitButtons = $form.find('button[type="submit"], input[type="submit"]');
			
			// Disable buttons and show loading state
			$submitButtons.prop('disabled', true);
			$submitButtons.each(function() {
				var $btn = $(this);
				$btn.data('original-text', $btn.text());
				$btn.text('Processing...');
			});
			
			// Re-enable after 10 seconds as failsafe (in case of network issues)
			setTimeout(function() {
				$submitButtons.prop('disabled', false);
				$submitButtons.each(function() {
					var $btn = $(this);
					if ($btn.data('original-text')) {
						$btn.text($btn.data('original-text'));
					}
				});
			}, 10000);
			
			return true; // Allow form submission to proceed
		});
	});
	</script>

	<?php
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>

