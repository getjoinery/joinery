<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/stripe-php/init.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/PublicPage.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublic.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/ShoppingCart.php');

	require_once($_SERVER['DOCUMENT_ROOT'].'/data/products_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/address_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/users_class.php');

	$session = SessionControl::get_instance();
	//$session->check_permission(0);

	$cart = $session->get_shopping_cart();
	

	if (isset($_REQUEST['r']) && is_numeric($_REQUEST['r'])) {
		$cart->remove_item(intval($_REQUEST['r']));
	}
	
	$settings = Globalvars::get_instance();
	$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')];
	
	if(!$_SESSION['test_mode']){
		$api_key = $settings->get_setting('stripe_api_key');
		$api_secret_key = $settings->get_setting('stripe_api_pkey');
	}
	else{
		$api_key = $settings->get_setting('stripe_api_key_test');
		$api_secret_key = $settings->get_setting('stripe_api_pkey_test');		
	}

	\Stripe\Stripe::setApiKey($api_key);
	$session = SessionControl::get_instance();
	
	if ($session->get_user_id()) {
		$user = new User($session->get_user_id(), TRUE);
	}
	else{
		$user = NULL;
	}	

	if($_POST['existing_billing_email']){
		$billing_user = array();
		if($_POST['existing_billing_email'] == 'A different person'){
			$billing_user['billing_first_name'] = $_POST['billing_first_name'];
			$billing_user['billing_last_name'] = $_POST['billing_last_name'];
			$billing_user['billing_email'] = strtolower(trim($_POST['billing_email']));
			$cart->billing_user = $billing_user;
			
		}
		else{
			foreach($cart->items as $key => $cart_item) {
				list($quantity, $product, $data) = $cart_item;
				if(strtolower(trim($_POST['existing_billing_email'])) == strtolower(trim($data['email']))){								
					$billing_user['billing_first_name'] = $data['full_name_first'];
					$billing_user['billing_last_name'] = $data['full_name_last'];
					$billing_user['billing_email'] = strtolower(trim($data['email']));
					$cart->billing_user = $billing_user;
				}				
			}
			
		}
		
	}				
	else if($cart->count_items() > 0 && !$cart->billing_user && !$newbilling){
		//IF AT LEAST ONE ITEM IN CART, LOAD FIRST AS BILLING USER
		foreach($cart->items as $key => $cart_item) {}  //SHORTCUT TO GET ONLY ONE
		list($quantity, $product, $data) = $cart_item;
		
		$billing_user['billing_first_name'] = $data['full_name_first'];
		$billing_user['billing_last_name'] = $data['full_name_last'];
		$billing_user['billing_email'] = strtolower(trim($data['email']));
		$cart->billing_user = $billing_user;
	}	


	$page = new PublicPage(TRUE);
	$page->public_header(array(
	'title' => 'Checkout',
	'profilenav' => TRUE,
	));
	
	$rownum=0;
	
	if($_GET['newbilling'] == 1){
		$cart->billing_user = NULL;
	}
?>

		<div class="section padding-top-20">
			<div class="container">
				<div class="row">


	<div id="content-body"> 	
		<div class="body-title"> 
	        <h1>Checkout</h1> 
		</div> 

    	<div class="rounded-940-padded"> 
    		<div class="top-corners-940"><!--For IE--></div> 


			<?php
		
			if (count($cart->get_items()) === 0) {
				// Cart is empty, can't checkout!
				?>
				<p>Your shopping cart is currently empty.</p>
				<?php
			} else {
				$stripe_item_list = array();
				foreach($cart->get_detailed_items() as $cart_item) {

					if($cart_item['recurring']){
						
						//CHECK FOR EXISTING PLAN
						try{
							$plan_name = 'recurring_donation-' . (int)$cart_item['price'];
							$plan = \Stripe\Plan::retrieve($plan_name);
						}
						catch (Exception $e) {
							//CREATE NEW PLAN
							$plan = \Stripe\Plan::create([
							  "amount" => (int)$cart_item['price'] * 100,
							  "interval" => "month",
							  "product" => [
								"name" => 'Recurring donation $' . (int)$cart_item['price'],
							  ],
							  "currency" => "usd",
							  "id" => 'recurring_donation-' . (int)$cart_item['price'],
							]); 							
						}

						$plan_items = array(
							'plan' => $plan['id'],
						);
						
						$plan_items_wrap = array($plan_items);
						
						$stripe_subscription_item = array(
							'items' => $plan_items_wrap,
						);
						
					}
					else{
						//ASSEMBLE THE STRIPE PRODUCT ARRAY
						//'images' => ['https://example.com/t-shirt.png'],
						$stripe_current_item = array(
							'name' => $cart_item['name'],
							'description' => $cart_item['name'].' ',			
							'amount' => (int)$cart_item['price'] * 100,
							'currency' => 'usd',
							'quantity' => $cart_item['quantity'],
						);
						
						//TODO add description "metadata" => ["order_id" => "6735"],
						if($cart_item['price'] > 0){
							array_push($stripe_item_list, $stripe_current_item);		
						}							
					}
				}		
				
				
				
	

				
			
				?>
				<h3>Shopping Cart</h3> (<a style="align:right;" href="/cart_clear">clear cart</a>)
				<?php
				
					$headers = array('Item', 'Description', 'Price', '');
					$page->tableheader($headers, 'table cart-table');
					


					foreach($cart->items as $key => $cart_item) {
						$rowvalues = array();
						list($quantity, $product, $data) = $cart_item;
						$product_version = $product->get_product_version($data);

						$price = $product->get_price($product_version, $data);

						array_push($rowvalues, $key+1);
						array_push($rowvalues, $product->get('pro_name').' '. $product_version->prv_version_name . ' ('. $data['full_name_first']. ' ' .$data['full_name_last']. ') ');
						array_push($rowvalues, $currency_symbol . money_format('%i', $price));
						array_push($rowvalues, '<span class="icon-remove"><a href="/cart?r=' . $key	. '">Remove</a></span>');
						$page->disprow($rowvalues);
				
						$itemcount++;
					}	
					$page->endtable();		
					?>
					<p class="cart-total">Total: <?php echo $currency_symbol; ?><?php echo  money_format('%i', $cart->get_total()); ?>			
					<span style="float:right;">(<a href="/cart_clear">clear cart</a>)</span>
					</p> 				
					</div>			

					<div class="col-12 col-xl-6">
						<div class="bg-grey padding-20 padding-md-30 padding-lg-40">
						<script>
						$(document).ready(function() {
							$('#nojavascript').hide();
						});
						</script>
						
						
					<?php									
					if($cart->billing_user){	
						echo '<h5 class="font-weight-medium">Billing User</h5>';
						echo '<p>'.$cart->billing_user['billing_first_name'] . ' ' . $cart->billing_user['billing_last_name'] . ' ('. $cart->billing_user['billing_email'].') <a href="/cart?newbilling=1">change billing user</a></p>';
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
						$formwriter = new FormWriterPublic("form1", TRUE);
						$validation_rules = array();
						$validation_rules['billing_email']['required']['value'] = "function(element) { return $('#existing_billing_email option:selected').text() == 'A different person'; }";
						$validation_rules['billing_email']['required']['value'] = 'true';
						$validation_rules['billing_first_name']['required']['value'] = "function(element) { return $('#existing_billing_email option:selected').text() == 'A different person'; }";
						$validation_rules['billing_last_name']['required']['value'] = "function(element) { return $('#existing_billing_email option:selected').text() == 'A different person'; }";										  
						echo $formwriter->set_validate($validation_rules);									

						echo $formwriter->begin_form("uniForm", "post", "/cart");
						
						$optionvals = array();
						$selected = '';
						foreach($cart->items as $key => $cart_item) {
							list($quantity, $product, $data) = $cart_item;
							$name = $data['full_name_first'] . ' ' . $data['full_name_last'];
							$optionvals[$name] = $data['email'];
							if(!$selected){
								$selected = $name;
							}				
						}
						$optionvals['A different person'] = 'A different person';
						echo $formwriter->dropinput("Billing User", "existing_billing_email", "ctrlHolder", $optionvals, $selected, '', FALSE);
						echo '<div id="new_billing">';
						echo $formwriter->textinput("Billing First Name", "billing_first_name", "ctrlHolder", 30, '', "", 255, "");
						echo $formwriter->textinput("Billing Last Name", "billing_last_name", "ctrlHolder", 30, '', "", 255, "");
						echo $formwriter->textinput("Billing Email", "billing_email", "ctrlHolder", 30, '', "", 255, ""); 
						//echo $formwriter->checkboxinput("I consent to the privacy policy.", "privacy", "checkbox", "left", NULL, 1, "");
						echo '</div>';
						echo $formwriter->new_form_button('Submit Billing User', 'button button-dark');
						echo $formwriter->end_form();
						echo '<br><br>';
							
					}

		
	
					
		
				
				
				
				$create_list = array(
					'billing_address_collection' => 'auto',
					'payment_method_types' => ['card'],
					'success_url' => 'https://jeremytunnell.net/cart_finish-checkout?session_id={CHECKOUT_SESSION_ID}',
					'cancel_url' => 'https://jeremytunnell.net/cart',
				);
				
				if($stripe_item_list){
					$create_list['line_items'] = $stripe_item_list;
				}
				
				if($stripe_subscription_item){
					$create_list['subscription_data'] = $stripe_subscription_item;
				}
				
				if(!$_SESSION['test_mode']){
					if($billing_user){
						$create_list['client_reference_id'] = $billing_user->key;
					
						if($billing_user->get('usr_stripe_customer_id')){
							if(!$_SESSION['test_mode']){
								$create_list['customer'] = $billing_user->get('usr_stripe_customer_id');
							}
						}
						elseif($billing_user->get('usr_email')){
							$create_list['customer_email'] = $billing_user->get('usr_email');		
						}				
					}
					else{
						$create_list['customer_email'] = $billing_user['billing_email'];
					}
				}
				
			
				if($cart->get_total() > 0){
					$stripe_session = \Stripe\Checkout\Session::create($create_list);					
				
				
					?>
					<script src="https://js.stripe.com/v3/"></script>
					<script language="javascript">
					var stripe = Stripe('<?php echo $api_secret_key; ?>');

					function ToCheckout() {
						stripe.redirectToCheckout({
						  sessionId: '<?php echo $stripe_session->id; ?>'
						}).then(function (result) {
						  // If `redirectToCheckout` fails due to a browser or network
						  // error, display the localized error message to your customer
						  // using `result.error.message`.
						});
					}
					</script>
					<?php
				}
				if($cart->billing_user){	
					if($cart->get_total() > 0){
						$formwriter = new FormWriterPublic("form1", TRUE);
						$formwriter->begin_form("uniForm", "post", '/profile/payment_finalize');

						echo '<div id="errorMsg" style="display:none;"></div>';
						echo '<fieldset class="inlineLabels">';

						$formwriter->hiddeninput('cc_type', '');
						$formwriter->hiddeninput('cart_cs', $cart->get_hash());
						
						$formwriter->start_buttons();
						echo '<input type="button" value="Pay" onclick="ToCheckout();" style="width:100px;">';
						//$formwriter->new_form_button('Checkout');
						$formwriter->end_buttons();

						$formwriter->end_form();
					}
					else{
						$formwriter = new FormWriterPublic("form1", TRUE);
						$formwriter->begin_form("uniForm", "post", '/cart_finish');

						echo '<fieldset class="inlineLabels">';
						$formwriter->hiddeninput('novalue', '');
						$formwriter->start_buttons();
						$formwriter->new_form_button('Submit');
						$formwriter->end_buttons();

						$formwriter->end_form();						
					}
				}		
		}


			?>
       <div class="bottom-corners-940"><!--For IE--></div>
    </div><!--End of .rounded-940-->
</div>

</div></div></div>
<?php
	$page->public_footer($foptions=array('track'=>TRUE));
?>

