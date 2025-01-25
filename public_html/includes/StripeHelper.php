<?php
require_once('Globalvars.php');
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir.'/data/users_class.php');
require_once($siteDir.'/data/orders_class.php');
require_once($siteDir.'/data/order_items_class.php');

$composer_dir = $settings->get_setting('composerAutoLoad');	
require_once $composer_dir.'autoload.php';

class StripeHelperException extends Exception {}

class StripeHelper {
	private $api_key;
	private $api_secret_key;
	private $stripe;
	private $stripe_test;
	public $test_mode;
	private $stripe_checkout_session;

	public function __construct() {
		
		$settings = Globalvars::get_instance();
		$session = SessionControl::get_instance();

		if($_SESSION['test_mode'] || $settings->get_setting('debug')){
			$this->api_key = $settings->get_setting('stripe_api_key_test');
			$this->api_secret_key = $settings->get_setting('stripe_api_pkey_test');
			$this->test_mode = true;
		}
		else{
			$this->api_key = $settings->get_setting('stripe_api_key');
			$this->api_secret_key = $settings->get_setting('stripe_api_pkey');
			$this->test_mode = false;			
		}

		if(!$this->api_key || !$this->api_secret_key){
			return false;
			//throw new SystemDisplayablePermanentError("Stripe api keys are not present.");
			//exit();			
		}
		

		$this->stripe = new \Stripe\StripeClient([
			'api_key' => $this->api_key,
			'stripe_version' => '2022-11-15'
		]);	
		
	

	}

	public function is_initialized() {
		if($this->api_key && $this->api_secret_key){
			return true;			
		}
		else{
			return false;
		}
	}

	public function get_stripe_private_key() {
		return $this->api_secret_key;
	}

	public function get_stripe_customer_id($user) {
		if($this->test_mode){
			return $user->get('usr_stripe_customer_id_test');
		}
		else{
			return $user->get('usr_stripe_customer_id');
		}
	}
	
	public function output_stripe_regular_form($formwriter, $button_class=''){
				
				$output = '
				<script>
				$(document).ready(function() {
					$(\'#nojavascript\').hide();
				});
				</script>
				<div id="nojavascript" style="border: 3px solid red; padding: 10px; margin: 10px;">Our payment form requires javascript to be turned on.  Please set your browser to allow javascript, turn off ad blockers, or try another browser.</div>
				<script src="https://js.stripe.com/v3/"></script>
				<form action="/cart_charge" method="post" id="payment-form">
				  <div>
					<div id="card-element">
					  <!-- A Stripe Element will be inserted here. -->
					</div>

					<!-- Used to display form errors. -->
					<div id="card-errors" role="alert"></div>
				  </div>
				<br />'. $formwriter->new_form_button('Pay with Stripe', 'primary', 'full', $button_class).'</form>';


	
				$session = SessionControl::get_instance();
				$settings = Globalvars::get_instance();
				if($_SESSION['test_mode'] || $settings->get_setting('debug')){
					$api_key = $settings->get_setting('stripe_api_key_test');
					$api_secret_key = $settings->get_setting('stripe_api_pkey_test');
				}
				else{
					$api_key = $settings->get_setting('stripe_api_key');
					$api_secret_key = $settings->get_setting('stripe_api_pkey');		
				}
				
				if(!$api_key || !$api_secret_key){
					throw new SystemDisplayablePermanentError("Stripe api keys are not present.");
					exit();			
				}

				$output .= '<script language="javascript"> var stripe = Stripe(\''.$api_secret_key.'\');';
				
				$output .= "				
							var elements = stripe.elements();

							// Custom styling can be passed to options when creating an Element.
							// (Note that this demo uses a wider set of styles than the guide below.)
							var style = {
								
							  base: {
								color: '#32325d', 
								fontFamily: '\"Helvetica Neue\", Helvetica, sans-serif',
								fontSmoothing: 'antialiased',
								fontSize: '24px',
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
							}";		
				
				$output .= '</script>';	
				
				return $output;
	}
	
	public function output_stripe_checkout_form($cart_hash){
				$formwriter = LibraryFunctions::get_formwriter_object('form3', 'tailwind');
				$output = '
				<script src="https://js.stripe.com/v3/"></script>
				<script language="javascript">
				var stripe = Stripe(\''. $this->get_stripe_private_key().'\');

				function ToCheckout() {
					stripe.redirectToCheckout({
					  sessionId: \''. $this->stripe_checkout_session->id .'\'
					}).then(function (result) {
					  // If `redirectToCheckout` fails due to a browser or network
					  // error, display the localized error message to your customer
					  // using `result.error.message`.
					});
				}
				</script>';
					

				
				$output .= $formwriter->begin_form("mt-6", "post", '/profile/payment_finalize');

				$output .=  '<div id="errorMsg" style="display:none;"></div>';

				$output .=  $formwriter->hiddeninput('cc_type', '');
				$output .=  $formwriter->hiddeninput('cart_cs', $cart_hash);
				
				$output .=  $formwriter->start_buttons();
				$output .=  '<input type="button" value="Pay with Stripe" class="inline-flex justify-center mr-3 mt-3 py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 " onclick="ToCheckout();" style="width:200px;">';
				
				$output .=  $formwriter->end_buttons();

				$output .=  $formwriter->end_form();	

				return $output;
	}
	
	public function build_checkout_item_array($cart, $existing_billing_user){
		
		$settings = Globalvars::get_instance();
		$currency_code = $settings->get_setting('site_currency');
		$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')];
	
		$contains_subscription = 0;
		$stripe_item_list = array();
		
		foreach($cart->get_detailed_items() as $cart_item) {
			$final_price = $cart_item['price'] - $cart_item['discount'];

			if($cart_item['recurring']){
				

				$product_data = array(
					'name' => $cart_item['name'],
					'description' => $cart_item['name'].' ',
				);

				if($cart_item['recurring'] == 'year'){
					$recurring = array(
						'interval' => 'year',
						'interval_count' => 1,
					);
				}
				else if($cart_item['recurring'] == 'month'){
					$recurring = array(
						'interval' => 'month',
						'interval_count' => 1,
					);
				}
				else if($cart_item['recurring'] == 1){  //HOW WE USED TO DO IT
					$recurring = array(
						'interval' => 'month',
						'interval_count' =>  1,
					);
				}		
				else if($cart_item['recurring'] == 'week'){
					$recurring = array(
						'interval' => 'week',
						'interval_count' =>  1,
					);
				}	
				else if($cart_item['recurring'] == 'day'){
					$recurring = array(
						'interval' => 'day',
						'interval_count' =>  1,
					);
				}	
				else {
					throw new SystemDisplayablePermanentError("This product (".$product->get('pro_name').") is not a subscription.");
				}	
				
				if($cart_item['recurring'] && $cart_item['trial_period_days']){
					$recurring['trial_period_days'] = $cart_item['trial_period_days'];
				}
				else{
					$recurring['trial_period_days'] = null;
				}

				
				$price_data = array(
					'currency' => $currency_code,
					'product_data' => $product_data,
					'unit_amount' => $final_price * 100,
					'recurring' => $recurring,
				);
				
				$stripe_current_item = array(
					'price_data' => $price_data,
					'quantity' => $cart_item['quantity'],
					//'metadata' => 
				);
				
				$plan = $this->get_or_create_subscription_plan($final_price, $product->get('pro_recurring'), $product->get('pro_trial_period_days'));

				
				//TODO add description "metadata" => 
				if($cart_item['price'] > 0){
					array_push($stripe_item_list, $stripe_current_item);		
				}	

				$contains_subscription = 1;


			}
			else{
				//ASSEMBLE THE STRIPE PRODUCT ARRAY

				
				$product_data = array(
					'name' => $cart_item['name'],
					'description' => $cart_item['name'].' ',
				);
				
				$price_data = array(
					'currency' => $currency_code,
					'product_data' => $product_data,
					'unit_amount' => $final_price * 100,
				);
				
				$stripe_current_item = array(
					'price_data' => $price_data,
					'quantity' => $cart_item['quantity'],
					//'metadata' => 
				);

				
				//TODO add description "metadata" => 
				if($final_price > 0){
					array_push($stripe_item_list, $stripe_current_item);		
				}	
				
			}
		}
			
		$create_list = array(
			'billing_address_collection' => 'auto',
			'payment_method_types' => ['card'],
			'success_url' => $settings->get_setting('webDir'). '/cart_charge?session_id={CHECKOUT_SESSION_ID}',
			'cancel_url' => $settings->get_setting('webDir'). '/cart',
			
		);
		
		if($contains_subscription){
			$create_list['mode'] = 'subscription';
		}
		else{
			$create_list['mode'] = 'payment';
		}
		
		if($stripe_item_list){
			$create_list['line_items'] = $stripe_item_list;
		}
		
		if($stripe_subscription_item){
			$create_list['subscription_data'] = $stripe_subscription_item;
			$create_list['mode'] = 'subscription';
		}			

		

		if($existing_billing_user){
			$create_list['client_reference_id'] = $existing_billing_user->key;
		
			if($existing_billing_user->get('usr_stripe_customer_id_test') && $this->test_mode){
				$create_list['customer'] = $existing_billing_user->get('usr_stripe_customer_id_test');
			}
			else if($existing_billing_user->get('usr_stripe_customer_id') && !$this->test_mode){
				$create_list['customer'] = $existing_billing_user->get('usr_stripe_customer_id');
			}
			else if($existing_billing_user->get('usr_email')){
				$create_list['customer_email'] = $existing_billing_user->get('usr_email');		
			}				
		}
		else{
			$create_list['customer_email'] = $cart->billing_user['billing_email'];
		}		
		
		return $create_list;
	}

	public function get_customer($user, $return_type='object') {
		$stripe_customer = $this->stripe->customers->all(["email" => $user->get('usr_email')]);
		if($return_type == 'object'){
			if($this->test_mode){
				$user->set('usr_stripe_customer_id_test', $stripe_customer['data'][0]['id']);
			}
			else{
				$user->set('usr_stripe_customer_id', $stripe_customer['data'][0]['id']);
			}		 
			$user->save();
			return $stripe_customer['data'][0];
		}
		else if($return_type == 'id'){
			if($stripe_customer['data'][0]['id']){
				if($this->test_mode){
					$user->set('usr_stripe_customer_id_test', $stripe_customer['data'][0]['id']);
				}
				else{
					$user->set('usr_stripe_customer_id', $stripe_customer['data'][0]['id']);
				}		 
				$user->save();
				return $stripe_customer['data'][0]['id'];
			}
			else{
				return false;
			}
		}
		else{
			throw new SystemDisplayablePermanentError("Invalid return type.");
			exit();			
		}
	}
	
	public function get_customers($params){
		$stripe_customers = $this->stripe->customers->all($params);
		return $stripe_customers;
	}

	public function create_customer_at_stripe($user, $return_type='object') {
		$stripe_customer = $this->stripe->customers->create([
				'name' => $user->get('usr_first_name'). ' ' . $user->get('usr_last_name'),
				'email' => $user->get('usr_email'),
				'description' => $user->get('usr_first_name'). ' ' . $user->get('usr_last_name'). ' ('.$user->get('usr_email').')',
			]);
			
		if($this->test_mode){
			$user->set('usr_stripe_customer_id_test', $stripe_customer['id']);
		}
		else{
			$user->set('usr_stripe_customer_id', $stripe_customer['id']);
		}
		$user->save();

		if($return_type == 'object'){
			return $stripe_customer;
		}
		else if($return_type == 'id'){
			if($stripe_customer['id']){
				return $stripe_customer['id'];
			}
			else{
				return false;
			}
		}
		else{
			throw new SystemDisplayablePermanentError("Invalid return type.");
			exit();			
		}
	}
	
	public function get_or_create_stripe_customer($user){
		if($stripe_customer_id = $this->get_stripe_customer_id($user)){
			return $stripe_customer_id;
		}
		else if($stripe_customer_id = $this->get_customer($user, 'id')){
			return $stripe_customer_id;
		}
		else{
			$stripe_customer_id = $this->create_customer_at_stripe($user, 'id');
			return $stripe_customer_id;
		}
	}
	
	public function get_charge($stripe_charge_id){
		$charge = $this->stripe->charges->retrieve($stripe_charge_id);
		return $charge;
	}
	
	public function get_charges($params){
		$charges = $this->stripe->charges->all($params);
		return $charges;
	}

	public function get_invoices($params){
		$invoices = $this->stripe->invoices->all($params);
		return $invoices;
	}
	
	public function get_payment_intent($stripe_payment_intent_id){
		$intent = $this->stripe->paymentIntents->retrieve($stripe_payment_intent_id);
		return $intent;
	}	
	
	public function get_charge_from_payment_intent($stripe_payment_intent_id){
		$intent = $this->get_payment_intent($stripe_payment_intent_id);
		if(isset($intent->charges->data[0]->id)){
			$charge = $this->get_charge($intent->charges->data[0]->id);
		}
		else if(isset($intent->latest_charge)){
			$charge = $this->get_charge($intent->latest_charge);
		}
		else{
			return false;
		}
		return $charge;
	}
	
	public function get_charge_from_order($order){
		if($order->get('ord_test_mode')){
			if(!$this->test_mode){
				//DON'T UPDATE TEST MODE ORDERS IF NOT IN TEST MODE
				return false;		
			}
		}
		else{
			if($this->test_mode){
				//DON'T UPDATE LIVE MODE ORDERS IF IN TEST MODE
				return false;			
			}			
		}
		
		if ($order->get('ord_stripe_charge_id')){
			$charge_id = $order->get('ord_stripe_charge_id');
			
			try{
				
				return $this->get_charge($charge_id);

			}
			catch(\Stripe\Exception $e) {
				  return false;
			}	
		}	
		else if ($order->get('ord_stripe_payment_intent_id')) {
			try{

				$charge = $this->get_charge_from_payment_intent($order->get('ord_stripe_payment_intent_id'));
				return $charge;
			}
			catch(\Stripe\Exception $e) {
				  return false;
			}
		}
			
	}
	
	public function update_order_refund_amount_from_stripe($order){
		if($order->get('ord_test_mode')){
			if(!$this->test_mode){
				//DON'T UPDATE TEST MODE ORDERS IF NOT IN TEST MODE
				return false;		
			}
		}
		else{
			if($this->test_mode){
				//DON'T UPDATE LIVE MODE ORDERS IF IN TEST MODE
				return false;			
			}			
		}
		$charge = $this->get_charge_from_order($order);

		if($charge->amount_refunded){
			//print_r($charge->refunds);
			//$order->set('ord_refund_time', 'now()');
			$order->set('ord_refund_amount', $charge->amount_refunded/100);
			//$order->set('ord_refund_note', $_POST['ord_refund_note']);

			$order->save();
	
		}	
		return $charge;		
	}

	public function get_subscription($stripe_subscription_id){
		$stripe_subscription = $this->stripe->subscriptions->retrieve($stripe_subscription_id);
		return $stripe_subscription;
	}
	
	public function get_subscriptions($params){
		$subs = $this->stripe->subscriptions->all($params);
		return $subs;
	}
	
	public function refund_charge($stripe_charge_id, $refund_amount){
		try{
			$re = $this->stripe->refunds->create([
			'charge' => $stripe_charge_id,
			'amount' => $refund_amount*100
			]);
		}
		catch(\Stripe\Exception $e) {
			  echo 'Status is:' . $e->getHttpStatus() . '\n';
			  echo 'Type is:' . $e->getError()->type . '\n';
			  echo 'Code is:' . $e->getError()->code . '\n';
			  exit;
		}		
	}
	
	public function update_all_subscriptions_in_order($order){
		$order_items = $order->get_order_items();
		foreach($order_items as $order_item){
			$this->update_subscription_in_order_item($order_item);
		}	
		return true;
	}
	
	public function update_subscription_in_order_item($order_item){
		if($order_item->get('odi_is_subscription')){
			//CHECK SUBSCRIPTION STATUS
			try{		
				$stripe_subscription = $this->get_subscription($order_item->get('odi_stripe_subscription_id'));	
				
				if($stripe_subscription['canceled_at']){
					$canceled_at = gmdate("c", $stripe_subscription['canceled_at']);
					
					//IF SUBSCRIPTION ENDED, REMOVE 
					$order_item->set('odi_subscription_cancelled_time', $canceled_at);
				}

				//if($stripe_subscription['status'] == 'canceled' || $stripe_subscription['status'] == 'incomplete_expired'){
				$order_item->set('odi_subscription_status', $stripe_subscription['status']);
				$order_item->save();
				//} 
				return true;
			}
			catch(Exception $e){
				//FAIL SILENTLY
				return false;
			}
		}		
	}
	
	
	public function create_card_from_token($stripe_token, $stripe_customer_id, $set_as_default=true){
		try {
			//STORE PAYMENT METHOD 
			/*
			$source_result = $stripe->sources->create([ 
				 'type' => 'card', 
				 'token' => $_REQUEST['stripeToken'],  
			]);
			*/
			$source_result = $this->stripe->customers->createSource($stripe_customer_id, [ 
				 'source' => $stripe_token,  
			]);
	

		}
		/*
		catch(\Stripe\Error\Card $e) {
			// Since it's a decline, \Stripe\Exception\Card will be caught
			$error = "Sorry, we weren't able to charge your card. <strong>" . $e->getMessage()."</strong> Please use your back button to go back to the checkout form and try again or contact us at ".$settings->get_setting('defaultemail')." if you keep having trouble.";
			$order->set('ord_error', substr($error, 0, 250));
			$order->save();	
			PublicPageTW::OutputGenericPublicPage("Card Error", "Card Error", $error);
		} 
		catch(\Stripe\Error\CardException $e) {
			// Since it's a decline, \Stripe\Exception\Card will be caught
			$error = "Sorry, we weren't able to charge your card. <strong>" . $e->getMessage()."</strong> Please use your back button to go back to the checkout form and try again or contact us at ".$settings->get_setting('defaultemail')." if you keep having trouble.";
			$order->set('ord_error', substr($error, 0, 250));
			$order->save();	
			PublicPageTW::OutputGenericPublicPage("Card Error", "Card Error", $error);
		}
		catch (\Stripe\Exception\RateLimitException $e) {
		  // Too many requests made to the API too quickly
			$error = "Sorry, we weren't able to authorize your card due to too many requests. You have not been charged.";
		} 
		catch (\Stripe\Exception\InvalidRequestException $e) {
			$error = "Sorry, we weren't able to authorize your card due to an invalid request. That's our fault. You have not been charged.";	
		} 
		catch (\Stripe\Exception\AuthenticationException $e) {
		  // Authentication with Stripe's API failed
		  // (maybe you changed API keys recently)
		  $error = "Sorry, our connection to our credit card processor is not currently working. That's our fault. You have not been charged.";
		} 
		catch (\Stripe\Exception\ApiConnectionException $e) {
		  // Network communication with Stripe failed
		  $error = "Sorry, we were unable to reach the credit card processor. That's our fault. You have not been charged.";
		} 
		catch (\Stripe\Exception\ApiErrorException $e) {
			
			print_r($e);
			exit;
		  // Display a very generic error to the user, and maybe send
		  // yourself an email
		  $error = "Sorry, we weren't able to connect to the Stripe api.";
		} 
		*/
		catch (Exception $e) {	
			$error = "Sorry, we weren't able to store your card. " . $e->getMessage();
			error_log($error);
			PublicPageTW::OutputGenericPublicPage("Card Error", "Card Error", $error);	
			exit;
			/*		
			$stored_error = "Card not charged.   Error type: ". $e->getError()->type . "  Code: " . $e->getError()->code. "  Decline code: ". $e->getError()->decline_code . "  Message: ".$e->getMessage(). "  Debug info: ".$e->getError()->doc_url .", ". $e->getError()->param;

			$error = "Sorry, we weren't able to charge your card. <strong>" . $e->getMessage()."</strong> Please use your back button to go back to the checkout form and try again or contact us at ".$settings->get_setting('defaultemail')." if you keep having trouble.";
			$order->set('ord_error', substr($stored_error, 0, 250));
			$order->save();	
			PublicPageTW::OutputGenericPublicPage("Card Error", "Card Error", $error);
			
			$error = "Sorry, we weren't able to charge your card. " . $e->getMessage();
			exit;
			*/
		}
		
		//SET NEW CARD AS DEFAULT 
		if($set_as_default){
			try {
				$customer = $this->stripe->customers->retrieve($stripe_customer_id);
				$customer->default_source=$source_result['id'];
				$customer->save();  
			}
			catch (Exception $e) {		  
				error_log("Unable to set stripe default card.");
			}
		}			
		return $source_result;
		
	}
	
	public function get_payment_methods($stripe_user_id){
		$result = $this->stripe->customers->allPaymentMethods($stripe_user_id, ['limit' => 20]);
		return $result;
	}
	
	public function get_subscription_plan($plan_name){
		$result = $this->stripe->plans->retrieve($plan_name);
		return $result;
	}	
	
	
	public function create_subscription_plan($params){
		//CREATE NEW PLAN
		if(!isset($params['amount'])){
			throw new SystemDisplayablePermanentError('Missing parameters passed to create_subscription_plan.  Amount:'. $params['amount'] );
		}
		else if(!isset($params['plan_name'])){
			throw new SystemDisplayablePermanentError('Missing parameters passed to create_subscription_plan.  Plan name:'. $params['plan_name'] );
		} 
		else if(!isset($params['interval'])){
			throw new SystemDisplayablePermanentError('Missing parameters passed to create_subscription_plan.  Interval:'. $params['interval'] );
		} 		
		else if(!isset($params['currency_code'])){
			throw new SystemDisplayablePermanentError('Missing parameters passed to create_subscription_plan.  Currency code:'. $params['currency_code'] );
		}

		if($params['trial_period_days']){
			$plan_info = array(
			[
			  "amount" => $params['amount'] * 100,
			  "interval" => $params['interval'],
			  "product" => [
				"name" => $params['plan_name'],
			  ],
			  "currency" => $params['currency_code'],
			  "trial_period_days" => $params['trial_period_days'],
			]		
			);
		}
		else{		
			$plan_info = array(
			[
			  "amount" => $params['amount'] * 100,
			  "interval" => $params['interval'],
			  "product" => [
				"name" => $params['plan_name'],
			  ],
			  "currency" => $params['currency_code'],
			]		
			);
		}

		
		$plan = $this->stripe->plans->create($plan_info); 	
		return $plan;
	}
	
	public function create_subscription($params){
		$subscription = $this->stripe->subscriptions->create($params);	
		return $subscription;
	}
	
	public function create_product($params){
		$product = $this->stripe->products->create($params);	
		return $product;
	}

	public function create_price($params){
		$price = $this->stripe->prices->create($params);	
		return $price;
	}
	

	
	/*
	public function update_subscription($subscription_id, $params){
		$subscription = $this->stripe->subscriptions->update(
			$subscription_id,
			$params
		);
		return $subscription;
	}
	*/

	public function change_subscription($subscription_id, $item_id_to_update, $new_stripe_price){
		$subscription = $this->stripe->subscriptions->update(
			$subscription_id,
			[
				'items' => [
					[
						'id' => $item_id_to_update, // Specify the subscription item ID
						'price' => $new_stripe_price, // Specify the new stripe price id
					],
				],
			]
		);
		return $subscription;
	}
	
	
	//THIS FUNCTION IS NOW DEPRECATED
	/*
	public function update_subscription_plan($subscription_id, $item_id_to_update, $new_plan_id){
		$subscription = $this->stripe->subscriptions->update(
			$subscription_id,
			[
				'items' => [
					[
						'id' => $item_id_to_update, // Specify the subscription item ID
						'plan' => $new_plan_id, // Specify the new plan ID
					],
				],
			]
		);
		return $subscription;
	}
	*/
	

	public function create_charge($params){
		//CHARGE THE PURCHASE
		$charge = $this->stripe->charges->create($params); 
		return $charge;
	}


	
	public function get_or_create_price($product, $price){

		
		if($interval == 1){  //HOW WE USED TO DO IT
			$interval = 'month';
		}
		
		$settings = Globalvars::get_instance();
		$currency_code = $settings->get_setting('site_currency');
		$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')];
		
		//CHECK FOR EXISTING PLAN
		if($this->test_mode){
			$stripe_product_id = $product->get('pro_stripe_product_id_test');
		}
		else{
			$stripe_product_id = $product->get('pro_stripe_product_id');
		}
		
		if($product->get('pro_recurring')){
			$stripe_type = 'recurring';
		}
		else{
			$stripe_type = 'one_time';
		}
	
		//GET ALL PRICES FOR PRODUCT
		$stripe_prices = $this->stripe->prices->all([
			'product' => $stripe_product_id,
			'active' => 'true',
			'type' => $stripe_type,
			]);
	
		$found_price = NULL;
		foreach ($stripe_prices->data as $stripe_price) {
			if($stripe_price->unit_amount / 100 == $price){
				return $stripe_price;
			}
		}
		
		//IF WE GOT HERE WE NEED TO CREATE ONE
		if($product->get('pro_trial_period_days')){
			$nickname = $amount. '-trial'.$product->get('pro_trial_period_days');
		}
		else{
			$nickname = $amount;
		}
		

	
		try{
			$stripe_params=array();
			$stripe_params['nickname'] = $nickname;
			$stripe_params['unit_amount'] = $price * 100;
			$stripe_params['currency'] = $currency_code;
			$stripe_params['product'] = $stripe_product_id;
			if($product->get('pro_recurring')){
				
				if($product->get('pro_trial_period_days')){
					$stripe_params['recurring'] = array(
						'interval' => $product->get('pro_recurring'),
						'trial_period_days' => $product->get('pro_trial_period_days')
						);
				}
				else{
					$stripe_params['recurring'] = array('interval' => $product->get('pro_recurring'));
				}
			}

			$stripe_price = $this->create_price($stripe_params);
		}
		catch (Exception $e) {
			
			throw new SystemDisplayablePermanentError("Stripe price creation failed.  Message: ".$e->getMessage()); 
		}
		return $stripe_price;
	}

	
	
	//THIS IS NOW DEPRECATED, USE PRICES INSTEAD
	public function get_or_create_subscription_plan($amount, $interval, $trial_period_days){
		
		if($interval == 1){  //HOW WE USED TO DO IT
			$interval = 'month';
		}
		
		$settings = Globalvars::get_instance();
		$currency_code = $settings->get_setting('site_currency');
		$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')];
		
		//CHECK FOR EXISTING PLAN
		if($trial_period_days){
			$plan_name = 'subscription-' . $amount. '-trial'.$trial_period_days;
		}
		else{
			$plan_name = 'subscription-' . $amount;
		}
		
		try{
			$plan = $this->get_subscription_plan($plan_name);
		}
		catch (Exception $e) {
			$plan_params=array();
			$plan_params['plan_name'] = $plan_name;
			$plan_params['amount'] = $amount;
			$plan_params['interval'] = $interval;
			$plan_params['currency_symbol'] = $currency_symbol;
			$plan_params['currency_code'] = $currency_code;
			if($trial_period_days){
				$plan_params['trial_period_days'] = $trial_period_days;
			}
			//CREATE NEW PLAN
			$plan = $this->create_subscription_plan($plan_params); 	

		}
		return $plan;
	}
	
	/*
	public function update_stripe_regular_subscription_from_order_item($subscription_id, $plan, $order_item){
		$order = $order_item->get_order();
		
		$plan_items = array(
			'plan' => $plan['id'],
		);
				
		
		$plan_items['metadata'] = array(
			"ord_order_id" => $order->key, 
			"odi_order_item_id" => $order_item->key, 
		);
		$plan_items_wrap = array($plan_items);
		
		
		//UPDATE DOES NOT WORK WITH TRIAL PERIODS
		try{
			$subscription_params = array([
			  'items' => $plan_items_wrap,
			  'metadata' => [
				 "ord_order_id" => $order->key, 
				 "odi_order_item_id" => $order_item->key,
				],
			]);
			
			$subscription_result = $this->update_subscription($subscription_id, $subscription_params);

		}
		catch (Exception $e) {		  
			$stored_error = "Subscription change failed.   Error type: ". $e->getError()->type . "  Code: " . $e->getError()->code. "  Decline code: ". $e->getError()->decline_code . "  Message: ".$e->getMessage(). "  Debug info: ".$e->getError()->doc_url .", ". $e->getError()->param;
			print_r($stored_error);
			exit;;  //SKIP THE REST OF THE ITEM
		}				
		

		
		//SAVE THE SUBSCRIPTION INFO FROM REGULAR CHECKOUT

		//$order_item->set('odi_stripe_foreign_invoice_id', $subscription_result['latest_invoice']);
		//$order_item->save();		
		
		return $subscription_result;
		
	}
	*/



	public function process_stripe_regular_subscription_from_order_item($price, $order_item, $billing_user, $stripe_customer_id){
		$billing_name = $billing_user->display_name();
		$order = $order_item->get_order();
		
		$price_items = array(
			'price' => $price['id'],
		);
				
		//START THE SUBSCRIPTION
		
		$price_items_wrap = array($price_items);

		try{

			$params = array([
			  'customer' => $stripe_customer_id,
			  'items' => $price_items_wrap,
			  'metadata' => [
				 "ord_order_id" => $order->key, 
				 "odi_order_item_id" => $order_item->key,
				 "customer_name" => $billing_name,
				 "customer_email" => $billing_user->get('usr_email')],
			]);
			
			
			$subscription_result = $this->create_subscription($params);
			


		}
		catch (Exception $e) {		  
			$stored_error = "Subscription failed.   Error type: ". $e->getError()->type . "  Code: " . $e->getError()->code. "  Decline code: ". $e->getError()->decline_code . "  Message: ".$e->getMessage(). "  Debug info: ".$e->getError()->doc_url .", ". $e->getError()->param;
			
			$error = "Sorry, we weren't able to create your subscription. <strong>" . $e->getMessage()."</strong> ";
			
			$order->set('ord_error', substr($stored_error, 0, 250));
			$order->save();	
			
			$order_item->set('odi_status', OrderItem::STATUS_ERROR);
			$order_item->set('odi_status_change_time', 'now()');
			$order_item->save();
			throw new SystemDisplayablePermanentError($error);
			exit;;  //SKIP THE REST OF THE ITEM
		}				
		

		//IF THE SUBSCRIPTION FAILED MARK IT AS ERROR
		if(!$subscription_result['id']){
			$order_item->set('odi_status', OrderItem::STATUS_ERROR);
			$order_item->set('odi_status_change_time', 'now()');
			$order_item->save();
			exit;  //SKIP THE REST OF THE ITEM
		}
		
		//SAVE THE SUBSCRIPTION INFO FROM REGULAR CHECKOUT
		$order_item->set('odi_stripe_subscription_id', $subscription_result['id']);
		$order_item->set('odi_stripe_foreign_invoice_id', $subscription_result['latest_invoice']);
		$order_item->set('odi_is_subscription', true);
		$order_item->set('odi_status', OrderItem::STATUS_PAID);
		$order_item->set('odi_status_change_time', 'now()');
		$order_item->save();		
		
		return $subscription_result;
		
	}	
	/*
	public function process_stripe_regular_subscription_from_order_item($plan, $order_item, $billing_user, $stripe_customer_id){
		$billing_name = $billing_user->display_name();
		$order = $order_item->get_order();
		
		$plan_items = array(
			'plan' => $plan['id'],
		);
				
		//START THE SUBSCRIPTION
		$plan_items['metadata'] = array(
			"ord_order_id" => $order->key, 
			"odi_order_item_id" => $order_item->key, 
			 "customer_name" => $billing_name,
			 "customer_email" => $billing_user->get('usr_email')
		);
		$plan_items_wrap = array($plan_items);
		
		try{
			if($plan['trial_period_days']){
				$subscription_params = array([
				  'customer' => $stripe_customer_id,
				  'items' => $plan_items_wrap,
				  'trial_from_plan' => true,
				  'metadata' => [
					 "ord_order_id" => $order->key, 
					 "odi_order_item_id" => $order_item->key,
					 "customer_name" => $billing_name,
					 "customer_email" => $billing_user->get('usr_email')],
				]);			
			}
			else{
				$subscription_params = array([
				  'customer' => $stripe_customer_id,
				  'items' => $plan_items_wrap,
				  'metadata' => [
					 "ord_order_id" => $order->key, 
					 "odi_order_item_id" => $order_item->key,
					 "customer_name" => $billing_name,
					 "customer_email" => $billing_user->get('usr_email')],
				]);
			}
			
			$subscription_result = $this->create_subscription($subscription_params);
			


		}
		catch (Exception $e) {		  
			$stored_error = "Subscription failed.   Error type: ". $e->getError()->type . "  Code: " . $e->getError()->code. "  Decline code: ". $e->getError()->decline_code . "  Message: ".$e->getMessage(). "  Debug info: ".$e->getError()->doc_url .", ". $e->getError()->param;

			$error = "Sorry, we weren't able to create your subscription. <strong>" . $e->getMessage()."</strong> Please use your back button to go back to the checkout form and try again or contact us at ".$settings->get_setting('defaultemail')." if you keep having trouble.";
			$order->set('ord_error', substr($stored_error, 0, 250));
			$order->save();	
			
			$order_item->set('odi_status', OrderItem::STATUS_ERROR);
			$order_item->set('odi_status_change_time', 'now()');
			$order_item->save();
			exit;;  //SKIP THE REST OF THE ITEM
		}				
		

		//IF THE SUBSCRIPTION FAILED MARK IT AS ERROR
		if(!$subscription_result['id']){
			$order_item->set('odi_status', OrderItem::STATUS_ERROR);
			$order_item->set('odi_status_change_time', 'now()');
			$order_item->save();
			exit;  //SKIP THE REST OF THE ITEM
		}
		
		//SAVE THE SUBSCRIPTION INFO FROM REGULAR CHECKOUT
		$order_item->set('odi_stripe_subscription_id', $subscription_result['id']);
		$order_item->set('odi_stripe_foreign_invoice_id', $subscription_result['latest_invoice']);
		$order_item->set('odi_is_subscription', true);
		$order_item->set('odi_status', OrderItem::STATUS_PAID);
		$order_item->set('odi_status_change_time', 'now()');
		$order_item->save();		
		
		return $subscription_result;
		
	}
	*/
	
	public function process_charge($source, $amount, $stripe_customer_id, $item_list, $billing_user, $order=NULL){
		
		if($amount <= 0){
			throw new SystemDisplayablePermanentError("The amount value ".$amount."submitted for process_charge is not greater than zero.");
			exit();	
		}
		$settings = Globalvars::get_instance();
		$currency_code = $settings->get_setting('site_currency');
		$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')];
		$billing_name = $billing_user->display_name();
		
		$amount = $amount*100;
			
		//CHARGE THE PURCHASE
		
		$metadata = array();
		if($order){
			$metadata['ord_order_id'] = $order->get('ord_order_id'); 
		}
		$metadata['customer_name'] = $billing_name;
		$metadata['customer_email'] = $billing_user->get('usr_email');
			 
		$charge_params = array(
		  'source' => $source['id'],
		  'amount' => $amount,
		  'currency' => $currency_code,
		  'customer' => $stripe_customer_id,
		  'description' => implode(",", $item_list), 
		  //'billing_details' => ['email' => $billing_user->get('usr_email'), 'name' => $billing_name, ],
		  'metadata' => $metadata 
		);

		$charge = $this->create_charge($charge_params);

		return $charge;
			
	}
	
	
	/* BEGIN STRIPE CHECKOUT FUNCTIONS */
	

	public function create_stripe_checkout_session($params){
		$stripe_session = $this->stripe->checkout->sessions->create($params);
		$this->stripe_checkout_session = $stripe_session;
		return $stripe_session;
	}
	
	public function webhook_construct_event($payload, $sig_header, $endpoint_secret){
	
		  $event = $this->stripe->Webhook->construct_event(
			$payload, $sig_header, $endpoint_secret
		  );
		  return $event;
	}

}

?>
