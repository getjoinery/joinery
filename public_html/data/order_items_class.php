<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SessionControl.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');
PathHelper::requireOnce('includes/EmailTemplate.php');
PathHelper::requireOnce('includes/StripeHelper.php');

PathHelper::requireOnce('data/address_class.php');
PathHelper::requireOnce('data/order_item_requirements_class.php');
PathHelper::requireOnce('data/products_class.php');

class OrderItemException extends SystemClassException {}

class OrderItem extends SystemBase {
	public static $prefix = 'odi';
	public static $tablename = 'odi_order_items';
	public static $pkey_column = 'odi_order_item_id';
	public static $permanent_delete_actions = array(		'evr_odi_order_item_id' => 'delete',
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	
	
	public const STATUS_UNPAID = 1;
	public const STATUS_PAID = 2;
	public const STATUS_ERROR = 3;

	public static $fields = array(		'odi_order_item_id' => 'Primary key - OrderItem ID',
		'odi_ord_order_id' => 'Order ID',
		'odi_pro_product_id' => 'Product ID',
		'odi_prv_product_version_id' => 'Product Version (if there is one)',
		'odi_product_info' => 'Serialized PHP array of the associated information with this product',
		'odi_price' => 'Price of the item when it was sold',
		'odi_status' => 'Current Status of this Order',
		'odi_status_change_time' => 'Timestamp of last status change',
		'odi_usr_user_id' => 'User who gets the product',
		'odi_evr_event_registrant_id' => 'If is event registration, registrant id',
		'odi_comment' => 'Optional comment',
		'odi_stripe_subscription_id' => 'Stripe subscription',
		'odi_is_subscription' => 'True if this order item is a subscription',
		'odi_subscription_cancelled_time' => 'If subscription, when it was cancelled',
		'odi_refund_amount' => 'Amount from this order item that has been refunded',
		'odi_refund_note' => 'Note for the refund',
		'odi_refund_time' => 'Time of last refund',
		'odi_stripe_foreign_invoice_id' => 'Stripe invoice id if it is the first of a subscription', 
		'odi_subscription_status' => 'Status if it is a subscription',
		'odi_subscription_period_end' => 'End date of the subscription period',
	);

	/**
	 * Field specifications define database column properties and schema constraints
	 * Available options:
	 *   'type' => 'varchar(255)' | 'int4' | 'int8' | 'text' | 'timestamp(6)' | 'numeric(10,2)' | 'bool' | etc.
	 *   'serial' => true/false - Auto-incrementing field
	 *   'is_nullable' => true/false - Whether NULL values are allowed
	 *   'unique' => true - Field must be unique (single field constraint)
	 *   'unique_with' => array('field1', 'field2') - Composite unique constraint with other fields
	 */
	public static $field_specifications = array(
		'odi_order_item_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'odi_ord_order_id' => array('type'=>'int4'),
		'odi_pro_product_id' => array('type'=>'int4'),
		'odi_prv_product_version_id' => array('type'=>'int4'),
		'odi_product_info' => array('type'=>'text'),
		'odi_price' => array('type'=>'numeric(10,2)'),
		'odi_status' => array('type'=>'int2'),
		'odi_status_change_time' => array('type'=>'timestamp(6)'),
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
	);

	public static $required_fields = array('odi_ord_order_id', 'odi_pro_product_id');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array(
		'odi_status_change_time' => 'now()'
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

	protected function _unsafe_edit() {
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if (!$this->key) {
			throw new OrderItemException('Cannot create an order item this way');
		}

		$p_keys = array('odi_order_item_id' => $this->key);


		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, 'odi_order_items', $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['odi_order_item_id'];
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
						$email = new EmailTemplate($email_inner_template, $notify_user);
						$email->fill_template(array(
							'subject' => 'Cancelled Subscription',
							'body' => $body,
						));	
						$result = $email->send();
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
}


class MultiOrderItem extends SystemMultiBase {

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
			$filters['odi_is_subscription'] = "= TRUE AND odi_subscription_cancelled_time IS NULL";
		}

		if (isset($this->options['is_cancelled_subscription'])) {
			$filters['odi_is_subscription'] = "= TRUE AND odi_subscription_cancelled_time IS NOT NULL";
		}

		return $this->_get_resultsv2('odi_order_items', $filters, $this->order_by, $only_count, $debug);
	}

	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new OrderItem($row->odi_order_item_id);
			$child->load_from_data($row, array_keys(OrderItem::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}

}

?>
