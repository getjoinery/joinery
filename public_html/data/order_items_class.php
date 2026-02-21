<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));
require_once(PathHelper::getIncludePath('includes/EmailTemplate.php'));
require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
require_once(PathHelper::getIncludePath('includes/StripeHelper.php'));

require_once(PathHelper::getIncludePath('data/address_class.php'));
require_once(PathHelper::getIncludePath('data/order_item_requirements_class.php'));
require_once(PathHelper::getIncludePath('data/products_class.php'));

class OrderItemException extends SystemBaseException {}

class OrderItem extends SystemBase {	public static $prefix = 'odi';
	public static $tablename = 'odi_order_items';
	public static $pkey_column = 'odi_order_item_id';

	protected static $foreign_key_actions = [
		'odi_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED],
		'odi_pro_product_id' => ['action' => 'prevent', 'message' => 'Cannot delete product - order items exist'],
		'odi_evr_event_registrant_id' => ['action' => 'prevent', 'message' => 'Cannot delete event registration - order items exist']
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
	);

function get_order() {
		$order = new Order($this->get('odi_ord_order_id'), TRUE);
		return $order;
	}
	
	function authenticate_write($data) {
		if ($this->get(static::$prefix.'_usr_user_id') != $data['current_user_id']) {
			// If the user's ID doesn't match, we have to make
			// sure they have admin access, otherwise denied.
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this entry in '. static::$tablename);
			}
		}
	}

	function get_data() {
		$data = unserialize(base64_decode($this->get('odi_product_info')));
		$clean_data = array();
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
	
	function cancel_subscription_order_item($send_email, $cancel_type){
		$session = SessionControl::get_instance();
		$settings = Globalvars::get_instance();
		$stripe_helper = new StripeHelper();
		
		$order = new Order($this->get('odi_ord_order_id'), TRUE);
		$order_user = new User($this->get('odi_usr_user_id'), TRUE);
		
		$this->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));

		$stripe_subscription = $stripe_helper->cancel_subscription($this->get('odi_stripe_subscription_id'), $cancel_type);
		if(!$stripe_subscription){
			throw new SystemDisplayablePermanentError("We were unable to cancel that subscription (".$this->get('odi_stripe_subscription_id').") Please contact the webmaster.");
			exit;		
		}		
		$result = $stripe_helper->update_subscription_in_order_item($this);

		//SEND NOTIFICATION
		if($send_email){
			if($settings->get_setting('subscription_notification_emails')){
				$notify_emails = explode(',', $settings->get_setting('subscription_notification_emails'));
				foreach($notify_emails as $notify_email){
					try {
						$notify_user = User::GetByEmail($notify_email);
						$body = 'Subscription '.$this->get('odi_stripe_subscription_id').' (Order '. $order->key .') was cancelled for user '.$order_user->display_name().' ('.$order_user->get('usr_email').')';
						$email_inner_template = $settings->get_setting('individual_email_inner_template');
						EmailSender::sendTemplate($email_inner_template,
							$notify_user->get('usr_email'),
							[
								'subject' => 'Cancelled Subscription',
								'body' => $body,
								'recipient' => $notify_user->export_as_array()
							]
						);
					}					
					catch (Exception $e) {
						//DO NOTHING
						$error2 = "";
					}
				}
			}	
		}
		return true;
		
	}
	
	function readable_subscription_status(){
		$settings = Globalvars::get_instance(); 
		$session = SessionControl::get_instance();

		if(!$this->get('odi_subscription_status')){
			throw new SystemDisplayablePermanentError("Subscripton ".$this->key." does not have a status.");
			exit;
		}

		if($this->get('odi_subscription_cancelled_time')){
			$status = 'Canceled on '. LibraryFunctions::convert_time($this->get('odi_subscription_cancelled_time'), 'UTC', $session->get_timezone());
			return $status;
		}
		else{

			$status = 'active';
			if($this->get('odi_subscription_status')){
				$status = $this->get('odi_subscription_status');
			}
			$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')];
			
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
			// Period has passed - sync with Stripe
			try {
				require_once(PathHelper::getIncludePath('includes/StripeHelper.php'));
				$stripe_helper = new StripeHelper();

				// This existing method updates all subscription fields
				$stripe_helper->update_subscription_in_order_item($this);

				// Check status after update
				$status = $this->get('odi_subscription_status');
				return in_array($status, ['active', 'trialing']);

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
		if ($status && !in_array($status, ['active', 'trialing'])) {
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

		if (isset($this->options['is_cancelled_subscription'])) {
			$filters['odi_is_subscription'] = "= TRUE AND odi_subscription_cancelled_time IS NOT NULL AND odi_status = " . OrderItem::STATUS_PAID;
		}

		return $this->_get_resultsv2('odi_order_items', $filters, $this->order_by, $only_count, $debug);
	}

}

?>
