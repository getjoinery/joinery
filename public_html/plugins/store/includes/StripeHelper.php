<?php
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
$settings = Globalvars::get_instance();
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));

require_once(PathHelper::getComposerAutoloadPath());

class StripeHelperException extends Exception {}

class StripeHelper {
	private $api_key;
	private $api_secret_key;
	private $stripe;
	private $stripe_test;
	public $test_mode;
	private $stripe_checkout_session;

	/**
	 * Centralized test mode detection
	 * @return bool True if test mode should be used
	 */
	public static function isTestMode() {
		return (isset($_SESSION['test_mode']) && $_SESSION['test_mode']) || 
		       Globalvars::get_instance()->get_setting('debug');
	}

	/**
	 * Get the appropriate Stripe setting key name based on test mode
	 * @param string $baseKey The base setting key (e.g., 'stripe_api_key')
	 * @return string The setting key with _test suffix if in test mode
	 */
	public static function getStripeSettingKey($baseKey) {
		return self::isTestMode() ? $baseKey . '_test' : $baseKey;
	}

	public function __construct() {
		
		$settings = Globalvars::get_instance();
		$session = SessionControl::get_instance();

		$this->test_mode = self::isTestMode();
		$this->api_key = $settings->get_setting(self::getStripeSettingKey('stripe_api_key'));
		$this->api_secret_key = $settings->get_setting(self::getStripeSettingKey('stripe_api_pkey'));

		if(!$this->api_key || !$this->api_secret_key){
			return false;
			//throw new SystemDisplayablePermanentError("Stripe api keys are not present.");
			//exit();			
		}
		

		$this->stripe = new \Stripe\StripeClient([
			'api_key' => $this->get_stripe_private_key(),
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

	/**
	 * The Stripe API client, or a refusal saying why there isn't one.
	 *
	 * The constructor returns early when no API keys are configured, which
	 * leaves this object usable for everything that does not talk to Stripe
	 * (is_initialized(), isTestMode(), key lookups) but with no client. Every
	 * call that needs one goes through here so the failure is an exception
	 * naming the cause, not "call to a member function on null" from whichever
	 * line happened to touch it first.
	 *
	 * That distinction matters to callers: the surrounding code is full of
	 * `catch (Exception $e)` blocks written to mean "Stripe is not set up here,
	 * carry on" — and a null-method fatal is an Error, which those never catch.
	 *
	 * @return \Stripe\StripeClient
	 * @throws StripeHelperException When Stripe is not configured.
	 */
	private function client() {
		if (!$this->stripe) {
			throw new StripeHelperException(
				'Stripe is not configured on this deployment — no API keys are set'
				. ($this->test_mode ? ' for test mode.' : '.'));
		}
		return $this->stripe;
	}

	/**
	 * Get the validated Stripe client for direct API calls
	 * @return \Stripe\StripeClient Validated Stripe client using secret key
	 * @throws StripeHelperException if not initialized
	 */
	public function get_stripe_client() {
		if (!$this->stripe) {
			throw new StripeHelperException("StripeHelper not initialized - check API keys configuration");
		}
		return $this->stripe;
	}

	/**
	 * PRIVATE: Get validated publishable key for client-side use
	 * @return string Validated publishable key (pk_)
	 * @throws StripeHelperException if key is invalid or wrong type
	 */
	private function get_stripe_public_key() {
		if (empty($this->api_key)) {
			throw new StripeHelperException("Stripe publishable key is not configured");
		}
		
		$prefix = $this->test_mode ? 'test' : 'live';
		
		// Validate it's a publishable key
		if (strpos($this->api_key, "pk_{$prefix}_") !== 0) {
			throw new StripeHelperException(
				"Invalid Stripe publishable key. Expected key starting with pk_{$prefix}_ but got: " . 
				substr($this->api_key, 0, 10) . "..."
			);
		}
		
		// Extra safety: ensure it's not a secret key
		if (strpos($this->api_key, 'sk_') === 0) {
			throw new StripeHelperException(
				"CRITICAL: Secret key detected in publishable key field! Keys are swapped. " .
				"The stripe_api_key field contains an sk_ key but should contain a pk_ key."
			);
		}
		
		return $this->api_key;
	}

	/**
	 * PRIVATE: Get validated secret key for server-side use
	 * @return string Validated secret key (sk_)
	 * @throws StripeHelperException if key is invalid or wrong type
	 */
	private function get_stripe_private_key() {
		if (empty($this->api_secret_key)) {
			throw new StripeHelperException("Stripe secret key is not configured");
		}
		
		$prefix = $this->test_mode ? 'test' : 'live';
		
		// Validate it's a secret key
		if (strpos($this->api_secret_key, "sk_{$prefix}_") !== 0) {
			throw new StripeHelperException(
				"Invalid Stripe secret key. Expected key starting with sk_{$prefix}_ but got: " . 
				substr($this->api_secret_key, 0, 10) . "..."
			);
		}
		
		// Extra safety: ensure it's not a publishable key
		if (strpos($this->api_secret_key, 'pk_') === 0) {
			throw new StripeHelperException(
				"CRITICAL: Publishable key detected in secret key field! Keys are swapped. " .
				"The stripe_api_pkey field contains a pk_ key but should contain an sk_ key."
			);
		}
		
		return $this->api_secret_key;
	}

	/**
	 * PRIVATE: Get validated webhook endpoint secret
	 * @return string Validated webhook endpoint secret (whsec_)
	 * @throws StripeHelperException if secret is invalid or not configured
	 */
	private function get_webhook_endpoint_secret() {
		$settings = Globalvars::get_instance();
		$endpoint_secret = $settings->get_setting('stripe_endpoint_secret');
		
		if (empty($endpoint_secret)) {
			throw new StripeHelperException("Stripe webhook endpoint secret is not configured");
		}
		
		// Validate endpoint secret format (should start with whsec_)
		if (strpos($endpoint_secret, 'whsec_') !== 0) {
			throw new StripeHelperException(
				"Invalid Stripe webhook endpoint secret format. Expected key starting with whsec_ but got: " .
				substr($endpoint_secret, 0, 10) . "..."
			);
		}
		
		return $endpoint_secret;
	}

	/**
	 * Validate a Stripe Checkout session ID and retrieve the paid session.
	 * Whether the session has already been completed into an order is the
	 * caller's question (a replayed return shows that order's receipt).
	 * @param string $session_id The session ID from $_GET or other source
	 * @return \Stripe\Checkout\Session The session, as Stripe reports it, paid
	 * @throws StripeHelperException if validation fails or it is not paid
	 */
	public function validate_session_id($session_id) {
		// Basic validation
		if (empty($session_id)) {
			throw new StripeHelperException("Session ID is required");
		}
		
		$session_id = trim($session_id);
		
		if (strlen($session_id) < 10 || strlen($session_id) > 200) {
			error_log("Invalid Stripe session ID length: " . strlen($session_id));
			throw new StripeHelperException("Invalid session ID length");
		}
		
		// Format validation: Stripe session IDs start with cs_test_ or cs_live_
		if (!preg_match('/^cs_(test|live)_[a-zA-Z0-9]+$/', $session_id)) {
			error_log("Invalid Stripe session ID format: " . substr($session_id, 0, 20) . "...");
			throw new StripeHelperException("Invalid session ID format");
		}
		
		// Environment validation: ensure test/live matches current mode
		$is_test_session = strpos($session_id, 'cs_test_') === 0;
		
		if ($this->test_mode && !$is_test_session) {
			error_log("Live session ID in test mode: " . substr($session_id, 0, 20) . "...");
			throw new StripeHelperException("Live session ID received in test mode");
		}
		
		if (!$this->test_mode && $is_test_session) {
			error_log("Test session ID in live mode: " . substr($session_id, 0, 20) . "...");
			throw new StripeHelperException("Test session ID received in live mode");
		}
		
		try {
			$session = $this->client()->checkout->sessions->retrieve($session_id);
			
			// Verify payment status. A subscription that starts with a free
			// trial completes with nothing to pay ('no_payment_required').
			$completed = ($session->payment_status === 'paid')
				|| ($session->payment_status === 'no_payment_required' && ($session->status ?? '') === 'complete');
			if (!$completed) {
				error_log("Unpaid session attempted: " . substr($session_id, 0, 15) . "... status: " . $session->payment_status);
				throw new StripeHelperException("Session payment not completed");
			}
			
		} catch (\Stripe\Exception\InvalidRequestException $e) {
			error_log("Stripe session retrieval failed: " . substr($session_id, 0, 15) . "... " . $e->getMessage());
			throw new StripeHelperException("Invalid session ID: " . $e->getMessage());
		} catch (\Stripe\Exception\ApiErrorException $e) {
			error_log("Stripe API error during session validation: " . $e->getMessage());
			throw new StripeHelperException("Unable to validate session with Stripe");
		}
		
		// Log successful validation
		error_log("Stripe session validated: " . substr($session_id, 0, 15) . "... (test_mode: " . ($this->test_mode ? 'yes' : 'no') . ", status: paid)");
		
		return $session;
	}

	/**
	 * Handle Stripe exceptions for payment operations with user-friendly error messages
	 * @param callable $stripePaymentCall The Stripe payment API call to execute
	 * @param string $operation Description of the payment operation for logging
	 * @return mixed The result of the Stripe payment API call
	 * @throws SystemDisplayableError with user-friendly message
	 * @throws StripeHelperException for configuration issues
	 */
	public function executePaymentWithErrorHandling($stripePaymentCall, $operation = 'Stripe payment') {
		try {
			return $stripePaymentCall();
			
		} catch (\Stripe\Exception\CardException $e) {
			// Card was declined - most common payment error
			$decline_code = $e->getError()->decline_code ?? 'unknown';
			error_log("Stripe CardException in {$operation}: " . $e->getMessage() . " (decline_code: {$decline_code})");
			
			// Provide specific messages based on decline code
			switch ($decline_code) {
				case 'insufficient_funds':
					throw new SystemDisplayableError("Your card has insufficient funds. Please try a different payment method.");
				case 'expired_card':
					throw new SystemDisplayableError("Your card has expired. Please update your payment information.");
				case 'incorrect_cvc':
					throw new SystemDisplayableError("Your card's security code (CVC) is incorrect. Please check and try again.");
				case 'card_not_supported':
					throw new SystemDisplayableError("This card type is not supported. Please try a different card.");
				case 'processing_error':
					throw new SystemDisplayableError("There was an error processing your payment. Please try again.");
				default:
					throw new SystemDisplayableError("Your card was declined. Please try a different payment method or contact your bank.");
			}
			
		} catch (\Stripe\Exception\RateLimitException $e) {
			// Too many requests - retry after delay
			error_log("Stripe RateLimitException in {$operation}: " . $e->getMessage());
			throw new SystemDisplayableError("Too many payment requests. Please wait a moment and try again.");
			
		} catch (\Stripe\Exception\InvalidRequestException $e) {
			// Invalid parameters - usually a configuration issue
			$param = $e->getError()->param ?? 'unknown';
			error_log("Stripe InvalidRequestException in {$operation}: " . $e->getMessage() . " (param: {$param})");
			
			if (strpos($e->getMessage(), 'api_key') !== false) {
				throw new StripeHelperException("Invalid Stripe API key configuration");
			}
			
			throw new SystemDisplayableError("Payment configuration error. Please contact support if this persists.");
			
		} catch (\Stripe\Exception\AuthenticationException $e) {
			// Authentication failed - API key issues
			error_log("Stripe AuthenticationException in {$operation}: " . $e->getMessage());
			throw new StripeHelperException("Stripe authentication failed - check API key configuration");
			
		} catch (\Stripe\Exception\ApiConnectionException $e) {
			// Network connection failed
			error_log("Stripe ApiConnectionException in {$operation}: " . $e->getMessage());
			throw new SystemDisplayableError("Unable to connect to payment processor. Please check your internet connection and try again.");
			
		} catch (\Stripe\Exception\ApiErrorException $e) {
			// Generic Stripe API error
			$error_type = $e->getError()->type ?? 'api_error';
			error_log("Stripe ApiErrorException in {$operation}: " . $e->getMessage() . " (type: {$error_type})");
			switch ($error_type) {
				case 'idempotency_error':
					throw new SystemDisplayableError("Duplicate payment detected. Please refresh the page and try again.");
				case 'rate_limit_error':
					throw new SystemDisplayableError("Too many requests. Please wait a moment and try again.");
				default:
					throw new SystemDisplayableError("Payment processing error. Please try again or contact support.");
			}
			
		} catch (\Exception $e) {
			// Non-Stripe exception
			error_log("Non-Stripe exception in {$operation}: " . $e->getMessage());
			throw new SystemDisplayableError("An unexpected error occurred. Please try again.");
		}
	}


	
	/** The session key a card checkout waiting on the buyer's bank is kept under (cart_charge_logic). */
	const CARD_PENDING_KEY = 'card_payment_pending';
	/** How long a card checkout waits for the bank's confirmation to come back. */
	const CARD_PENDING_MAX_AGE = 3600;

	/**
	 * The card form (stripe_regular). Stripe.js turns the card into a
	 * PaymentMethod in the browser and the form posts only its id to
	 * /cart_charge; no card data reaches this server.
	 *
	 * When a checkout is waiting on the buyer's bank (3-D Secure,
	 * CARD_PENDING_KEY), the page runs that confirmation as it loads and posts
	 * back to /cart_charge to carry on, whatever the bank answered: the server
	 * reads the outcome from Stripe, not from the page.
	 */
	public function output_stripe_regular_form($formwriter, $button_class=''){
		$pending = $_SESSION[self::CARD_PENDING_KEY] ?? null;
		$client_secret = (is_array($pending) && !empty($pending['client_secret'])
			&& (int)($pending['time'] ?? 0) > time() - self::CARD_PENDING_MAX_AGE) ? (string)$pending['client_secret'] : '';

		$output = '
				<script>
					document.addEventListener(\'DOMContentLoaded\', function() {
						var el = document.getElementById(\'nojavascript\'); if (el) el.style.display = \'none\';
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
				<br />'. '<button type="submit" id="payment-submit" class="btn btn-primary '.$button_class.'">Pay with Stripe</button></form>
				<form action="/cart_charge" method="post" id="card-confirm-form">
				  <input type="hidden" name="card_payment_confirm" value="1">
				</form>';

		$output .= '<script> var stripe = Stripe(' . json_encode($this->get_stripe_public_key()) . ');
							var pendingSecret = ' . json_encode($client_secret) . ';';

		$output .= "
							var elements = stripe.elements();
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

							var card = elements.create('card', {style: style});
							card.mount('#card-element');

							var displayError = document.getElementById('card-errors');
							var submitButton = document.getElementById('payment-submit');
							card.on('change', function(event) {
							  displayError.textContent = event.error ? event.error.message : '';
							});

							// The card becomes a PaymentMethod here; only its id is posted.
							var form = document.getElementById('payment-form');
							form.addEventListener('submit', function(event) {
							  event.preventDefault();
							  submitButton.disabled = true;
							  stripe.createPaymentMethod({type: 'card', card: card}).then(function(result) {
								if (result.error) {
								  displayError.textContent = result.error.message;
								  submitButton.disabled = false;
								  return;
								}
								var hiddenInput = document.createElement('input');
								hiddenInput.setAttribute('type', 'hidden');
								hiddenInput.setAttribute('name', 'stripePaymentMethod');
								hiddenInput.setAttribute('value', result.paymentMethod.id);
								form.appendChild(hiddenInput);
								form.submit();
							  });
							});

							// A checkout waiting on the buyer's bank: Stripe shows the bank's
							// confirmation, then the checkout carries on at /cart_charge.
							if (pendingSecret) {
							  submitButton.disabled = true;
							  displayError.textContent = 'Confirming the payment with your bank...';
							  stripe.confirmCardPayment(pendingSecret).then(function() {
								document.getElementById('card-confirm-form').submit();
							  });
							}";

		$output .= '</script>';

		return $output;
	}

	/**
	 * The hosted Stripe Checkout button: a POST to /cart_charge, which makes
	 * the session for the cart as it is then, records it, and sends the buyer
	 * to Stripe's page (cart_charge_logic). Nothing is made at Stripe until it
	 * is pressed. $cart_hash is the cart the page showed: a cart changed since
	 * is shown again rather than paid for.
	 */
	public function output_stripe_checkout_form($cart_hash){
		$formwriter = new FormWriterV2HTML5('form_hosted_checkout', ['action' => '/cart_charge', 'method' => 'post']);
		ob_start();
		$formwriter->begin_form();
		$formwriter->hiddeninput('start_hosted_checkout', '', ['value' => 1]);
		$formwriter->hiddeninput('cart_cs', '', ['value' => $cart_hash]);
		$formwriter->submitbutton('pay_hosted', 'Pay with Stripe');
		$formwriter->end_form();
		return ob_get_clean();
	}

	public function build_checkout_item_array($cart, $existing_billing_user){

		$settings = Globalvars::get_instance();
		$currency_code = $settings->get_setting('site_currency');
		$currency_symbol = CurrencyHelper::symbol($settings->get_setting('site_currency'));

		$contains_subscription = 0;
		$max_trial_period_days = 0;  // Track the maximum trial period across all items
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
				
				// Note: trial_period_days cannot be set in price_data[recurring] for Checkout sessions
				// It must be set in subscription_data instead

				$price_data = array(
					'currency' => $currency_code,
					'product_data' => $product_data,
					'unit_amount' => $final_price * 100,
					'recurring' => $recurring,
				);

				// Store trial period for later use in subscription_data
				if($cart_item['recurring'] && $cart_item['trial_period_days']){
					$max_trial_period_days = max($max_trial_period_days, $cart_item['trial_period_days']);
				}
				
				$stripe_current_item = array(
					'price_data' => $price_data,
					'quantity' => $cart_item['quantity'],
				);

				// The session charges the inline price_data above (discount
				// included). Make sure the version has its own canonical price,
				// which a later tier change bills at.
				$this->get_or_create_price($cart_item['product_version']);

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
				);

				if($final_price > 0){
					array_push($stripe_item_list, $stripe_current_item);		
				}	
				
			}
		}
			
		$create_list = array(
			'billing_address_collection' => 'auto',
			'payment_method_types' => ['card'],
			'success_url' => LibraryFunctions::get_absolute_url('/cart_charge?session_id={CHECKOUT_SESSION_ID}'),
			'cancel_url' => LibraryFunctions::get_absolute_url('/checkout'),
			
		);
		
		if($contains_subscription){
			$create_list['mode'] = 'subscription';

			// Add trial period if any subscription items have it
			if($max_trial_period_days > 0){
				$create_list['subscription_data'] = array(
					'trial_period_days' => $max_trial_period_days
				);
			}
		}
		else{
			$create_list['mode'] = 'payment';
		}
		
		if($stripe_item_list){
			$create_list['line_items'] = $stripe_item_list;
		}
		
		// Note: $stripe_subscription_item is not currently defined anywhere
		// The subscription_data is now set above when contains_subscription is true			

		

		if($existing_billing_user){
			$create_list['client_reference_id'] = $existing_billing_user->key;
		
			$existing_customer_id = $this->read_customer_id($existing_billing_user);
			if($existing_customer_id){
				$create_list['customer'] = $existing_customer_id;
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
		// Stripe's SDK drops a null parameter, so a search with no email would be an
		// unfiltered list and its first customer someone else's: an account with
		// no email is never looked up, only created (get_or_create_stripe_customer).
		$stripe_customer = $user->get('usr_email')
			? $this->client()->customers->all(["email" => $user->get('usr_email')])
			: array('data' => array());

		// No matching customer in Stripe — caller (e.g. get_or_create_stripe_customer)
		// is responsible for creating one. Return-type-appropriate "not found" sentinel.
		if(empty($stripe_customer['data'])){
			if($return_type == 'id'){
				return false;
			}
			else if($return_type == 'object'){
				return null;
			}
			else{
				throw new SystemDisplayablePermanentError("Invalid return type.");
			}
		}

		$customer_id = $stripe_customer['data'][0]['id'];
		$this->write_customer_id($user, $customer_id);

		if($return_type == 'object'){
			return $stripe_customer['data'][0];
		}
		else if($return_type == 'id'){
			return $customer_id;
		}
		else{
			throw new SystemDisplayablePermanentError("Invalid return type.");
		}
	}
	
	public function get_customers($params){
		$stripe_customers = $this->client()->customers->all($params);
		return $stripe_customers;
	}

	/**
	 * Read the Stripe customer id for a user in the current mode. Identity now
	 * lives in the store-owned stc_stripe_customers table, not the users table.
	 */
	public function read_customer_id($user) {
		require_once(PathHelper::getIncludePath('plugins/store/data/stripe_customers_class.php'));
		$sc = StripeCustomer::GetForUser($user->key);
		return $this->test_mode ? $sc->get('stc_customer_id_test') : $sc->get('stc_customer_id');
	}

	/** Persist the Stripe customer id for a user in the current mode. */
	public function write_customer_id($user, $customer_id) {
		require_once(PathHelper::getIncludePath('plugins/store/data/stripe_customers_class.php'));
		$sc = StripeCustomer::GetForUser($user->key);
		$sc->set($this->test_mode ? 'stc_customer_id_test' : 'stc_customer_id', $customer_id);
		$sc->save();
	}

	public function get_stripe_customer_id($user) {
		return $this->read_customer_id($user);
	}

	public function create_customer_at_stripe($user, $return_type='object') {
		$name = trim($user->get('usr_first_name') . ' ' . $user->get('usr_last_name'));
		$params = array(
			'name' => $name,
			'description' => $name . ' (' . $user->login_label() . ')',
		);
		if ($user->get('usr_email')) {
			$params['email'] = $user->get('usr_email');
		}
		$stripe_customer = $this->client()->customers->create($params);
			
		$this->write_customer_id($user, $stripe_customer['id']);

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
		$charge = $this->client()->charges->retrieve($stripe_charge_id);
		return $charge;
	}
	
	public function get_charges($params){
		$charges = $this->client()->charges->all($params);
		return $charges;
	}

	public function get_invoices($params){
		$invoices = $this->client()->invoices->all($params);
		return $invoices;
	}
	
	public function get_payment_intent($stripe_payment_intent_id){
		$intent = $this->client()->paymentIntents->retrieve($stripe_payment_intent_id);
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
			catch(\Stripe\Exception\ExceptionInterface $e) {
				  return false;
			}	
		}	
		else if ($order->get('ord_stripe_payment_intent_id')) {
			try{

				$charge = $this->get_charge_from_payment_intent($order->get('ord_stripe_payment_intent_id'));
				return $charge;
			}
			catch(\Stripe\Exception\ExceptionInterface $e) {
				  return false;
			}
		}
		// Neither id: a PayPal, free or hand-made order has no Stripe charge.
		return false;
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
		// A refresh on the order page's view: Stripe out of reach (no keys,
		// a refused call) leaves the stored total as it is.
		try {
			$charge = $this->get_charge_from_order($order);
		} catch (StripeHelperException $e) {
			return false;
		}
		if (!$charge) {
			return false;
		}

		if($charge->amount_refunded){
			//print_r($charge->refunds);
			//$order->set('ord_refund_time', 'now()');
			$order->refresh_refund_amount($charge->amount_refunded/100);

			// The refund total Stripe just reported, folded in on the order page's view.
			SystemBase::server_initiated_write(function () use ($order) { $order->save(); });
	
		}	
		return $charge;		
	}

	/**
	 * The Stripe charge a refund of this order line comes out of.
	 *
	 * A subscription line is paid by its own invoices, so its refund comes out
	 * of the subscription's latest paid invoice. Any other line was paid by the
	 * order's own charge (ord_stripe_charge_id, or the charge of
	 * ord_stripe_payment_intent_id).
	 *
	 * @return array ['charge' => \Stripe\Charge, 'is_order_charge' => bool,
	 *         'what' => what is being refunded, for the page] or
	 *         ['charge' => null, 'reason' => why there is nothing to refund here]
	 * @throws StripeHelperException when Stripe cannot be asked.
	 */
	public function refundable_charge_for_item($order_item) {
		$order = $order_item->get_order();
		if ((bool)$order->get('ord_test_mode') !== (bool)$this->test_mode) {
			return array('charge' => null, 'reason' => 'This order was paid in Stripe '
				. ($order->get('ord_test_mode') ? 'test' : 'live') . ' mode; refund it with the site in that mode.');
		}
		if ($order_item->get_payment_source() === 'paypal' || $order->get('ord_paypal_order_id')) {
			return array('charge' => null, 'reason' => 'This was paid through PayPal: refund it in PayPal.');
		}

		try {
			if ($order_item->get('odi_stripe_subscription_id')) {
				$subscription = $this->client()->subscriptions->retrieve(
					$order_item->get('odi_stripe_subscription_id'), ['expand' => ['latest_invoice']]);
				$invoice = $subscription->latest_invoice ?? null;
				$charge_id = is_object($invoice) ? ($invoice->charge ?? null) : null;
				if (!$charge_id) {
					return array('charge' => null, 'reason' => 'The subscription\'s latest invoice has no payment to refund (a trial, or not paid yet).');
				}
				return array(
					'charge' => $this->get_charge($charge_id),
					'is_order_charge' => false,
					'what' => 'the subscription\'s latest payment (invoice ' . ($invoice->number ?: $invoice->id)
						. ', ' . gmdate('Y-m-d', (int)$invoice->created) . ')',
				);
			}

			$charge = null;
			if ($order->get('ord_stripe_charge_id')) {
				$charge = $this->get_charge($order->get('ord_stripe_charge_id'));
			}
			else if ($order->get('ord_stripe_payment_intent_id')) {
				$charge = $this->get_charge_from_payment_intent($order->get('ord_stripe_payment_intent_id'));
			}
		}
		catch (\Stripe\Exception\ExceptionInterface $e) {
			throw new StripeHelperException($e->getMessage(), 0, $e);
		}
		if (!$charge) {
			return array('charge' => null, 'reason' => 'This order has no Stripe card payment to refund.');
		}
		return array('charge' => $charge, 'is_order_charge' => true, 'what' => 'the order\'s card payment');
	}

	public function get_subscription($stripe_subscription_id){
		$stripe_subscription = $this->client()->subscriptions->retrieve($stripe_subscription_id);
		return $stripe_subscription;
	}
	
	public function get_subscriptions($params){
		$subs = $this->client()->subscriptions->all($params);
		return $subs;
	}
	
	public function refund_charge($stripe_charge_id, $refund_amount){
		try{
			$re = $this->client()->refunds->create([
			'charge' => $stripe_charge_id,
			'amount' => (int)round($refund_amount * 100)
			]);
		}
		catch(\Stripe\Exception\ExceptionInterface $e) {
			// Stripe refused it, so nothing was refunded; the refund page says so.
			throw new StripeHelperException($e->getMessage(), 0, $e);
		}
		return $re;
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
				$this->apply_subscription_to_order_item($order_item, $stripe_subscription);
				return $stripe_subscription;
			}
			catch(Exception $e){
				//FAIL SILENTLY
				return false;
			}
		}

	}

	/**
	 * Apply an already-fetched Stripe subscription object to an order item's
	 * local subscription fields and save. Performs NO Stripe API call, so a
	 * subscription fetched in bulk (e.g. via get_subscriptions()) can be applied
	 * without an extra per-item round-trip. The field-write behavior is identical
	 * to update_subscription_in_order_item()'s original inline logic — this only
	 * separates the local write from the fetch.
	 *
	 * @param OrderItem $order_item        The order item row to update.
	 * @param mixed     $stripe_subscription  A \Stripe\Subscription (array-accessible).
	 * @return void
	 */
	public function apply_subscription_to_order_item($order_item, $stripe_subscription){
		// odi_subscription_cancelled_time is when the subscription ENDS, as the
		// change-tier page writes it: a cancel at period end ends at the end of
		// the paid period (Stripe's cancel_at), not at canceled_at, which is the
		// moment the cancel was asked for. Read as the end, a buyer still billed
		// through the period was shown nothing and could not reactivate.
		// A scheduled end (cancel_at: at the period end, or on a chosen date)
		// wins over canceled_at, which is only when it was asked for.
		$at_period_end = !empty($stripe_subscription['cancel_at_period_end']);
		$order_item->set('odi_subscription_cancel_at_period_end', $at_period_end);
		$ends = (int)($stripe_subscription['cancel_at'] ?? 0);
		if (!$ends && $at_period_end) {
			$ends = (int)($stripe_subscription['current_period_end'] ?? 0);
		}
		if ($ends) {
			$order_item->set('odi_subscription_cancelled_time', gmdate('Y-m-d H:i:s', $ends));
		} elseif (!empty($stripe_subscription['canceled_at'])) {
			$order_item->set('odi_subscription_cancelled_time', gmdate('Y-m-d H:i:s', (int)$stripe_subscription['canceled_at']));
		} elseif (in_array($stripe_subscription['status'], array('active', 'trialing', 'past_due'), true)) {
			// Neither ending nor ended: a cancel taken back.
			$order_item->set('odi_subscription_cancelled_time', null);
		}
		// No period end in the answer (an API version without the top-level
		// field) leaves the stored one, never 1970.
		if (!empty($stripe_subscription['current_period_end'])) {
			$order_item->set('odi_subscription_period_end', gmdate('Y-m-d H:i:s', (int)$stripe_subscription['current_period_end']));
		}

		//if($stripe_subscription['status'] == 'canceled' || $stripe_subscription['status'] == 'incomplete_expired'){
		$order_item->set('odi_subscription_status', $stripe_subscription['status']);
		// Reconciliation: the row brought into line with what Stripe just said,
		// often while a page that shows the subscription is being viewed.
		SystemBase::server_initiated_write(function () use ($order_item) { $order_item->save(); });
	}

	public function change_subscription($subscription_id, $item_id_to_update, $new_stripe_price){
		$subscription = $this->client()->subscriptions->update(
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
	
	/**
	 * Schedule a price change to take effect when the current period ends,
	 * leaving the subscriber on what they already paid for until then.
	 *
	 * change_subscription() swaps the price immediately (with proration), which
	 * is right for an upgrade but wrong for a downgrade the customer was told
	 * takes effect at period end. Stripe expresses "later" as a subscription
	 * schedule: phase one is the current price running to the existing period
	 * end, phase two starts the new price.
	 *
	 * @param string $subscription_id
	 * @param string $new_stripe_price The price id to move to at period end
	 * @return object|false The schedule on success, false if it did not take
	 */
	public function schedule_subscription_change($subscription_id, $new_stripe_price){
		$schedule = $this->client()->subscriptionSchedules->create([
			'from_subscription' => $subscription_id,
		]);

		if(empty($schedule->phases)){
			return false;
		}

		// from_subscription seeds a single phase mirroring the live subscription.
		// Preserve it verbatim as the paid-through phase, then append the new price.
		$current = $schedule->phases[0];
		$current_items = [];
		foreach($current->items as $item){
			$current_items[] = [
				'price'    => $item->price,
				'quantity' => isset($item->quantity) ? $item->quantity : 1,
			];
		}

		$updated = $this->client()->subscriptionSchedules->update(
			$schedule->id,
			[
				'phases' => [
					[
						'items'      => $current_items,
						'start_date' => $current->start_date,
						'end_date'   => $current->end_date,
					],
					[
						'items'      => [['price' => $new_stripe_price, 'quantity' => 1]],
						'iterations' => 1,
					],
				],
			]
		);

		return (count($updated->phases) > 1) ? $updated : false;
	}

	public function cancel_subscription($subscription_id, $cancel_type){
		if($cancel_type == 'period_end'){
			$subscription = $this->client()->subscriptions->update(
				$subscription_id,
				[
					'cancel_at_period_end' => true,
				]
			);
			if($subscription->cancel_at_period_end){
				return $subscription;
			}
			else{
				return false;
			}
		}
		else if($cancel_type == 'immediate'){
			$stripe_subscription = $this->get_subscription($subscription_id);
			$subscription = $stripe_subscription->cancel();
			if($subscription->canceled_at){
				return $subscription;
			}
			else{
				return false;
			}
		}
		return false;
	}

	/**
	 * Reactivate a subscription that was scheduled for cancellation
	 *
	 * @param string $subscription_id The Stripe subscription ID
	 * @return object|false Returns the subscription object on success, false on failure
	 */
	public function reactivate_subscription($subscription_id){
		$subscription = $this->client()->subscriptions->update(
			$subscription_id,
			[
				'cancel_at_period_end' => false,
			]
		);

		if($subscription && !$subscription->cancel_at_period_end){
			return $subscription;
		}
		else{
			return false;
		}
	}


	/**
	 * Turn a card token (Stripe.js createToken, or a test token such as
	 * tok_visa) into a PaymentMethod. The card form posts a PaymentMethod; a
	 * token still arrives from a page loaded before the form changed and from
	 * the live store test.
	 *
	 * @throws SystemDisplayableError when the bank refuses the card.
	 * @throws StripeHelperException when Stripe cannot be asked.
	 */
	public function payment_method_from_token($stripe_token) {
		try {
			$method = $this->client()->paymentMethods->create(['type' => 'card', 'card' => ['token' => $stripe_token]]);
		}
		catch (\Stripe\Exception\CardException $e) {
			throw new SystemDisplayableError("Your card was not accepted. " . $e->getMessage());
		}
		catch (Exception $e) {
			error_log("Making a payment method from a token failed: " . $e->getMessage());
			throw new StripeHelperException($e->getMessage(), 0, $e);
		}
		return $method->id;
	}

	/**
	 * Save the checkout's card on the buyer's Stripe customer and make it the
	 * card their invoices are charged to, so a subscription made next pays
	 * with it.
	 *
	 * @return string the saved PaymentMethod's id, which the checkout pays with:
	 *         Stripe's test shortcuts (pm_card_visa) save as a new id
	 * @throws SystemDisplayableError when the bank refuses the card.
	 * @throws StripeHelperException when Stripe cannot be asked (no card was tried).
	 */
	public function attach_card($payment_method_id, $stripe_customer_id): string {
		try {
			$method = $this->client()->paymentMethods->attach($payment_method_id, ['customer' => $stripe_customer_id]);
			$saved_id = (is_object($method) && !empty($method->id)) ? (string)$method->id : (string)$payment_method_id;
			$this->client()->customers->update($stripe_customer_id, [
				'invoice_settings' => ['default_payment_method' => $saved_id],
			]);
		}
		catch (\Stripe\Exception\CardException $e) {
			// The bank refused the card: the buyer is told why.
			$error = "Your card was not accepted. " . $e->getMessage();
			error_log($error);
			throw new SystemDisplayableError($error);
		}
		catch (Exception $e) {
			// Stripe out of reach, a bad key, a method used twice: no card was
			// tried, so it is not reported as a decline.
			error_log("Saving the card failed before the bank was asked: " . $e->getMessage());
			throw new StripeHelperException($e->getMessage(), 0, $e);
		}
		return $saved_id;
	}

	/**
	 * Authorize a card checkout's one-time lines, to be captured once the
	 * rest of the checkout has gone through (capture_card_payment()). An
	 * authorization that is never captured lapses on its own: a buyer who
	 * leaves while their bank is asking to confirm is never charged.
	 *
	 * With $for_later, the bank's confirmation also covers charging this card
	 * again without the buyer present, so the subscription made next does not
	 * ask a second time.
	 *
	 * Returns the PaymentIntent: 'requires_capture' (authorized),
	 * 'requires_action' (the bank wants the buyer to confirm, client_secret
	 * for Stripe.js), or another status the caller treats as refused.
	 *
	 * @throws SystemDisplayableError when the card is declined.
	 * @throws StripeHelperException on a configuration problem.
	 */
	public function authorize_card_payment($amount, $stripe_customer_id, $payment_method_id, $description, $order, $billing_user, bool $for_later) {
		$params = [
			'amount' => (int)round($amount * 100),
			'currency' => Globalvars::get_instance()->get_setting('site_currency'),
			'customer' => $stripe_customer_id,
			'payment_method' => $payment_method_id,
			'payment_method_types' => ['card'],
			'capture_method' => 'manual',
			'confirm' => true,
			'description' => substr((string)$description, 0, 1000),
			'metadata' => [
				'ord_order_id' => $order->key,
				'customer_name' => $billing_user->display_name(),
				'customer_email' => $billing_user->get('usr_email'),
			],
		];
		if ($for_later) {
			$params['setup_future_usage'] = 'off_session';
		}
		return $this->executePaymentWithErrorHandling(function () use ($params) {
			return $this->client()->paymentIntents->create($params);
		}, 'Card authorization');
	}

	/** Take the money a card checkout authorized. */
	public function capture_card_payment($intent_id) {
		return $this->executePaymentWithErrorHandling(function () use ($intent_id) {
			return $this->client()->paymentIntents->capture($intent_id);
		}, 'Card capture');
	}

	public function get_card_payment($intent_id) {
		return $this->client()->paymentIntents->retrieve($intent_id);
	}

	/**
	 * Take back a card checkout's one-time payment: release an authorization
	 * not yet captured, refund one that was.
	 *
	 * @return int what was refunded, in cents (0 when nothing had been taken)
	 * @throws \Stripe\Exception\ExceptionInterface when Stripe refuses.
	 */
	public function undo_card_payment($intent): int {
		$status = (string)$intent->status;
		if ($status === 'succeeded') {
			$this->client()->refunds->create(['payment_intent' => $intent->id]);
			return (int)$intent->amount_received;
		}
		if ($status !== 'canceled') {
			$this->client()->paymentIntents->cancel($intent->id);
		}
		return 0;
	}

	/** What to tell the buyer when a card payment was not authorized. */
	public static function card_payment_refusal($intent): string {
		if (($intent->status ?? '') === 'requires_action') {
			return 'Your bank did not confirm the payment. Please try again or use another card.';
		}
		return self::buyer_reason($intent->last_payment_error ?? null, 'Your card was not accepted.');
	}

	/**
	 * A payment error in words for the buyer. Stripe's message is shown only
	 * for a card error (the bank's decline, worded for the cardholder); its
	 * other errors are worded for the developer ('The provided PaymentMethod
	 * has failed authentication. You can provide payment_method_data...').
	 */
	public static function buyer_reason($error, string $fallback): string {
		if (!is_object($error)) {
			return $fallback;
		}
		if (($error->code ?? '') === 'payment_intent_authentication_failure') {
			return 'Your bank did not confirm the payment. Please try again or use another card.';
		}
		if (($error->type ?? '') === 'card_error' && !empty($error->message)) {
			return (string)$error->message;
		}
		return $fallback;
	}

	public function get_payment_methods($stripe_user_id){
		$result = $this->client()->customers->allPaymentMethods($stripe_user_id, ['limit' => 20]);
		return $result;
	}

	/**
	 * Create a Stripe Billing Portal session for payment method management.
	 */
	public function create_billing_portal_session($stripe_customer_id, $return_url) {
		return $this->client()->billingPortal->sessions->create([
			'customer' => $stripe_customer_id,
			'return_url' => $return_url,
		]);
	}

	/**
	 * Get recent invoices for a Stripe customer.
	 */
	public function get_customer_invoices($stripe_customer_id, $limit = 10) {
		$invoices = $this->client()->invoices->all([
			'customer' => $stripe_customer_id,
			'limit' => $limit,
		]);
		return $invoices->data;
	}
	
	/*  WE ARE NO LONGER USING PLANS  
	public function get_subscription_plan($plan_name){
		$result = $this->client()->plans->retrieve($plan_name);
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

		
		$plan = $this->client()->plans->create($plan_info); 	
		return $plan;
	}
	*/
	
	public function create_subscription($params){
		$subscription = $this->client()->subscriptions->create($params);	
		return $subscription;
	}
	
	public function create_product($params){
		$product = $this->client()->products->create($params);	
		return $product;
	}

	public function create_price($params){
		$price = $this->client()->prices->create($params);	
		return $price;
	}
	


	

	/**
	 * The Stripe price to charge a product version at.
	 *
	 * With no $price, or a $price equal to the version's own (prv_version_price),
	 * this is the version's canonical price: the one a tier change, the billing
	 * page and the webhook read back from prv_stripe_price_id(_test) as the
	 * plan's price. It is found or created at the version's own amount and its id
	 * stored on the version.
	 *
	 * Any other $price (a coupon-discounted checkout) is that one charge's price:
	 * found or created at that amount and returned, never stored on the version,
	 * so a discount never becomes what the plan bills at later.
	 */
	public function get_or_create_price($product_version, $price=NULL){
		$version_price = $product_version->get('prv_version_price');
		if($price === NULL){
			$price = $version_price;
		}
		$amount_cents = (int)round((float)$price * 100);
		$is_canonical = ($amount_cents === (int)round((float)$version_price * 100));

		$product = new Product($product_version->get('prv_pro_product_id'), TRUE);
		
		$settings = Globalvars::get_instance();
		$currency_code = $settings->get_setting('site_currency');
		
		$interval = $product_version->is_subscription();
		$trial_days = (int)$product_version->get('prv_trial_period_days');
		if($interval){
			$stripe_type = 'recurring';
		}
		else{
			$stripe_type = 'one_time';
		}
	
	
		if($this->test_mode){
			$stripe_product_id = $product->get('pro_stripe_product_id_test');
		}
		else{
			$stripe_product_id = $product->get('pro_stripe_product_id');
		}	
		
		if(!$stripe_product_id){
			throw new SystemDisplayablePermanentError("This product does not have a stripe product id."); 
			exit;
		}

		$price_id_field = $this->test_mode ? 'prv_stripe_price_id_test' : 'prv_stripe_price_id';
		$stored_id = (string)$product_version->get($price_id_field);
		
		//FIND AN ACTIVE PRICE OF THIS PRODUCT AT THIS AMOUNT, INTERVAL AND TRIAL
		$stripe_prices = $this->client()->prices->all([
			'product' => $stripe_product_id,
			'active' => 'true',
			'type' => $stripe_type,
			'limit' => 100,
			]);
	
		$found_price = NULL;
		foreach ($stripe_prices->autoPagingIterator() as $stripe_price) {
			if((int)$stripe_price->unit_amount !== $amount_cents){
				continue;
			}
			if($interval){
				$recurring = $stripe_price->recurring;
				if(!$recurring || $recurring->interval !== $interval){
					continue;
				}
				if((int)($recurring->trial_period_days ?? 0) !== $trial_days){
					continue;
				}
			}
			// The id already stored on the version wins over another price at
			// the same amount, so the stored id never flips between twins.
			if($found_price === NULL || $stripe_price->id === $stored_id){
				$found_price = $stripe_price;
			}
		}

		if(!$found_price){
			//IF WE GOT HERE WE NEED TO CREATE ONE
			// The nickname is what labels this price in the Stripe dashboard, where
			// every price of a product otherwise looks alike. Build it from the
			// amount actually being charged. The currency CODE, not the symbol —
			// CurrencyHelper::symbol() is for HTML (it returns '&euro;') and falls
			// back to '$' for any currency it does not know, which would label a
			// GBP price with a dollar sign.
			$nickname = number_format($amount_cents / 100, 2) . ' ' . strtoupper((string)$currency_code);
			if($trial_days){
				$nickname .= '-trial'.$trial_days;
			}
			if(!$is_canonical){
				$nickname .= '-discounted';
			}

			try{
				$stripe_params=array();
				$stripe_params['nickname'] = $nickname;
				$stripe_params['unit_amount'] = $amount_cents;
				$stripe_params['currency'] = $currency_code;
				$stripe_params['product'] = $stripe_product_id;
				if($interval){
					$stripe_params['recurring'] = array('interval' => $interval);
					if($trial_days){
						$stripe_params['recurring']['trial_period_days'] = $trial_days;
					}
				}

				$found_price = $this->create_price($stripe_params);
			}
			catch (Exception $e) {

				throw new SystemDisplayablePermanentError("Stripe price creation failed.  Message: ".$e->getMessage());
			}
		}

		// Only the version's own amount is the version's price. Storing it is
		// the platform's bookkeeping: the amount comes from the product row, not
		// from the request, so it is done whoever reached it (a buyer adding the
		// product to a cart, a member changing tier, an admin editing it).
		if($is_canonical && $found_price->id !== $stored_id){
			$product_version->set($price_id_field, $found_price->id);
			SystemBase::server_initiated_write(function () use ($product_version) { $product_version->save(); });
		}

		return $found_price;
	}




	/**
	 * Start a card checkout's subscription, before anything is written to its
	 * order line. Stripe charges the first invoice as it creates the
	 * subscription, and a declined first invoice is not an error: the
	 * subscription comes back 'incomplete'. The caller keeps it only when it is
	 * 'active' or 'trialing' (regular_subscription_refusal() says why not), and
	 * undoes it (undo_regular_subscription()) if the checkout fails after it.
	 *
	 * @throws SystemDisplayableError when Stripe refuses to create it.
	 */
	public function create_regular_subscription($price, $product_version, $order, $billing_user, $stripe_customer_id, bool $off_session = false){
		$subscription_params = [
			'customer' => $stripe_customer_id,
			'items' => [['price' => $price['id']]],
			'metadata' => [
				'ord_order_id' => $order->key,
				'customer_name' => $billing_user->display_name(),
				'customer_email' => $billing_user->get('usr_email'),
			],
			'expand' => ['latest_invoice.payment_intent'],
		];
		$trial_period_days = intval($product_version->get('prv_trial_period_days'));
		if ($trial_period_days > 0) {
			$subscription_params['trial_period_days'] = $trial_period_days;
		}
		// After the checkout's one-time authorization confirmed the card for
		// later use, the first invoice is charged as a merchant charge, so the
		// bank does not ask the buyer a second time.
		if ($off_session) {
			$subscription_params['off_session'] = true;
		}

		try {
			$subscription = $this->create_subscription($subscription_params);
		}
		catch (\Stripe\Exception\ExceptionInterface $e) {
			error_log('Stripe subscription create failed for order ' . $order->key . ': ' . $e->getMessage());
			throw new SystemDisplayableError("We couldn't start your subscription. " . $e->getMessage());
		}
		if (empty($subscription->id)) {
			throw new SystemDisplayableError("We couldn't start your subscription. Please try again.");
		}
		return $subscription;
	}

	public function get_regular_subscription($subscription_id) {
		return $this->client()->subscriptions->retrieve($subscription_id, ['expand' => ['latest_invoice.payment_intent']]);
	}

	/** The first invoice's PaymentIntent of a subscription made by create_regular_subscription(), or null. */
	public static function regular_subscription_intent($subscription) {
		$intent = $subscription->latest_invoice->payment_intent ?? null;
		return is_object($intent) ? $intent : null;
	}

	/** What to tell the buyer when a new subscription is not live. */
	public static function regular_subscription_refusal($subscription): string {
		$intent = $subscription->latest_invoice->payment_intent ?? null;
		if (is_object($intent) && ($intent->status ?? '') === 'requires_action') {
			return 'Your bank did not confirm the payment. Please try again or use another card.';
		}
		return self::buyer_reason(is_object($intent) ? ($intent->last_payment_error ?? null) : null,
			'Your card was not accepted for the first payment.');
	}

	/**
	 * Take back a subscription a checkout made and then could not complete:
	 * cancel it now, and refund its first invoice if Stripe already took it.
	 *
	 * @return int what was refunded, in cents (0 when nothing had been paid)
	 * @throws \Stripe\Exception\ExceptionInterface when Stripe refuses; the
	 *         caller alerts the operator, since money may still be held.
	 */
	public function undo_regular_subscription($subscription): int {
		$this->client()->subscriptions->cancel($subscription->id);
		$invoice = $subscription->latest_invoice ?? null;
		$intent = is_object($invoice) ? ($invoice->payment_intent ?? null) : null;
		$intent_id = is_object($intent) ? ($intent->id ?? null) : $intent;
		$paid = (int)($invoice->amount_paid ?? 0);
		if ($intent_id && $paid > 0) {
			$this->client()->refunds->create(['payment_intent' => $intent_id]);
			return $paid;
		}
		return 0;
	}

	/** Write a live subscription made by create_regular_subscription() onto its order line. */
	public function record_regular_subscription($order_item, $subscription){
		$invoice = $subscription->latest_invoice ?? null;
		$order_item->set('odi_stripe_subscription_id', $subscription->id);
		$order_item->set('odi_payment_source', 'stripe');
		$order_item->set('odi_stripe_foreign_invoice_id', is_object($invoice) ? $invoice->id : $invoice);
		$order_item->set('odi_is_subscription', true);
		$order_item->set('odi_subscription_status', $subscription->status);
		// Recorded here, not left to the first invoice's webhook: that event
		// is sent as the subscription is made and can arrive before this line
		// exists, and then nothing sets the date until the next renewal.
		if (!empty($subscription->current_period_end)) {
			$order_item->set('odi_subscription_period_end', gmdate('Y-m-d H:i:s', (int)$subscription->current_period_end));
		}
		$order_item->set('odi_status', OrderItem::STATUS_PAID);
		$order_item->set('odi_status_change_time', 'now()');
		$order_item->save();
	}


	/* BEGIN STRIPE CHECKOUT FUNCTIONS */
	

	public function create_stripe_checkout_session($params){
		$stripe_session = $this->client()->checkout->sessions->create($params);
		$this->stripe_checkout_session = $stripe_session;
		return $stripe_session;
	}
	
	public function get_checkout_session($session_id) {
		try {
			$session = $this->client()->checkout->sessions->retrieve($session_id);
			return $session;
		} catch (\Stripe\Exception\ExceptionInterface $e) {
			return false;
		}
	}
	
	public function verify_checkout_session($order) {
		// Test/prod mode validation (following existing pattern from get_charge_from_order)
		if ($order->get('ord_test_mode')) {
			if (!$this->test_mode) {
				// DON'T VERIFY TEST MODE ORDERS IF NOT IN TEST MODE
				return false;
			}
		} else {
			if ($this->test_mode) {
				// DON'T VERIFY LIVE MODE ORDERS IF IN TEST MODE
				return false;
			}
		}
		
		$session_id = $order->get('ord_stripe_session_id');
		if (!$session_id) {
			return false;
		}
		
		try {
			$session = $this->get_checkout_session($session_id);
			if (!$session) {
				return false;
			}
			
			return [
				'session_id' => $session->id,
				'payment_status' => $session->payment_status, // 'paid', 'unpaid', 'no_payment_required'
				'amount_total' => $session->amount_total / 100,
				'currency' => $session->currency,
				'status' => $session->status, // 'complete', 'expired', 'open'
				'payment_intent' => $session->payment_intent
			];
		} catch (\Stripe\Exception\ExceptionInterface $e) {
			return false;
		}
	}
	
	/**
	 * Process webhook - handles ALL Stripe setup and validation internally
	 * @return object Stripe event object
	 * @throws StripeHelperException for configuration errors
	 * @throws \UnexpectedValueException for invalid payload
	 * @throws \Stripe\Exception\SignatureVerificationException for invalid signature
	 */
	public function process_webhook() {
		// Get payload and signature from HTTP request
		$payload = @file_get_contents('php://input');
		$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
		
		if (empty($payload)) {
			throw new \UnexpectedValueException("Empty webhook payload");
		}
		
		if (empty($sig_header)) {
			throw new \Stripe\Exception\SignatureVerificationException("Missing Stripe signature header");
		}
		
		// Get validated endpoint secret and process webhook
		$endpoint_secret = $this->get_webhook_endpoint_secret();
		return \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
	}

}

?>
