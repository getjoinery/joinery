<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPage.php', '/includes'));
	require_once (LibraryFunctions::get_logic_file_path('cart_logic.php'));

	$page_vars = cart_logic($_GET, $_POST);
	$cart = $page_vars['cart'];
	$currency_symbol = $page_vars['currency_symbol'];
	$page_vars['currency_code'] = $currency_code;

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
						  echo '<a href="#">'.$product->get('pro_name').' '. $product_version->get('prv_version_name') . ' ('. $data['full_name_first']. ' ' .$data['full_name_last']. ') '.'</a>';
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
	  

        <!--<form class="mt-6">-->

		<?php									
		if($cart->billing_user){	
			echo '<h2 class="text-lg font-medium text-gray-900">Billing User</h2>';
			echo '<p>'.$cart->billing_user['billing_first_name'] . ' ' . $cart->billing_user['billing_last_name'] . ' ('. $cart->billing_user['billing_email'].')</p>';
			$formwriter = LibraryFunctions::get_formwriter_object('form_billing_user', 'tailwind');
			
			//echo $formwriter->start_buttons();
			echo $formwriter->new_button('Change billing user', '/cart?newbilling=1', 'secondary');
			//echo $formwriter->end_buttons();
			$billing_user = User::GetByEmail(trim($cart->billing_user['billing_email']));
			echo '<br><br>';
		}
		else{
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
			$formwriter = LibraryFunctions::get_formwriter_object('form2', 'tailwind');
			$validation_rules = array();
			$validation_rules['billing_email']['required']['value'] = "function(element) { return $('#existing_billing_email option:selected').text() == 'A different person'; }";
			$validation_rules['billing_email']['required']['value'] = 'true';
			$validation_rules['billing_first_name']['required']['value'] = "function(element) { return $('#existing_billing_email option:selected').text() == 'A different person'; }";
			$validation_rules['billing_last_name']['required']['value'] = "function(element) { return $('#existing_billing_email option:selected').text() == 'A different person'; }";										  
			echo $formwriter->set_validate($validation_rules);									

			echo $formwriter->begin_form("mt-6", "post", "/cart");
			
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
			echo '<div id="new_billing">';
			echo $formwriter->textinput("Billing First Name", "billing_first_name", NULL, 30, '', "", 255, "");
			echo $formwriter->textinput("Billing Last Name", "billing_last_name", NULL, 30, '', "", 255, "");
			echo $formwriter->textinput("Billing Email", "billing_email", NULL, 30, '', "", 255, ""); 
			
			echo $formwriter->checkboxinput("I consent to the privacy policy.", "privacy", "sm:col-span-6", "left", NULL, 1, "");

			echo '</div>';
			echo $formwriter->start_buttons();
			//echo $formwriter->new_button('Cancel', 'secondary');
			echo $formwriter->new_form_button('Submit Billing User');
			echo $formwriter->end_buttons();
			echo $formwriter->end_form();
			echo '<br><br>';
				
		}

		$settings = Globalvars::get_instance();
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
			if($_SESSION['test_mode'] || $settings->get_setting('debug')){
				echo '<div style="border: 3px solid blue; padding: 10px; margin: 10px;">Test mode:';
				foreach($page_vars['all_coupons'] as $coupon){

					$formwriter = LibraryFunctions::get_formwriter_object('form_test_coupon', 'tailwind');
					echo $formwriter->begin_form("mt-6", "get", '/cart');

					echo $formwriter->hiddeninput('coupon_code',$coupon->get('ccd_code'));
					
					echo $formwriter->new_form_button('Add'.$coupon->get('ccd_code'), 'secondary');


					echo $formwriter->end_form();
				}
				echo '</div>';
			}

			$formwriter = LibraryFunctions::get_formwriter_object('form_coupon', 'tailwind');
			echo $formwriter->begin_form("mt-6", "get", '/cart');

			echo $formwriter->textinput('Add Coupon Code', 'coupon_code', NULL, 64, NULL, '', 255, '');
			if($page_vars['coupon_error']){
				echo '<p>'.$page_vars['coupon_error'].'</p>';
			}
			//echo $formwriter->start_buttons();
			echo $formwriter->new_form_button('Add Coupon', 'secondary');
			//echo $formwriter->end_buttons();

			echo $formwriter->end_form();
			
		}
		
		if($_SESSION['test_mode'] || $settings->get_setting('debug')){
			echo '<div style="border: 3px solid red; padding: 10px; margin: 10px;">Using test mode with type '.$settings->get_setting('checkout_type').'</div>';
		}
		
		
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
			$formwriter = LibraryFunctions::get_formwriter_object('form4', 'tailwind');
			echo $formwriter->begin_form("mt-6", "post", '/cart_charge');
			echo $formwriter->hiddeninput('novalue', '');
			echo $formwriter->start_buttons();
			echo $formwriter->new_form_button('Submit', 'primary', 'full');
			echo $formwriter->end_buttons();
			echo $formwriter->end_form();						
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

