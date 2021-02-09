<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/FormWriterPublic.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/ShoppingCart.php');

	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/stripe-php/init.php');
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

	$page = new PublicPage(TRUE);
	$page->public_header(array(
	'title' => 'Checkout'
	));
	
	$rownum=0;
	
	$newbilling = 0;
	if($_GET['newbilling'] == 1){
		$cart->billing_user = NULL;
		$newbilling = 1;
	}
	echo PublicPage::BeginPage('Checkout');
?>



<div uk-grid>
    <div class="uk-width-1-2@m"><div style="padding: 20px">
	<h3>Your Cart</h3>


			<?php
		
			if (count($cart->get_items()) === 0) {
				// Cart is empty, can't checkout!
				?>
				<p>Your shopping cart is currently empty.</p>
				<?php
			} else {

			
				$headers = array('Item', 'Description', 'Price', '');
				$page->tableheader($headers);

				foreach($cart->items as $key => $cart_item) {
					$rowvalues = array();
					list($quantity, $product, $data) = $cart_item;
					$product_version = $product->get_product_version($data);
					$product = new Product($product->get('pro_product_id'), TRUE); 	

					//HANDLE PRICES
					if($product->get('pro_user_choose_price') && $data['user_price']){
						$price = $data['user_price'];
					}
					else{
						if ($product_version !== NULL) {
							$price = $product_version->prv_version_price;
						} else {
							$price = $product->get('pro_price');
						}
					}	
					
					array_push($rowvalues, $key+1);
					array_push($rowvalues, $product->get('pro_name').' '. $product_version->prv_version_name . ' ('. $data['full_name_first']. ' ' .$data['full_name_last']. ') ');
					array_push($rowvalues, '$' . money_format('%i', $price));
					array_push($rowvalues, '<span class="icon-remove"><a href="/cart?r=' . $key	. '">Remove</a></span>');
					$page->disprow($rowvalues);
			
					$itemcount++;
				}	
				$page->endtable();		
				?>
				<p class="cart-total">Total: $<?php echo  money_format('%i', $cart->get_total()); ?>
								
				<span style="float:right;">(<a href="/cart_clear">clear cart</a>)</span>
				
				</p> 				

	</div>
	</div>
	<div class="uk-width-1-2@m"><div style="padding: 20px">
	<h3>Billing Info</h3>

				<?php
					
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
					else if($cart->count_items() == 1 && !$cart->billing_user && !$newbilling){
						//IF ONLY ONE ITEM IN CART, LOAD THAT AS BILLING USER
						foreach($cart->items as $key => $cart_item) {}  //SHORTCUT TO GET ONLY ONE
						list($quantity, $product, $data) = $cart_item;
						
						$billing_user['billing_first_name'] = $data['full_name_first'];
						$billing_user['billing_last_name'] = $data['full_name_last'];
						$billing_user['billing_email'] = strtolower(trim($data['email']));
						$cart->billing_user = $billing_user;
					}						

										
					if(!$cart->billing_user){	
						
						//DISPLAY THE FORM
						?>
						<script>
							//<![CDATA[

							$(document).ready(function() {
								$("#usr_first_name").focus();
								$('#new_billing').hide();
								
							$('#existing_billing_email').change(function () {
								if ($('#existing_billing_email option:selected').text() == 'A different person') {
									$('#new_billing').show();
								}
								else $('#new_billing').hide(); // hide div if value is not "custom"
							});

										$("#form1").validate({
												//debug: true,
												 errorElement: "p",
												rules: {
													billing_email: {
																	required:  function(element) {
																		return $('#existing_billing_email option:selected').text() == 'A different person';
																	  },
																	email: true

													},
													billing_first_name: {
																	required:  function(element) {
																		return $('#existing_billing_email option:selected').text() == 'A different person';
																	  }

													},													
													billing_last_name: {
																	required:  function(element) {
																		return $('#existing_billing_email option:selected').text() == 'A different person';
																	  }

													},
												},
												messages: {
													billing_email: {
														required: "Please enter your email address",
													   email: "Please enter a valid email"
													 },					 

												},
												errorClass: "errorField",
												highlight: function(element, errorClass) {
													$('#'+element.name+'_container').addClass("error");

												  },
												  unhighlight: function(element, errorClass) {
													  $('#'+element.name+'_container').removeClass("error");

												  },
												errorPlacement: function(error, element) {
													error.prependTo(element.parents(".errorplacement").eq(0));
												}
										});

								});
							//]]>
								</script>
						<?php		
						
						echo '<h3>Who is submitting the order/paying?</h3>';
						$formwriter = new FormWriterPublic("form1", TRUE);
						echo $formwriter->begin_form("uniForm", "post", "/cart");
						echo '<fieldset class="inlineLabels">';
						
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
						echo $formwriter->start_buttons();
						echo $formwriter->new_form_button('Next Step', '');
						echo $formwriter->end_buttons();
						echo '</fieldset>';
						echo $formwriter->end_form();
				}
				else{
					echo '<p><strong>Bill to: '.$cart->billing_user['billing_first_name'] . ' ' . $cart->billing_user['billing_last_name'] . ' ('. $cart->billing_user['billing_email'].') <a href="/cart?newbilling=1">change billing user</a></strong></p>';
					$billing_user = User::GetByEmail(trim($cart->billing_user['billing_email']));
						
				}
		
				
				
				$settings = Globalvars::get_instance();
				$create_list = array(
					'billing_address_collection' => 'auto',
					'payment_method_types' => ['card'],
					'success_url' => $settings->get_setting('webDir'). '/cart_confirm?session_id={CHECKOUT_SESSION_ID}',
					'cancel_url' => $settings->get_setting('webDir'). '/cart',
				);
				
				if($stripe_item_list){
					$create_list['line_items'] = $stripe_item_list;
				}
				
				if($stripe_subscription_item){
					$create_list['subscription_data'] = $stripe_subscription_item;
				}
				
				if(!$_SESSION['test_mode']){
					if(!$billing_user){				
						$create_list['customer_email'] = $billing_user['billing_email'];
					}
				}
			
				if($cart->get_total() > 0){			
				
				
					?>
					<script>
						$(document).ready(function() {
							$('#nojavascript').hide();
						});
					</script>					
					
					
					
					<div id="nojavascript" style="border: 3px solid red; padding: 10px; margin: 10px;">Our payment form requires javascript to be turned on.  Please set your browser to allow javascript, turn off ad blockers, or try another browser.</div>
					<script src="https://js.stripe.com/v3/"></script>
					<form action="/cart_charge" method="post" id="payment-form">
					  <div class="form-row">
						<label for="card-element">
						  Credit or debit card
						</label>
						<div id="card-element">
						  <!-- A Stripe Element will be inserted here. -->
						</div>

						<!-- Used to display form errors. -->
						<div id="card-errors" role="alert"></div>
					  </div>
					<br />
					  <button>Submit Payment</button>
					</form>					
					
					

					<script language="javascript">
					var stripe = Stripe('<?php echo $api_secret_key; ?>');
					var elements = stripe.elements();

					// Custom styling can be passed to options when creating an Element.
					// (Note that this demo uses a wider set of styles than the guide below.)
					var style = {
					  base: {
						color: '#32325d',
						fontFamily: '"Helvetica Neue", Helvetica, sans-serif',
						fontSmoothing: 'antialiased',
						fontSize: '16px',
						'::placeholder': {
						  color: '#aab7c4'
						}
					  },
					  invalid: {
						color: '#fa755a',
						iconColor: '#fa755a'
					  }
					};

					// Create an instance of the card Element.
					var card = elements.create('card', {style: style});


				    // Add an instance of the card Element into the `card-element` <div>.
					card.mount('#card-element');



					// Handle real-time validation errors from the card Element.
					card.on('change', function(event) {
					  var displayError = document.getElementById('card-errors');
					  if (event.error) {
						displayError.textContent = event.error.message;
					  } else {
						displayError.textContent = '';
					  }
					});

					// Handle form submission.
					var form = document.getElementById('payment-form');
					form.addEventListener('submit', function(event) {
					  event.preventDefault();

					  stripe.createToken(card).then(function(result) {
						if (result.error) {
						  // Inform the user if there was an error.
						  var errorElement = document.getElementById('card-errors');
						  errorElement.textContent = result.error.message;
						} else {
						  // Send the token to your server.
						  stripeTokenHandler(result.token);
						}
					  });
					});

					// Submit the form with the token ID.
					function stripeTokenHandler(token) {
					  // Insert the token ID into the form so it gets submitted to the server
					  var form = document.getElementById('payment-form');
					  var hiddenInput = document.createElement('input');
					  hiddenInput.setAttribute('type', 'hidden');
					  hiddenInput.setAttribute('name', 'stripeToken');
					  hiddenInput.setAttribute('value', token.id);
					  form.appendChild(hiddenInput);

					  // Submit the form
					  form.submit();
					}


					</script>
					
					<style>
					/**
					 * The CSS shown here will not be introduced in the Quickstart guide, but shows
					 * how you can use CSS to style your Element's container.
					 */
					.StripeElement {
					  box-sizing: border-box;

					  height: 40px;

					  padding: 10px 12px;

					  border: 1px solid transparent;
					  border-radius: 4px;
					  background-color: white;

					  box-shadow: 0 1px 3px 0 #a2a6aa;
					  -webkit-transition: box-shadow 150ms ease;
					  transition: box-shadow 150ms ease;
					}

					.StripeElement--focus {
					  box-shadow: 0 1px 3px 0 #cfd7df;
					}

					.StripeElement--invalid {
					  border-color: #fa755a;
					}

					.StripeElement--webkit-autofill {
					  background-color: #fefde5 !important;
					}					
					</style>
					
					
					

					<?php
				}
				
				if($cart->billing_user){					
				
					if($cart->get_total() == 0){
						
						$formwriter = new FormWriterPublic("form1", TRUE);
						echo $formwriter->begin_form("uniForm", "post", '/cart_charge');

						echo '<fieldset class="inlineLabels">';
						echo $formwriter->hiddeninput('novalue', '');
						echo $formwriter->start_buttons();
						echo $formwriter->new_form_button('Submit');
						echo $formwriter->end_buttons();
						echo $formwriter->end_form();						
					}
				}		
		}


			?>

</div>

	</div>
</div>	
<?php
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>

