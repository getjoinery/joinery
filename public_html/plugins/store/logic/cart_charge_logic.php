<?php

/**
 * Save a payment error to session and return a redirect to /cart.
 * This ensures all payment errors show on the checkout page instead of throwing exceptions.
 */
function _checkout_error($message) {
	$session = SessionControl::get_instance();
	$session->save_message(new DisplayMessage($message, 'Payment Error', null, DisplayMessage::MESSAGE_ERROR));
	return LogicResult::redirect('/checkout');
}

/**
 * Resolve which template name to use for this product's per-product receipt email.
 * Falls back to $default_name when the product has no override or the override
 * points to a missing/soft-deleted template (best-effort, never load-bearing).
 */
function _resolve_receipt_template(Product $product, $default_name) {
	require_once(PathHelper::getIncludePath('data/email_templates_class.php'));
	$override_id = $product->get('pro_emt_receipt_template_id');
	if ($override_id) {
		$tpl = new EmailTemplateStore($override_id, TRUE);
		// SystemBase preserves $key even on failed load. Use emt_name as the
		// "row exists" signal — it's required, so null means the load missed.
		$tpl_name = $tpl->get('emt_name');
		if ($tpl_name && !$tpl->get('emt_delete_time')) {
			return $tpl_name;
		}
	}
	return $default_name;
}

function cart_charge_logic(array $input): LogicResult{

	require_once(PathHelper::getIncludePath('plugins/store/includes/ShoppingCart.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/EmailTemplate.php'));
	require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
	require_once(PathHelper::getIncludePath('plugins/store/includes/StripeHelper.php'));
	require_once(PathHelper::getIncludePath('plugins/store/includes/PaypalHelper.php'));
	require_once(PathHelper::getIncludePath('includes/Activation.php'));
	require_once(PathHelper::getIncludePath('data/groups_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
	require_once(PathHelper::getIncludePath('data/address_class.php'));
	require_once(PathHelper::getIncludePath('data/phone_number_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/product_details_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/coupon_codes_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/coupon_code_uses_class.php'));
	require_once(PathHelper::getIncludePath('data/notifications_class.php'));
	
			
	$page_vars = array();

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;

	// cart_charge is the payment-gateway return handler — Stripe/PayPal redirect the
	// buyer back here via GET — as well as the checkout POST target. Both legitimately
	// persist the order, so opt in to the GET-is-read-only tripwire for the handler.
	//
	// The opt-in spans the whole handler rather than one call, which is why this
	// sets the flag directly instead of using SystemBase::server_initiated_write():
	// the guarded region is the function body, with its own returns.
	SystemBase::$allow_get_mutation = true;
  try {

	if(!$settings->get_setting('products_active')){
		return _checkout_error('Purchasing is currently unavailable. Please try again later.');
	}
	
	$currency_code = $settings->get_setting('site_currency');
	$page_vars['currency_code'] = $currency_code;
	$currency_symbol = CurrencyHelper::symbol($settings->get_setting('site_currency'));
	$page_vars['currency_symbol'] = $currency_symbol;

	


	$cart = ShoppingCart::current();
	$page_vars['cart'] = $cart;
	$charge_total = $cart->get_total();
	


	if($charge_total){
		if($settings->get_setting('use_paypal_checkout')){
			$paypal = new PaypalHelper();
			$page_vars['paypal_helper'] = $paypal;
		}
		if($settings->get_setting('checkout_type') != 'none'){
			$stripe_helper = new StripeHelper();
		}
	}

	$receipts = array();
	$line_summaries = array();
	$applied_coupon_codes = array();
	
	
	if(!$cart->items){
		return LogicResult::redirect('/cart_confirm');		
	}
	
	//DEBUG
	/*
	foreach($cart->get_items() as $key => $cart_item) {
		print_r($cart_item);
	}
	*/


	//HANDLE THE BILLING USER
	$billing_user = User::GetByEmail($cart->billing_user['billing_email']);
	if(!$billing_user){
		$user_data = array(
			'usr_first_name' => $cart->billing_user['billing_first_name'],
			'usr_last_name' => $cart->billing_user['billing_last_name'],
			'usr_email' => $cart->billing_user['billing_email'],		
		);
		
		if(!empty($_POST['password'])){
			$user_data['password'] = $_POST['password'];
		}
		$billing_user = User::CreateCompleteNew($user_data, true, true, false);
		if(!$billing_user){
			return _checkout_error("We couldn't create your account. Please check your information and try again.");
		}
		// Implicit consent was shown under the Continue button on the cart billing form.
		$billing_user->set('usr_terms_accepted_time', gmdate('Y-m-d H:i:s'));
		$billing_user->save();
		$_SESSION['terms_accepted'] = true;
	}
	
	if($settings->get_setting('checkout_type') == 'stripe_regular' || $settings->get_setting('checkout_type') == 'stripe_checkout'){
		$stripe_helper = new StripeHelper();
		$stripe_customer_id = $stripe_helper->get_or_create_stripe_customer($billing_user);
	}

	
	//GET THE ORDER IF IT WAS CREATED EARLIER
	if($settings->get_setting('checkout_type') == 'stripe_checkout' && !empty($_GET['session_id'])){
		
		try {
			$session_id = $stripe_helper->validate_session_id($_GET['session_id']);
			
			if(!$order = Order::GetByStripeSession($session_id)){	
				$error = 'Stripe returned bad or missing session id';
				return _checkout_error("Your payment session could not be verified. Please try again.");				  
			}
			
		} catch (StripeHelperException $e) {
			error_log("Stripe session validation failed: " . $e->getMessage());
			return _checkout_error("Your payment session has expired or is invalid. Please try again.");
		}
	}
	else{
		//CREATE THE ORDER 
		$order = new Order(NULL);
		if(StripeHelper::isTestMode()){
			$order->set('ord_test_mode', true);
		}
		$order->set('ord_usr_user_id', $billing_user->key);
		$order->set('ord_total_cost', $cart->get_total());
		$order->set('ord_timestamp', 'now()');	
		$order->set('ord_raw_cart', print_r($cart, true));
		$order->set('ord_serialized_cart', serialize($cart->get_items_generic()));	
		$order->set('ord_status', Order::STATUS_UNPAID);
		$order->prepare();	
		$order->save();
		$order->load();		
	}
	
	
	
	//CHECK THE COUPON CODES BEFORE WE CHARGE
	foreach($cart->coupon_codes as $coupon_code_name){
		$coupon_code_test = CouponCode::GetByColumn('ccd_code', trim($coupon_code_name));
		if(!$coupon_code_test->is_valid()){
			return _checkout_error("One of your coupon codes is no longer valid. Please remove it and try again.");
		}

	}

	//PHASE 1 of cart_charge_atomicity: pre-charge user resolution.
	//Resolve every per-line recipient user *before* the charge so any user-
	//creation validation error surfaces to /cart without touching the customer's
	//card. send_emails=false here defers the activation email to pass 1 of the
	//post-charge work, gated on $is_newly_created — this prevents declined or
	//abandoned checkouts from emailing the recipient.
	$resolved_users = array();
	$is_newly_created = array();
	foreach($cart->items as $key => $cart_item) {
		list($quantity, $product, $data, $price, $discount, $product_version) = $cart_item;
		if(!empty($data['email'])){
			$existing_user = User::GetByEmail($data['email']);
			if($existing_user){
				$resolved_users[$key] = $existing_user;
				$is_newly_created[$key] = false;
			}
			else {
				try {
					$user_data = array(
						'usr_first_name' => $data['full_name_first'],
						'usr_last_name' => $data['full_name_last'],
						'usr_email' => $data['email'],
					);
					$resolved_users[$key] = User::CreateCompleteNew($user_data, false, false, false);
					$is_newly_created[$key] = true;
				}
				catch (Exception $e) {
					error_log("Pre-charge user resolution failed for cart line {$key} ({$data['email']}): " . $e->getMessage());
					return _checkout_error("We couldn't create an account for " . $data['email'] . ". Please check the recipient details and try again.");
				}
			}
		}
		else {
			$resolved_users[$key] = $billing_user;
			$is_newly_created[$key] = false;
		}
	}

	//Source exclusivity, pre-charge: a recipient whose active subscription is
	//billed through the App Store or Google Play can't start a web-billed one
	//— they must cancel in the store first. Surfaces before any card charge.
	require_once(PathHelper::getIncludePath('plugins/store/includes/TierBilling.php'));
	foreach($cart->items as $key => $cart_item) {
		list($quantity, $product, $data, $price, $discount, $product_version) = $cart_item;
		if($product_version->is_subscription()){
			$conflict = TierBilling::sourceConflict($resolved_users[$key]->key, 'stripe');
			if ($conflict !== null && in_array($conflict, array('app_store', 'play_store'))) {
				return _checkout_error('This subscription is billed through ' . TierBilling::sourceLabel($conflict)
					. '. Cancel it in the app or your store account settings before subscribing here.');
			}
		}
	}

	//Fulfillment availability, pre-charge: anything with a finite supply gets to
	//refuse while the purchase can still be declined for free. fulfill() runs
	//only after payment succeeds, so a refusal there would mean the buyer has
	//already been charged for something that cannot be delivered.
	require_once(PathHelper::getIncludePath('plugins/store/includes/FulfillmentRegistry.php'));
	foreach($cart->items as $key => $cart_item) {
		list($quantity, $product, $data, $price, $discount, $product_version) = $cart_item;
		if(!$product->get('pro_fulfillment_provider')){
			continue;
		}
		$availability_provider = FulfillmentRegistry::get($product->get('pro_fulfillment_provider'));
		if(!$availability_provider){
			//An unresolvable provider is handled after the charge, where it is
			//already logged and stamped onto the order.
			continue;
		}
		try {
			$unavailable = $availability_provider->checkAvailability(
				$product, (int)$product->get('pro_fulfillment_ref'), (int)$quantity);
		}
		catch (\Throwable $e) {
			//A provider that cannot answer must not take the checkout down with
			//it; treat silence as available and let fulfillment report the truth.
			error_log('cart_charge_logic: checkAvailability failed for product #'
				. $product->key . ': ' . $e->getMessage());
			$unavailable = null;
		}
		if($unavailable !== null){
			return _checkout_error($unavailable);
		}
	}

	//Ownership, pre-charge: the store will not charge an account for something
	//it already owns. This is the authoritative guard — the product page and
	//the cart refuse earlier for a better experience, but a replayed URL or a
	//guest who resolves to an existing account mid-checkout is caught here,
	//before any payment call. Checked against the user the payment-time
	//recorder would credit, so a gift to an owner is refused too.
	//
	//A refusal is a refusal, never a discount: no branch below reprices.
	foreach($cart->items as $key => $cart_item) {
		list($quantity, $product, $data, $price, $discount, $product_version) = $cart_item;
		$ownership_tag = trim((string)$product->get('pro_ownership_tag'));
		if($ownership_tag === '' || $product_version->is_subscription()){
			continue;
		}
		$ownership_candidate = $resolved_users[$key];
		if(Ownership::user_owns($ownership_candidate->key, $ownership_tag)){
			return _checkout_error('"' . $product->get('pro_name') . '" is already owned by '
				. $ownership_candidate->get('usr_email')
				. '. Remove it from your cart to continue.');
		}
	}

	$payment_service = '';
	if($charge_total > 0){
		if($settings->get_setting('use_paypal_checkout') && (!empty($_GET['id']) || !empty($_GET['subscription']))){
			$payment_id=$_GET['id'];
			$paypal=new PaypalHelper();
			$payment=$paypal->validatePayment($payment_id);

			// Determine funding source (paypal, venmo, card, etc.)
			$funding_source = isset($_GET['funding']) ? $_GET['funding'] : 'paypal';
			$valid_sources = array('paypal', 'venmo', 'card', 'paylater');
			$payment_method = in_array($funding_source, $valid_sources) ? $funding_source : 'paypal';

			if($_GET['subscription']){
				// Verify PayPal subscription ID if provided
				$paypal_sub_id = isset($_GET['paypal_subscription_id']) ? trim($_GET['paypal_subscription_id']) : null;
				if ($paypal_sub_id) {
					$sub_details = $paypal->subDetails($paypal_sub_id);
					if (!$sub_details || !in_array($sub_details['status'] ?? '', ['ACTIVE', 'APPROVED'])) {
						return _checkout_error('PayPal subscription could not be verified. Please try again.');
					}
				}

				$order->set('ord_status', Order::STATUS_PAID);
				$order->set('ord_payment_method', $payment_method);
				$order->set('ord_raw_response', json_encode($payment));
				if ($paypal_sub_id) {
					$order->set('ord_paypal_order_id', $paypal_sub_id);
				}
				$order->save();

				$payment_service = 'paypal';
				// Store subscription ID on order items after they're created (see below)
			}
			else if($payment['status']=='COMPLETED'){
				$order->set('ord_status', Order::STATUS_PAID);
				$order->set('ord_paypal_order_id', $payment_id);
				$order->set('ord_payment_method', $payment_method);
				$order->set('ord_raw_response', json_encode($payment));
				$order->save();

				$payment_service = 'paypal';
			}
			else{
				$error = 'Paypal returned bad or missing payment id';
				$order->set('ord_error', $error);
				$order->set('ord_status', Order::STATUS_ERROR);
				$order->save();
				return _checkout_error("Your PayPal payment could not be verified. Please try again or use a different payment method.");
			}
		}
		else if($settings->get_setting('checkout_type') == 'stripe_checkout' && $_GET['session_id']){

			$order->set('ord_status', Order::STATUS_PAID);
			$order->set('ord_payment_method', 'stripe_checkout');
			$order->save();


			$payment_service = 'stripe_checkout';
		}
		else if($settings->get_setting('checkout_type') == 'stripe_regular'){

			//CHECK CREDIT CARD INFO AND STORE IF PRESENT FOR REGULAR STRIPE CHECKOUT
			//IF IT IS A NONZERO CART, REQUIRE CREDIT CARD INFO
			if(!isset($_REQUEST['stripeToken'])){
				$order->set('ord_status', Order::STATUS_ERROR);
				$order->set('ord_error', 'The credit card was not submitted because the browser is not using https.');
				$order->save();
				
				$log_error = "The credit card information was not submitted because your browser has javascript turned off or is not using https.  Go back to the previous page and make sure that you are accessing this page from https (look for the lock icon) and turn off any script blockers.  For help, contact us at ".$settings->get_setting('defaultemail')." .";

				return _checkout_error("Your credit card information could not be submitted. Please make sure you are using a secure connection (https) and that JavaScript is enabled.");
			}	

			$source_result = $stripe_helper->create_card_from_token($_REQUEST['stripeToken'], $stripe_customer_id, true);
			$order->set('ord_payment_method', 'stripe');
			$order->save();
			$payment_service = 'stripe_regular';
		}
		else{		
			return _checkout_error("We couldn't process your payment. Please try again or contact support.");
		}
	}
	else{
		
		$order->set('ord_status', Order::STATUS_PAID);
		$order->set('ord_payment_method', 'free');
		$order->save();
		$payment_service = 'none';
	}
	
	//REFRESH THE ORDER 
	$order->load();
		

	
	//NOW CHARGE THE CREDIT CARD FOR THE REMAINING AMOUNT
	if($cart->get_non_recurring_total()){
		if($payment_service == 'stripe_regular'){

			//PROCESS RECURRING ITEMS
			$stripe_item_list = array();
			foreach($cart->items as $key => $cart_item) {
				$email_fill = array();
				list($quantity, $product, $data, $price, $discount, $product_version) = $cart_item;
				$product_name = $product->get('pro_name').' '. $product_version->get('prv_version_name');
				$email_fill['purchase_amount'] = $price - $discount;

				//ASSEMBLE THE STRIPE CHARGE DESCRIPTION
				$stripe_current_item = substr($product_name, 0, 40) .' ('.$quantity.') - $'. ($price - $discount). ' ';
				array_push($stripe_item_list, $stripe_current_item);		
			}	

			try{
				$charge_result = $stripe_helper->executePaymentWithErrorHandling(
					function() use ($stripe_helper, $source_result, $cart, $stripe_customer_id, $stripe_item_list, $billing_user, $order) {
						return $stripe_helper->process_charge($source_result, $cart->get_non_recurring_total(), $stripe_customer_id, $stripe_item_list, $billing_user, $order);
					},
					'Credit card charge processing'
				);
			}
			catch (SystemDisplayableError $e) {
				$order->set('ord_status', Order::STATUS_ERROR);
				$order->set('ord_error', substr($e->getMessage(), 0, 250));
				$order->save();

				require_once(PathHelper::getIncludePath('includes/SignalBus.php'));
				SignalBus::dispatch('payment.failed', array(
					'order_id'      => $order->key,
					'user_id'       => $billing_user->key,
					'error_message' => substr($e->getMessage(), 0, 160),
				));

				return _checkout_error($e->getMessage());
			}
			catch (StripeHelperException $e) {
				error_log("Stripe configuration error during payment: " . $e->getMessage());
				$order->set('ord_status', Order::STATUS_ERROR);
				$order->set('ord_error', 'Stripe configuration error');
				$order->save();
				$support_email = $settings->get_setting('defaultemail');
				return _checkout_error("Your payment could not be processed. Please try again or contact support" . ($support_email ? " at $support_email" : "") . ".");
			}

			//STORE THE CHARGE ID AND MARK PAID
			//Phase 2 of cart_charge_atomicity: mark PAID immediately upon successful
			//charge to eliminate the UNPAID-with-charge-id contradiction. PayPal,
			//Stripe checkout, and free paths already mark PAID earlier in payment-
			//path branching; this completes the invariant for stripe_regular.
			$order->set('ord_stripe_charge_id', $charge_result->id);
			$order->set('ord_status', Order::STATUS_PAID);
			$order->save();
		}
	}
	
	
	
	
	
	
	// =========================================================================
	// PHASE 3 of cart_charge_atomicity: post-charge work as a two-pass restructure.
	//   Pass 1: data persistence (must succeed for the line to be "real")
	//   R1/R2/R3: send receipts referencing pass-1 data
	//   Pass 2: best-effort side effects (failure does not block receipts)
	// All wrapped in an inner try/catch. On exception: log to ord_error, notify
	// ops, render the success page anyway. Order is already PAID (Phase 2)
	// before we enter this block, so a partial failure leaves the customer with
	// a confirmed payment and the operator with a worklist via /admin/admin_orders.
	// =========================================================================
	$r1_already_sent = false;
	$confirmation_surveys = array();

	$send_r1_r2_r3 = function() use (&$line_summaries, &$applied_coupon_codes, &$billing_user, &$order, &$currency_symbol) {
		$applied_coupon_codes_list = array_keys($applied_coupon_codes);

		$build_line_for_template = function($summary, $for_billing_view) {
			$line = array(
				'product_name' => $summary['product_name'],
				'quantity' => $summary['quantity'],
				'outcome' => $summary['outcome'],
				'is_gift_to' => null,
				'event_name' => $summary['event_name'],
				'digital_link' => $summary['digital_link'],
				'event_list' => is_array($summary['event_list']) ? implode('<br>', $summary['event_list']) : null,
				'subscription_active' => ($summary['outcome'] === 'subscription'),
			);
			if ($for_billing_view) {
				$line['price'] = $summary['price'];
				if ($summary['is_gift']) {
					$line['is_gift_to'] = $summary['recipient_email'];
				} else {
					$line['act_code'] = $summary['act_code'];
					$line['event_registrant_id'] = $summary['event_registrant_id'];
				}
			} else {
				$line['act_code'] = $summary['act_code'];
				$line['event_registrant_id'] = $summary['event_registrant_id'];
			}
			return $line;
		};

		// --- R1: default order receipt to billing user ----------------------
		if (!empty($line_summaries)) {
			$billing_lines = array();
			foreach ($line_summaries as $summary) {
				$billing_lines[] = $build_line_for_template($summary, true);
			}
			$billing_fill = array(
				'recipient' => $billing_user->export_as_array(),
				'is_billing' => true,
				'order' => $order->export_as_array(),
				'order_total' => $order->get('ord_total_cost'),
				'currency_symbol' => $currency_symbol,
				'line_items' => $billing_lines,
				'coupon_codes_used' => $applied_coupon_codes_list,
			);
			try {
				EmailSender::sendTemplate('purchase_receipt_default', $billing_user->get('usr_email'), $billing_fill);
			} catch (Exception $e) {
				error_log('purchase_receipt_default send to billing user failed: ' . $e->getMessage());
			}
		}

		// --- R2: per-registrant activation for event/bundle gift recipients -
		$registrant_groups = array();
		foreach ($line_summaries as $summary) {
			if (!$summary['is_gift']) continue;
			if (!in_array($summary['outcome'], array('event', 'bundle'), true)) continue;
			$registrant_groups[$summary['recipient_email']][] = $summary;
		}
		foreach ($registrant_groups as $recipient_email => $summaries) {
			$registrant_lines = array();
			foreach ($summaries as $summary) {
				$registrant_lines[] = $build_line_for_template($summary, false);
			}
			$registrant_user = $summaries[0]['recipient_user'];
			$registrant_fill = array(
				'recipient' => $registrant_user->export_as_array(),
				'is_billing' => false,
				'order' => $order->export_as_array(),
				'currency_symbol' => $currency_symbol,
				'line_items' => $registrant_lines,
			);
			try {
				EmailSender::sendTemplate('purchase_receipt_default', $recipient_email, $registrant_fill);
			} catch (Exception $e) {
				error_log('purchase_receipt_default registrant send failed: ' . $e->getMessage());
			}
		}

		// --- R3: per-product additional email to billing user (deduped) ----
		$seen_product_ids = array();
		foreach ($line_summaries as $summary) {
			$r3_product = $summary['product'];
			if (isset($seen_product_ids[$r3_product->key])) continue;
			$has_msg = (bool)$r3_product->get('pro_after_purchase_message');
			$has_override = (bool)$r3_product->get('pro_emt_receipt_template_id');
			if (!$has_msg && !$has_override) continue;
			$seen_product_ids[$r3_product->key] = true;

			$tpl_name = _resolve_receipt_template($r3_product, 'purchase_receipt_product_default');
			$product_fill = array(
				'recipient' => $billing_user->export_as_array(),
				'product_name' => $summary['product_name'],
				'after_purchase_message' => (string)$r3_product->get('pro_after_purchase_message'),
				'order_item' => $summary['order_item']->export_as_array(),
				'order' => $order->export_as_array(),
			);
			try {
				EmailSender::sendTemplate($tpl_name, $billing_user->get('usr_email'), $product_fill);
			} catch (Exception $e) {
				error_log("Per-product receipt send ({$tpl_name}) failed: " . $e->getMessage());
			}
		}
	};

	try {

		// ----- PASS 1: data persistence -----
		// Must succeed for the line to be "real". If anything throws here, the
		// outer catch handler records ord_error, fires a best-effort R1 with
		// whatever has accumulated in $line_summaries, and notifies ops.
		foreach($cart->items as $key => $cart_item) {
			$email_fill = array();
			list($quantity, $product, $data, $price, $discount, $product_version) = $cart_item;
			$product_name = $product->get('pro_name').' '.$product_version->get('prv_version_name');
			$email_fill['purchase_amount'] = $price - $discount;

			//User was resolved pre-charge (Phase 1). Pulling from the parallel
			//array means user-creation validation errors have already surfaced
			//to /cart with no Stripe activity.
			$user = $resolved_users[$key];

			//Fire activation email for newly-created recipient users (deferred
			//from Phase 1's send_emails=false pre-charge resolution). Best-
			//effort: a send failure here is non-blocking — the recipient can
			//set their initial password via forgot-password.
			if(!empty($is_newly_created[$key])){
				try {
					Activation::email_activate_send($user);
				}
				catch (Exception $e) {
					error_log("Activation email send failed for user {$user->key}: " . $e->getMessage());
				}
			}

			//CREATE THE ORDER ITEM
			$order_item = new OrderItem(NULL);
			$order_item->set('odi_ord_order_id', $order->key);
			$order_item->set('odi_pro_product_id', $product->key);
			$order_item->set('odi_usr_user_id', $user->key);
			$order_item->set('odi_product_info', base64_encode(serialize($data)));
			$order_item->set('odi_price', $price - $discount);
			$order_item->set('odi_prv_product_version_id', $product_version->key);

			if($product_version->is_subscription()){
				$order_item->set('odi_is_subscription', true);
			}
			else{
				$order_item->set('odi_is_subscription', false);
			}

			//STORE COMMENT IF ENTERED (legacy — now handled via QuestionRequirement)
			if(isset($data['comment']) && !is_array($data['comment'])){
				$order_item->set('odi_comment', $data['comment']);
			}

			$order_item->set('odi_prv_product_version_id', $product_version->key);
			$order_item->set('odi_status', OrderItem::STATUS_UNPAID);
			$order_item->set('odi_status_change_time', 'now()');

			$order_item->save();
			$order_item->load();

			//SAVE THE EXTRA INFO THE USER ENTERED.
			$order_item->save_cart_data($data);

			//STORE ANY USED COUPONS, ONE ENTRY IN THE COUPON CODES USE TABLE, FK IN ORDER ITEMS
			foreach($cart->coupon_codes as $coupon_code_name){
				if($valid_coupons = $product->get_valid_coupons($product_version)){
					foreach($valid_coupons as $valid_coupon){
						if($coupon_code_name == $valid_coupon->get('ccd_code')){
							$coupon_code_use = new CouponCodeUse(NULL);
							$coupon_code_use->set('ccu_odi_order_item_id', $order_item->key);
							$coupon_code_use->set('ccu_ccd_coupon_code_id', $valid_coupon->key);
							$coupon_code_use->set('ccu_amount_discount', $valid_coupon->get('ccd_amount_discount'));
							$coupon_code_use->set('ccu_percent_discount', $valid_coupon->get('ccd_percent_discount'));
							$coupon_code_use->prepare();
							$coupon_code_use->save();
							$applied_coupon_codes[$valid_coupon->get('ccd_code')] = true;
						}
					}
				}
			}

			//HANDLE SUBSCRIPTIONS — data persistence only; notifications run in pass 2.
			$subscription_result = null;
			if($product_version->is_subscription()){
				if($payment_service == 'stripe_regular'){
					$final_price = $price - $discount;
					$stripe_price = $stripe_helper->get_or_create_price($product_version, $final_price);
					$subscription_result = $stripe_helper->process_stripe_regular_subscription_from_order_item($stripe_price, $order_item, $billing_user, $stripe_customer_id);
					$order_item->set('odi_subscription_status', $subscription_result['status']);
					$order_item->set('odi_status', OrderItem::STATUS_PAID);
					$order_item->save();
				}
				else if($payment_service == 'stripe_checkout'){
					$order_item->set('odi_status', OrderItem::STATUS_PAID);
					//MOVE THE SUBSCRIPTION ID FROM THE ORDER TO THE ORDER ITEM
					$order_item->set('odi_stripe_subscription_id', $order->get('ord_stripe_subscription_id_temp'));
					$order_item->set('odi_payment_source', 'stripe');
					$order_item->save();
					$order->set('ord_stripe_subscription_id_temp', NULL);
					$order->save();
				}
				else if($payment_service == 'paypal' && isset($_GET['paypal_subscription_id']) && $_GET['paypal_subscription_id']){
					$order_item->set('odi_status', OrderItem::STATUS_PAID);
					$order_item->set('odi_paypal_subscription_id', $_GET['paypal_subscription_id']);
					$order_item->set('odi_payment_source', 'paypal');
					$order_item->set('odi_subscription_status', 'active');
					$order_item->save();
				}
			}
			else{
				//NON-SUBSCRIPTION: paid above
				$order_item->set('odi_status', OrderItem::STATUS_PAID);
				$order_item->save();
			}

			//FULFILL THE PRODUCT — data persistence (R2 receipt depends on the
			//returned reference id) plus the provider's own best-effort side
			//effects (notifications, signals). The provider owns everything
			//kind-specific about what a purchase grants; the store just invokes it.
			$event_registrant_id = null;
			$event_name = null;
			$event_list = null;
			$fulfillment_result = null;
			if($product->get('pro_fulfillment_provider')){
				require_once(PathHelper::getIncludePath('plugins/store/includes/FulfillmentRegistry.php'));
				$fulfillment_provider = FulfillmentRegistry::get($product->get('pro_fulfillment_provider'));
				if($fulfillment_provider){
					$fulfillment_result = $fulfillment_provider->fulfill($user, $product, $order_item, $order, (int)$product->get('pro_fulfillment_ref'));
					$event_registrant_id = $fulfillment_result['ref_id'] ?? null;
					$event_name = $fulfillment_result['label'] ?? null;
					$event_list = $fulfillment_result['labels'] ?? null;
					if(!empty($fulfillment_result['confirmation_survey'])){
						$confirmation_surveys[] = $fulfillment_result['confirmation_survey'];
					}
				}
				else{
					//A product declares a fulfillment provider but its key resolves
					//to nothing (plugin deactivated, typo, not yet registered). That
					//is an error, not an ungated product: the buyer paid but nothing
					//was granted. Complete the charge, but log it and stamp ord_error
					//so it surfaces in /admin/admin_orders for manual follow-up.
					$fulfillment_error = 'Unregistered fulfillment provider "'
						. $product->get('pro_fulfillment_provider')
						. '" on product #' . $product->key . ' — purchase completed without fulfillment.';
					error_log('cart_charge_logic: ' . $fulfillment_error . ' (order #' . $order->key . ')');
					$order->set('ord_error', substr($fulfillment_error, 0, 250));
					$order->save();
				}
			}

			//Outcome category (mirrors template body conditionals). The fulfilled
			//cases are driven by the provider's result shape, not by any product
			//column the store would have to interpret.
			if ($fulfillment_result && !empty($fulfillment_result['labels'])) {
				$outcome = 'bundle';
			} else if ($fulfillment_result && !empty($fulfillment_result['ref_id'])) {
				$outcome = 'event';
			} else if ($product_version->is_subscription()) {
				$outcome = 'subscription';
			} else if ($product->get('pro_digital_link')) {
				$outcome = 'digital';
			} else {
				$outcome = 'plain';
			}

			//Canonicalize emails to decide is_gift. Empty data['email'] already
			//collapsed to billing user during pre-charge resolution.
			$billing_email_canon = strtolower(trim($billing_user->get('usr_email')));
			$line_email_canon = strtolower(trim($user->get('usr_email')));
			$is_gift = ($line_email_canon !== $billing_email_canon);

			$line_summaries[$key] = array(
				'order_item' => $order_item,
				'product' => $product,
				'product_version' => $product_version,
				'cart_data' => $data,
				'product_name' => $product_name,
				'quantity' => $quantity,
				'price' => $price - $discount,
				'recipient_email' => $line_email_canon,
				'recipient_user' => $user,
				'is_gift' => $is_gift,
				'outcome' => $outcome,
				'act_code' => $user->get('usr_act_code'),
				'event_registrant_id' => $event_registrant_id,
				'event_name' => $event_name,
				'event_list' => $event_list,                       // array of names, or null
				'digital_link' => $product->get('pro_digital_link'),
				'subscription_result' => $subscription_result,     // for pass-2 ops notification
			);

			//Legacy receipts array for $cart->last_receipt (used by older display paths).
			if($product_version->get('prv_trial_period_days')){
				$trial = ' (' . $product_version->get('prv_trial_period_days') . ' day free trial)';
			}
			else{
				$trial = '';
			}

			$receipts[$key+1]['pname'] = $product_name . $trial;
			$receipts[$key+1]['name'] = $data['full_name_first'].' '.$data['full_name_last'];
			$receipts[$key+1]['price'] = $price - $discount;
			$receipts[$key+1]['after_purchase_message'] = $product->get('pro_after_purchase_message');
			if($product->get('pro_digital_link')){
				$receipts[$key+1]['link'] = $product->get('pro_digital_link');
			}
		}

		// =========================================================================
		// BUILD AND SEND RECEIPT EMAILS (between passes — receipts reference
		// fully-persisted pass-1 data; pass-2 plugin failures cannot strand them).
		// Routing rules per specs/receipts_refactor.md §6.1:
		//   R1 — one default order receipt to the billing user, always
		//   R2 — per-registrant activation email for event/bundle gift recipients
		//   R3 — per-product additional email (opt-in) to the billing user, deduped
		// =========================================================================
		$send_r1_r2_r3();
		$r1_already_sent = true;

		// ----- PASS 2: best-effort side effects -----
		// Failures here do NOT block receipts (already sent above). Each block
		// is individually try/wrapped so one failing extension point does not
		// skip later blocks for the same line. The outer pass-2 try/catch (the
		// inner try/catch of the whole post-charge block) catches anything
		// truly unexpected and routes it to ord_error + ops notification.
		foreach($cart->items as $key => $cart_item) {
			list($quantity, $product, $data, $price, $discount, $product_version) = $cart_item;
			if (!isset($line_summaries[$key])) continue;
			$user = $resolved_users[$key];
			$summary = $line_summaries[$key];
			$order_item = $summary['order_item'];

			//Ownership. A tagged product makes its buyer an owner the moment
			//the item is paid for — this is core's job, not a script's. It runs
			//before the product scripts so a fulfillment script (a key mailer,
			//say) finds the row it decorates already there.
			try {
				Ownership::record_purchase($product, $product_version, $user, $order_item, $order);
			}
			catch (Exception $e) {
				error_log("Ownership recording failed for product {$product->key} (order {$order->key}): " . $e->getMessage());
			}

			//Plugin product scripts.
			try {
				$product->run_product_scripts($user, $order_item, $order);
			}
			catch (Exception $e) {
				error_log("run_product_scripts failed for product {$product->key} (order {$order->key}): " . $e->getMessage());
			}

			//Requirement post_purchase hooks.
			try {
				require_once(PathHelper::getIncludePath('plugins/store/includes/requirements/AbstractProductRequirement.php'));
				foreach (AbstractProductRequirement::getProductRequirements($product->key) as $requirement) {
					$requirement->post_purchase($data, $order_item, $user, $order);
				}
			}
			catch (Exception $e) {
				error_log("Requirement post_purchase failed for product {$product->key} (order {$order->key}): " . $e->getMessage());
			}

			//Subscription tier assignment — only if payment was successful.
			if($order_item->get('odi_status') == OrderItem::STATUS_PAID) {
				try {
					require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/includes/TierBilling.php'));
					TierBilling::handleProductPurchase($user, $product, $order_item, $order);
				}
				catch (Exception $e) {
					error_log("TierBilling::handleProductPurchase failed for product {$product->key} (order {$order->key}): " . $e->getMessage());
				}
			}

			//Refresh cached remaining-count for capacity-limited products.
			if($product->get('pro_max_purchase_count') > 0){
				try {
					$remaining = $product->get('pro_max_purchase_count') - $product->get_number_purchased();
					$product->set('pro_num_remaining_calc', $remaining);
					$product->save();
				}
				catch (Exception $e) {
					error_log("pro_num_remaining_calc refresh failed for product {$product->key}: " . $e->getMessage());
				}
			}

			//Event-registration notification + admin signal are owned by the
			//fulfillment provider (fired during fulfill() in pass 1).

			if($product_version->is_subscription()){
				//In-app notification: subscription confirmed.
				try {
					Notification::create_notification(
						$user->key,
						'subscription',
						'Your ' . $product->get('pro_name') . ' subscription is active',
						'Order #' . $order->key,
						'/profile#orders',
						null
					);
				} catch (Exception $e) { /* notification system not available */ }

				//Admin alert: new subscription.
				require_once(PathHelper::getIncludePath('includes/SignalBus.php'));
				SignalBus::dispatch('subscription.started', array(
					'order_id'       => $order->key,
					'order_item_id'  => $order_item->key,
					'user_id'        => $user->key,
					'source_user_id' => $user->key,
					'product_id'     => $product->key,
					'product_name'   => $product->get('pro_name'),
					'buyer_name'     => $billing_user->display_name(),
					'buyer_email'    => $billing_user->get('usr_email'),
				));
			}
			else{
				//In-app notification: purchase confirmed.
				try {
					Notification::create_notification(
						$user->key,
						'order',
						'Your purchase is confirmed: ' . $product->get('pro_name'),
						'Order #' . $order->key,
						'/profile#orders',
						null
					);
				} catch (Exception $e) { /* notification system not available */ }

				//Admin alert: completed sale.
				require_once(PathHelper::getIncludePath('includes/SignalBus.php'));
				SignalBus::dispatch('purchase.completed', array(
					'order_id'       => $order->key,
					'user_id'        => $user->key,
					'source_user_id' => $user->key,
					'product_id'     => $product->key,
					'product_name'   => $product->get('pro_name'),
					'buyer_name'     => $billing_user->display_name(),
					'buyer_email'    => $billing_user->get('usr_email'),
					'amount'         => (string)$order->get('ord_total_cost'),
					'currency'       => $currency_code,
				));
			}
		}

		// Record PURCHASE conversion event — canonical attribution signal for
		// revenue-by-channel. UTM is carried automatically from the session.
		require_once(PathHelper::getIncludePath('data/visitor_events_class.php'));
		$session->save_visitor_event(VisitorEvent::TYPE_PURCHASE, FALSE, 'order', $order->key);
		unset($_SESSION['checkout_started']);

		// Optional post-purchase surveys for confirmation page display are
		// collected during fulfillment (the provider returns them per line).
		$session->save_session_item('confirmation_surveys', $confirmation_surveys);

	}
	catch (Exception $post_charge_e) {
		// Phase 3 lite safety net: post-charge work threw, but order is already
		// PAID (Phase 2). Record the error, page the operator, render the
		// success page anyway so the customer doesn't see a generic error after
		// being charged.
		error_log("Post-charge work failed for order {$order->key}: " . $post_charge_e->getMessage() . " in " . $post_charge_e->getFile() . ":" . $post_charge_e->getLine());

		try {
			$order->set('ord_error', substr($post_charge_e->getMessage(), 0, 250));
			$order->save();
		}
		catch (Exception $inner_e) {
			error_log("Failed to write ord_error for order {$order->key}: " . $inner_e->getMessage());
		}

		// Best-effort R1 send if pass 1 threw before reaching the receipt block.
		if (!$r1_already_sent) {
			try {
				$send_r1_r2_r3();
			}
			catch (Exception $r1_e) {
				error_log("Best-effort R1 send failed for order {$order->key}: " . $r1_e->getMessage());
			}
		}

		// Operator notification — paged the instant it happens.
		try {
			$ops_email = $settings->get_setting('defaultemail');
			if ($ops_email) {
				$body = "Post-charge work failed for order #{$order->key}.\n\n".
					"Billing user: ".$billing_user->get('usr_email')."\n".
					"Error: ".$post_charge_e->getMessage()."\n".
					"Location: ".$post_charge_e->getFile().":".$post_charge_e->getLine()."\n\n".
					"Order is PAID; manual fix-up via /admin/admin_orders.";
				$email_inner_template = $settings->get_setting('individual_email_inner_template');
				EmailSender::sendTemplate($email_inner_template, $ops_email, array(
					'subject' => 'Order #'.$order->key.' post-charge failure',
					'body' => $body,
					'recipient' => array('display_name' => 'Operator')
				));
			}
		}
		catch (Exception $ops_e) {
			error_log("Operator notification failed for order {$order->key}: " . $ops_e->getMessage());
		}
	}

	// Always run — order is PAID (Phase 2), errors recorded on ord_error for
	// operator follow-up, customer sees the success page.
	$cart->last_receipt = $receipts;
	$cart->clear_cart();

	return LogicResult::render($page_vars);

  } catch (Exception $e) {
	error_log("Checkout error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
	$support_email = $settings->get_setting('defaultemail');
	return _checkout_error("Something went wrong processing your order. Please try again" . ($support_email ? " or contact us at $support_email" : "") . ".");
  } finally {
	SystemBase::$allow_get_mutation = false;
  }
}

?>