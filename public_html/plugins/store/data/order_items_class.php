<?php

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));
require_once(PathHelper::getIncludePath('includes/EmailTemplateRenderer.php'));
require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/includes/StripeHelper.php'));

require_once(PathHelper::getIncludePath('data/users_addrs_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/order_item_requirements_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));

class OrderItemException extends SystemBaseException {}

/**
 * OrderItem - one line of an order; a subscription line carries its billing state.
 *
 * @version 1.4 - refund_payment(): a line is refunded from the payment that paid it (a subscription's own
 *                latest invoice, or the order's charge), capped per line, and can cancel a subscription with it
 * @version 1.3 - a cancel notice goes to each address on subscription_notification_emails whether or not it is a
 *                user's: one that was not stopped the cancel page with a 500 after the provider had cancelled
 * @version 1.2 - cancel_subscription_by_system(): the same provider cancel for a scheduled task acting on the
 *                plane's own decision, with no signed-in user to check and no store emails
 * @version 1.1 - cancel_subscription_order_item() cancels with the provider that bills the subscription
 *                (Stripe or PayPal) and refuses an app-store one with where the buyer cancels it
 *                (subscription_cancel_blocker()); it called Stripe for every provider
 */

class OrderItem extends SystemBase {	public static $prefix = 'odi';

	public static $tablename = 'odi_order_items';
	public static $access_class = 'personal';
	public static $pkey_column = 'odi_order_item_id';

	// REST CRUD exposure (Layer 1). User-owned (Bucket B): readable + writable
	// under the deny-by-default owner-or-staff row scope.
	public static $api_readable = true;
	public static $api_writable = true;

	// AI auto-discovery (read)
	public static $ai_readable        = true;
	public static $ai_description     = 'Line items within the user\'s orders.';
	public static $ai_excluded_fields = [];
	public static $ai_untrusted_fields = ['odi_comment'];

	protected static $foreign_key_actions = [
		'odi_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED],
		'odi_pro_product_id' => ['action' => 'prevent', 'message' => 'Cannot delete product - order items exist'],
		'odi_evr_event_registrant_id' => ['action' => 'prevent', 'message' => 'Cannot delete event registration - order items exist'],
		'odi_ord_order_id' => ['action' => 'permanent_delete'],
		'odi_prv_product_version_id' => ['action' => 'prevent', 'message' => 'this product version has been ordered - order items reference it']
	];

	public const STATUS_UNPAID = 1;
	public const STATUS_PAID = 2;
	public const STATUS_ERROR = 3;

		/**
	 * Field specifications define database column properties and validation rules
	 * 
	 * Database schema properties (used by update_database):
	 *   'type' => 'varchar(255)' | 'int4' | 'int8' | 'text' | 'timestamp' | 'bool' | etc.
	 *   'is_nullable' => true/false - Whether NULL values are allowed
	 *   'serial' => true/false - Auto-incrementing field
	 * 
	 * Validation and behavior properties (used by SystemBase):
	 *   'required' => true/false - Field must have non-empty value on save
	 *   'default' => mixed - Default value for new records (applied on INSERT only)
	 *   'zero_on_create' => true/false - Set to 0 when creating if NULL (INSERT only)
	 * 
	 * Note: Timestamp fields are auto-detected based on type for smart_get() and export_as_array()
	 */
	public static $field_specifications = array(
	    'odi_order_item_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'odi_ord_order_id' => array('type'=>'int4', 'required'=>true),
	    'odi_pro_product_id' => array('type'=>'int4', 'required'=>true),
	    'odi_prv_product_version_id' => array('type'=>'int4'),
	    'odi_product_info' => array('type'=>'text'),
	    'odi_price' => array('type'=>'numeric(10,2)'),
	    'odi_status' => array('type'=>'int2'),
	    'odi_status_change_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'odi_usr_user_id' => array('type'=>'int4'),
	    'odi_evr_event_registrant_id' => array('type'=>'int4'),
	    'odi_comment' => array('type'=>'varchar(255)'),
	    'odi_stripe_subscription_id' => array('type'=>'varchar(255)'),
	    'odi_is_subscription' => array('type'=>'bool'),
	    'odi_subscription_cancelled_time' => array('type'=>'timestamp(6)'),
	    'odi_refund_amount' => array('type'=>'numeric(10,2)'),
	    'odi_refund_note' => array('type'=>'varchar(255)'),
	    'odi_refund_time' => array('type'=>'timestamp(6)'),
	    'odi_stripe_foreign_invoice_id' => array('type'=>'varchar(64)'),
	    'odi_subscription_status' => array('type'=>'varchar(64)'),
	    'odi_subscription_period_end' => array('type'=>'timestamp(6)'),
	    'odi_subscription_cancel_at_period_end' => array('type'=>'bool', 'default'=>false),
	    'odi_paypal_subscription_id' => array('type'=>'varchar(64)'),
	    'odi_payment_source' => array('type'=>'varchar(20)'),
	    'odi_app_store_original_transaction_id' => array('type'=>'varchar(64)'),
	    'odi_play_purchase_token' => array('access_class' => 'restricted', 'type'=>'varchar(255)'),
	    'odi_store_environment' => array('type'=>'varchar(20)'),
	);

function get_order() {
		$order = new Order($this->get('odi_ord_order_id'), TRUE);
		return $order;
	}

	/**
	 * The billing system this item is paid through: 'stripe', 'paypal',
	 * 'app_store', 'play_store', or 'none'. Reads the stored
	 * odi_payment_source when set; derives from the provider-ID columns for
	 * rows written before the field existed.
	 */
	public function get_payment_source() {
		if ($this->get('odi_payment_source')) {
			return $this->get('odi_payment_source');
		}
		if ($this->get('odi_stripe_subscription_id')) {
			return 'stripe';
		}
		if ($this->get('odi_paypal_subscription_id')) {
			return 'paypal';
		}
		if ($this->get('odi_app_store_original_transaction_id')) {
			return 'app_store';
		}
		if ($this->get('odi_play_purchase_token')) {
			return 'play_store';
		}
		return 'none';
	}
	
	function get_data() {
		$data = unserialize(base64_decode((string)$this->get('odi_product_info')));
		$clean_data = array();
		// An item bought without product info (a subscription renewal, an app
		// store purchase) has none to show.
		if (!is_array($data)) {
			return $clean_data;
		}
		foreach($data as $key => $value) {
			// Check if value is a string before applying htmlspecialchars
			if (is_string($value)) {
				$clean_data[$key] = htmlspecialchars($value);
			} elseif (is_object($value) || is_array($value)) {
				// For objects and arrays, keep them as-is
				$clean_data[$key] = $value;
			} else {
				// For other scalar types (int, float, bool), convert to string first
				$clean_data[$key] = htmlspecialchars((string)$value);
			}
		}
		return $clean_data;
	}
	
	//THIS WILL RETURN THE DATA STORED IN THE DATABASE FOR THIS ORDER ITEM
	function get_all_data() {
		
		$order_requirements = new MultiOrderItemRequirement(
			array('order_item_id'=>$this->key),
			NULL,
			NULL,
			NULL);
		$numrecords = $order_requirements->count_all();
		$order_requirements->load();		
		
		return $order_requirements;
	}	
	
	function save_cart_data($data_items){
		
		if(empty($data_items)){
			return true;
		}
		
		foreach ($data_items as $name=>$info){
			
			$order_item_requirement = new OrderItemRequirement(NULL);
			$order_item_requirement->set('oir_odi_order_item_id', $this->key);
			
			if(is_array($info)){
				$order_item_requirement->set('oir_label', $info['question']);
				$order_item_requirement->set('oir_answer', $info['answer']);
				
				if(isset($info['question_id'])){
					$order_item_requirement->set('oir_qst_question_id', $info['question_id']);
				}
		
				if(isset($info['requirement_id'])){
					$order_item_requirement->set('oir_prq_product_requirement_id', $info['requirement_id']);
				}	
			}
			else if(is_object($info)){
				$order_item_requirement->set('oir_label', $name);
				$order_item_requirement->set('oir_answer', $info->get_address_string(' '));
			}		
			else{
				$order_item_requirement->set('oir_label', $name);
				$order_item_requirement->set('oir_answer', $info);
			}

			$order_item_requirement->prepare();
			$order_item_requirement->save();
			
		}
		return true;
	}

	function get_raw_data() {
		return unserialize(base64_decode($this->get('odi_product_info')));
	}

	function get_product_version($product_id){
		
		if($this->get('odi_prv_product_version_id')){
			$product_version = new ProductVersion($this->get('odi_prv_product_version_id'), TRUE);
			if(!$product_version->get('prv_status')){
				return false;
			}
			else{
				return $product_version;
			}
		}
		else{
			return FALSE;
		}
		
	}
	
	/**
	 * Why this subscription cannot be cancelled from this site, or null when it can.
	 *
	 * A subscription bought in an app store is the buyer's to cancel there: the
	 * merchant has no cancel for Apple's, and none is built for Google Play's.
	 * One with no payment provider behind it has nothing to cancel.
	 */
	public function subscription_cancel_blocker() {
		switch ($this->get_payment_source()) {
			case 'stripe':
			case 'paypal':
				return null;
			case 'app_store':
				return 'Bought through the App Store: cancel it in your Apple ID\'s subscription settings.';
			case 'play_store':
				return 'Bought through Google Play: cancel it in the Play Store\'s subscriptions.';
			default:
				return 'This subscription is not linked to a payment provider. Please contact support.';
		}
	}

	/**
	 * Tell each address on subscription_notification_emails that this
	 * subscription was cancelled. An address need not belong to a user here:
	 * the notice goes to the address either way. A notice that fails to send
	 * never undoes or hides the cancel, which the provider has already made.
	 */
	protected function notify_cancelled($provider_id, $order, $order_user) {
		$list = (string)Globalvars::get_instance()->get_setting('subscription_notification_emails');
		$body = 'Subscription '.$provider_id.' (Order '. $order->key .') was cancelled for user '.$order_user->display_name().' ('.$order_user->login_label().')';
		foreach (explode(',', $list) as $notify_email) {
			$notify_email = trim($notify_email);
			if ($notify_email === '') {
				continue;
			}
			$notify_user = User::GetByEmail($notify_email);
			try {
				$this->send_notice($notify_email, array(
					'subject' => 'Cancelled Subscription',
					'body' => $body,
					'recipient' => $notify_user ? $notify_user->export_as_array() : array('usr_email' => $notify_email),
				));
			} catch (Exception $e) {
				error_log('OrderItem ' . $this->key . ': the cancel notice to ' . $notify_email . ' was not sent: ' . $e->getMessage());
			}
		}
	}

	/** Sends one cancel notice. A seam, so a test can stand in for it. */
	protected function send_notice($to, array $values) {
		EmailSender::sendTemplate(Globalvars::get_instance()->get_setting('individual_email_inner_template'), $to, $values);
	}

	/** The Stripe client a cancel uses. A seam, so a test can stand in for it. */
	protected function stripe_helper() {
		return new StripeHelper();
	}

	/** The PayPal client a cancel uses. A seam, so a test can stand in for it. */
	protected function paypal_helper() {
		require_once(PathHelper::getIncludePath('plugins/store/includes/PaypalHelper.php'));
		return new PaypalHelper();
	}

	/**
	 * Cancel this subscription with the provider that bills it.
	 *
	 * Stripe cancels at the end of the paid period or at once, as asked.
	 * PayPal's cancel takes no timing and stops billing at once, so the row
	 * records it as cancelled now. An app-store subscription is refused with
	 * where the buyer cancels it (subscription_cancel_blocker()).
	 */
	function cancel_subscription_order_item($send_email, $cancel_type){
		$session = SessionControl::get_instance();

		$order = new Order($this->get('odi_ord_order_id'), TRUE);
		$order_user = new User($this->get('odi_usr_user_id'), TRUE);

		$this->assert_can_write($session);

		$provider_id = $this->cancel_with_provider($cancel_type);

		if($send_email){
			$this->notify_cancelled($provider_id, $order, $order_user);
		}
		return true;
		
	}
	
	/**
	 * Cancel this subscription on the plane's own decision, from a scheduled
	 * task: there is no signed-in user to check, and the buyer is told by the
	 * caller in its own words, so no store email goes. Used when a hosting
	 * subscription ends because its server moved to the customer's own cloud
	 * account. Throws SystemDisplayablePermanentError as the user path does.
	 */
	function cancel_subscription_by_system($cancel_type) {
		$this->cancel_with_provider($cancel_type);
		return true;
	}

	/**
	 * How much of $charge can still be refunded against this line: what is
	 * left on the charge, and no more than the line's price. A one-time line
	 * shares the order's charge with the other one-time lines, so its own
	 * earlier refunds count against it; a subscription line's charge is one
	 * invoice of its own.
	 */
	public function refundable_amount($charge): float {
		$left_on_charge = ((int)$charge->amount - (int)$charge->amount_refunded) / 100;
		$line_left = (float)$this->get('odi_price');
		if (!$this->get('odi_is_subscription')) {
			$line_left -= (float)$this->get('odi_refund_amount');
		}
		return round(max(0, min($left_on_charge, $line_left)), 2);
	}

	/**
	 * Refund part or all of what this line was paid, from the Stripe charge
	 * that paid it (StripeHelper::refundable_charge_for_item()), and record it
	 * on the line and the order. With $and_cancel, a subscription line is then
	 * cancelled now, through the same cancel as everywhere else, so whatever
	 * listens for a cancelled subscription (a hosted site's wind-down) hears it.
	 *
	 * @return array ['refunded' => float, 'what' => string, 'cancelled' => bool,
	 *         'cancel_error' => ?string, why a cancel asked for did not happen]
	 * @throws SystemDisplayableError when nothing can be refunded, the amount is
	 *         out of range, or Stripe refuses; nothing is recorded then.
	 */
	public function refund_payment($amount, string $note = '', bool $and_cancel = false): array {
		$stripe_helper = $this->stripe_helper();
		try {
			$found = $stripe_helper->refundable_charge_for_item($this);
		} catch (StripeHelperException $e) {
			throw new SystemDisplayableError('The payment cannot be read: ' . $e->getMessage());
		}
		if (empty($found['charge'])) {
			throw new SystemDisplayableError($found['reason']);
		}
		$amount = round((float)$amount, 2);
		$max = $this->refundable_amount($found['charge']);
		if ($amount <= 0 || $amount > $max) {
			throw new SystemDisplayableError('The refund must be more than 0 and at most ' . number_format($max, 2) . '.');
		}

		try {
			$stripe_helper->refund_charge($found['charge']->id, $amount);
		} catch (StripeHelperException $e) {
			throw new SystemDisplayableError('The refund was not made: ' . $e->getMessage());
		}

		$this->set('odi_refund_amount', round((float)$this->get('odi_refund_amount') + $amount, 2));
		$this->set('odi_refund_note', $note);
		$this->set('odi_refund_time', 'now()');
		$this->save();

		$order = $this->get_order();
		// Stripe's own figure for the order's charge, this refund included, so
		// an earlier dashboard refund stays counted.
		$order->refresh_refund_amount(!empty($found['is_order_charge'])
			? ((int)$found['charge']->amount_refunded / 100) + $amount : null);
		$order->set('ord_refund_time', 'now()');
		$order->save();

		// The refund stands whatever the cancel does; a refused cancel is
		// reported beside it rather than in place of it.
		$cancelled = false;
		$cancel_error = null;
		if ($and_cancel && $this->get('odi_is_subscription') && !$this->get('odi_subscription_cancelled_time')) {
			try {
				$this->cancel_subscription_order_item(true, 'immediate');
				$cancelled = true;
			} catch (SystemBaseException $e) {
				$cancel_error = $e->getMessage();
			}
		}
		return array('refunded' => $amount, 'what' => $found['what'], 'cancelled' => $cancelled, 'cancel_error' => $cancel_error);
	}

	/**
	 * The provider half of a cancel: refuse what this store cannot cancel,
	 * then cancel where the subscription is billed and record it. Returns the
	 * provider's subscription id.
	 */
	private function cancel_with_provider($cancel_type) {
		$blocker = $this->subscription_cancel_blocker();
		if ($blocker !== null) {
			throw new SystemDisplayablePermanentError($blocker);
		}

		$provider_id = '';
		if ($this->get_payment_source() === 'paypal') {
			$provider_id = (string)$this->get('odi_paypal_subscription_id');
			if (!$this->paypal_helper()->cancel_subscription($provider_id)) {
				throw new SystemDisplayablePermanentError("We were unable to cancel that subscription (" . $provider_id . ") Please contact the webmaster.");
			}
			$this->set('odi_subscription_cancelled_time', gmdate('Y-m-d H:i:s'));
			$this->set('odi_subscription_status', 'canceled');
			$this->save();
		}
		else {
			$provider_id = (string)$this->get('odi_stripe_subscription_id');
			$stripe_helper = $this->stripe_helper();
			if (!$stripe_helper->cancel_subscription($provider_id, $cancel_type)) {
				throw new SystemDisplayablePermanentError("We were unable to cancel that subscription (" . $provider_id . ") Please contact the webmaster.");
			}
			$stripe_helper->update_subscription_in_order_item($this);
		}
		return $provider_id;
	}

	/**
	 * What a subscription costs, as a person reads it: the site's currency and
	 * the plan's own period ('$29.99/year'). No period when the version is gone
	 * or is not a subscription, rather than a guessed '/month'.
	 */
	function subscription_price_label(): string {
		$symbol = CurrencyHelper::symbol(Globalvars::get_instance()->get_setting('site_currency')) ?? '$';
		$label = $symbol . number_format((float)$this->get('odi_price'), 2);
		$version_id = (int)$this->get('odi_prv_product_version_id');
		if ($version_id) {
			$version = new ProductVersion($version_id, TRUE);
			$period = $version->key ? $version->is_subscription() : false;
			if ($period) {
				$label .= '/' . $period;
			}
		}
		return $label;
	}

	function readable_subscription_status(){
		$settings = Globalvars::get_instance(); 
		$session = SessionControl::get_instance();

		if(!$this->get('odi_subscription_status')){
			throw new SystemDisplayablePermanentError("Subscripton ".$this->key." does not have a status.");
			exit;
		}

		if($this->get('odi_subscription_cancelled_time')){
			$status = 'Canceled on '. $this->get_local('odi_subscription_cancelled_time');
			return $status;
		}
		else{

			$status = 'active';
			if($this->get('odi_subscription_status')){
				$status = $this->get('odi_subscription_status');
			}
			$currency_symbol = CurrencyHelper::symbol($settings->get_setting('site_currency'));
			
			if($this->get('odi_prv_product_version_id')){
				$product_version = new ProductVersion($this->get('odi_prv_product_version_id'), TRUE);
				return $currency_symbol . $this->get('odi_price') . '/'. $product_version->is_subscription();
			}
			else{
				return false;
			}

		}
	}

	/**
	 * Check if subscription is still active, sync with Stripe if needed
	 * This implements lazy evaluation for subscription expiration checking
	 * @return bool True if subscription is active, false if expired/cancelled
	 */
	public function check_subscription_status() {
		// Only check subscriptions
		if (!$this->get('odi_is_subscription')) {
			return true; // Non-subscription items are always "active"
		}

		// Check if period has ended
		$period_end = strtotime($this->get('odi_subscription_period_end'));

		if ($period_end < time()) {
			// Period has passed - sync with the billing provider
			try {
				$source = $this->get_payment_source();
				if ($source === 'app_store') {
					require_once(PathHelper::getIncludePath('plugins/store/includes/AppStoreHelper.php'));
					AppStoreHelper::update_subscription_in_order_item($this);
				} elseif ($source === 'play_store') {
					require_once(PathHelper::getIncludePath('plugins/store/includes/GooglePlayHelper.php'));
					GooglePlayHelper::update_subscription_in_order_item($this);
				} else {
					require_once(PathHelper::getIncludePath('plugins/store/includes/StripeHelper.php'));
					$stripe_helper = new StripeHelper();

					// This existing method updates all subscription fields
					$stripe_helper->update_subscription_in_order_item($this);
				}

				// Check status after update
				$status = $this->get('odi_subscription_status');
				return in_array($status, ['active', 'trialing', 'grace_period']);

			} catch (Exception $e) {
				// If Stripe check fails, assume subscription is still valid
				// to avoid removing access due to API issues
				error_log('Failed to check subscription status for OrderItem ' . $this->key . ': ' . $e->getMessage());
				return true; // Fail open - assume valid
			}
		}

		// Period hasn't ended yet - check if cancelled
		if ($this->get('odi_subscription_cancelled_time')) {
			// Check if cancellation time has passed
			$cancelled_time = strtotime($this->get('odi_subscription_cancelled_time'));
			if ($cancelled_time < time()) {
				return false; // Cancelled subscription
			}
		}

		// Check current status
		$status = $this->get('odi_subscription_status');
		if ($status && !in_array($status, ['active', 'trialing', 'grace_period'])) {
			return false; // Not active status
		}

		return true; // Active subscription
	}
}

class MultiOrderItem extends SystemMultiBase {
	protected static $model_class = 'OrderItem';

	public function get_prices() {
		$price_array = array();
		foreach($this as $item) {
			$price_array[] = $item->get('odi_price');
		}
		return $price_array;
	}

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		// Order ID filtering
		// DEPRECATED: Use 'odi_ord_order_id' instead of 'order_id' (kept for backward compatibility only)
		if (isset($this->options['order_id'])) {
			$filters['odi_ord_order_id'] = [$this->options['order_id'], PDO::PARAM_INT];
		}
		if (isset($this->options['odi_ord_order_id'])) {
			$filters['odi_ord_order_id'] = [$this->options['odi_ord_order_id'], PDO::PARAM_INT];
		}

		// User ID filtering
		// DEPRECATED: Use 'odi_usr_user_id' instead of 'user_id' (kept for backward compatibility only)
		if (isset($this->options['user_id'])) {
			$filters['odi_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		}
		if (isset($this->options['odi_usr_user_id'])) {
			$filters['odi_usr_user_id'] = [$this->options['odi_usr_user_id'], PDO::PARAM_INT];
		}

		// Event Registrant ID filtering
		// DEPRECATED: Use 'odi_evr_event_registrant_id' instead of 'registrant_id' (kept for backward compatibility only)
		if (isset($this->options['registrant_id'])) {
			$filters['odi_evr_event_registrant_id'] = [$this->options['registrant_id'], PDO::PARAM_INT];
		}
		if (isset($this->options['odi_evr_event_registrant_id'])) {
			$filters['odi_evr_event_registrant_id'] = [$this->options['odi_evr_event_registrant_id'], PDO::PARAM_INT];
		}

		// Product ID filtering
		// DEPRECATED: Use 'odi_pro_product_id' instead of 'product_id' (kept for backward compatibility only)
		if (isset($this->options['product_id'])) {
			$filters['odi_pro_product_id'] = [$this->options['product_id'], PDO::PARAM_INT];
		}
		if (isset($this->options['odi_pro_product_id'])) {
			$filters['odi_pro_product_id'] = [$this->options['odi_pro_product_id'], PDO::PARAM_INT];
		}

		// Stripe Subscription ID filtering
		// DEPRECATED: Use 'odi_stripe_subscription_id' instead of 'stripe_subscription_id' (kept for backward compatibility only)
		if (isset($this->options['stripe_subscription_id'])) {
			$filters['odi_stripe_subscription_id'] = [$this->options['stripe_subscription_id'], PDO::PARAM_STR];
		}
		if (isset($this->options['odi_stripe_subscription_id'])) {
			$filters['odi_stripe_subscription_id'] = [$this->options['odi_stripe_subscription_id'], PDO::PARAM_STR];
		}

		// PayPal Subscription ID filtering
		if (isset($this->options['odi_paypal_subscription_id'])) {
			$filters['odi_paypal_subscription_id'] = [$this->options['odi_paypal_subscription_id'], PDO::PARAM_STR];
		}

		// App Store original transaction ID filtering
		if (isset($this->options['odi_app_store_original_transaction_id'])) {
			$filters['odi_app_store_original_transaction_id'] = [$this->options['odi_app_store_original_transaction_id'], PDO::PARAM_STR];
		}

		// Google Play purchase token filtering
		if (isset($this->options['odi_play_purchase_token'])) {
			$filters['odi_play_purchase_token'] = [$this->options['odi_play_purchase_token'], PDO::PARAM_STR];
		}

		// Payment source filtering (stripe | paypal | app_store | play_store)
		if (isset($this->options['odi_payment_source'])) {
			$filters['odi_payment_source'] = [$this->options['odi_payment_source'], PDO::PARAM_STR];
		}

		// Status filtering
		// DEPRECATED: Use 'odi_status' instead of 'status' (kept for backward compatibility only)
		if (isset($this->options['status'])) {
			$filters['odi_status'] = [$this->options['status'], PDO::PARAM_INT];
		}
		if (isset($this->options['odi_status'])) {
			$filters['odi_status'] = [$this->options['odi_status'], PDO::PARAM_INT];
		}

		if (isset($this->options['order_date_after'])) {
			$filters['odi_status_change_time'] = '> \''.$this->options['order_date_after'].'\'';
		}

		if (isset($this->options['is_subscription'])) {
			$filters['odi_is_subscription'] = "= TRUE";
		}

		if (isset($this->options['is_active_subscription'])) {
			$filters['odi_is_subscription'] = "= TRUE AND odi_subscription_cancelled_time IS NULL AND odi_status = " . OrderItem::STATUS_PAID;
		}

		// Currently-entitled subscriptions: never cancelled OR scheduled to
		// cancel at a future time (end-of-period cancellations stay current —
		// and reactivatable — until they lapse).
		if (isset($this->options['is_current_subscription'])) {
			$filters['odi_is_subscription'] = "= TRUE AND odi_status = " . OrderItem::STATUS_PAID
				. " AND (odi_subscription_cancelled_time IS NULL OR odi_subscription_cancelled_time > now())";
		}

		if (isset($this->options['is_cancelled_subscription'])) {
			$filters['odi_is_subscription'] = "= TRUE AND odi_subscription_cancelled_time IS NOT NULL AND odi_status = " . OrderItem::STATUS_PAID;
		}

		return $this->_get_resultsv2('odi_order_items', $filters, $this->order_by, $only_count, $debug);
	}

}

?>
