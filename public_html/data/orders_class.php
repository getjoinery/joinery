<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
$settings = Globalvars::get_instance();
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

require_once(PathHelper::getIncludePath('data/order_items_class.php'));

class OrderException extends SystemBaseException {}

class Order extends SystemBase {	public static $prefix = 'ord';
	public static $tablename = 'ord_orders';
	public static $pkey_column = 'ord_order_id';

	protected static $foreign_key_actions = [
		'ord_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
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
	    'ord_order_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'ord_usr_user_id' => array('type'=>'int4'),
	    'ord_timestamp' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'ord_total_cost' => array('type'=>'numeric(10,2)'),
	    'ord_billing_address_id' => array('type'=>'int4'),
	    'ord_stripe_session_id' => array('type'=>'varchar(70)'),
	    'ord_stripe_payment_intent_id' => array('type'=>'varchar(32)'),
	    'ord_raw_response' => array('type'=>'text'),
	    'ord_raw_cart' => array('type'=>'text'),
	    'ord_serialized_cart' => array('type'=>'text'),
	    'ord_status' => array('type'=>'int4'),
	    'ord_error' => array('type'=>'varchar(255)'),
	    'ord_refund_amount' => array('type'=>'int4'),
	    'ord_refund_time' => array('type'=>'timestamp(6)'),
	    'ord_refund_note' => array('type'=>'varchar(255)'),
	    'ord_stripe_charge_id' => array('type'=>'varchar(64)'),
	    'ord_stripe_invoice_id' => array('type'=>'varchar(64)'),
	    'ord_test_mode' => array('type'=>'bool', 'default'=>false),
	    'ord_stripe_subscription_id_temp' => array('type'=>'varchar(255)'),
	    'ord_paypal_order_id' => array('type'=>'varchar(64)', 'is_nullable'=>true),
	    'ord_payment_method' => array('type'=>'varchar(32)', 'is_nullable'=>true),
	);

function is_stripe_order(){
		if($this->get('ord_stripe_session_id') || $this->get('ord_stripe_payment_intent_id') || $this->get('ord_stripe_charge_id') || $this->get('ord_stripe_invoice_id')){
			return true;
		}
		else{
			return false;
		}
	}

	/*
	function save_serialized_cart($cart){
		$cart_serialized = serialize($cart->get_items());

		$dbhelper = DbConnector::get_instance(); 
		$dblink = $dbhelper->get_db_link();
		$sql = 'UPDATE ord_orders SET ord_serialized_cart=:ord_serialized_cart WHERE ord_order_id='.$this->key;
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':ord_serialized_cart', $cart_serialized, PDO::PARAM_STR);
			$q->execute();
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}		
		
		return $cart_serialized;
	}
	*/

	public static function GetByStripeSession($session_id) {
		$data = SingleRowFetch('ord_orders', 'ord_stripe_session_id',
			$session_id, PDO::PARAM_STR, SINGLE_ROW_ALL_COLUMNS);

		if ($data === NULL) {
			return NULL;
		}

		$order = new Order($data->ord_order_id);
		$order->load_from_data($data, array_keys(Order::$field_specifications));
		return $order;
	}
	
	public static function GetByStripePaymentIntent($payment_intent) {
		$data = SingleRowFetch('ord_orders', 'ord_stripe_payment_intent_id',
			$payment_intent, PDO::PARAM_STR, SINGLE_ROW_ALL_COLUMNS);

		if ($data === NULL) {
			return NULL;
		}

		$order = new Order($data->ord_order_id);
		$order->load_from_data($data, array_keys(Order::$field_specifications));
		return $order;
	}	

	public static function GetByStripeCharge($charge) {
		$data = SingleRowFetch('ord_orders', 'ord_stripe_charge_id',
			$charge, PDO::PARAM_STR, SINGLE_ROW_ALL_COLUMNS);

		if ($data === NULL) {
			return NULL;
		}

		$order = new Order($data->ord_order_id);
		$order->load_from_data($data, array_keys(Order::$field_specifications));
		return $order;
	}	

	function authenticate_read($data) {
		if ($this->get(static::$prefix.'_usr_user_id') != $data['current_user_id']) {
			// If the user's ID doesn't match, we have to make
			// sure they have admin access, otherwise denied.
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to view this entry in '. $this->tablename);
			}
		}
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
	
	function permanent_delete($debug = false){
		//REMOVE ALL ORDER ITEMS FIRST
		$order_items = $this->get_order_items();
		foreach($order_items as $order_item) {
			$order_item->permanent_delete();
		}
		parent::permanent_delete($debug);
	}

	function get_order_items() {
		$multi_order_item = new MultiOrderItem(array('order_id' => $this->key));
		$multi_order_item->load();
		return $multi_order_item;
	}
	
}

class MultiOrder extends SystemMultiBase {
	protected static $model_class = 'Order';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['order_id'])) {
			$filters['ord_order_id'] = [$this->options['order_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['user_id'])) {
			$filters['ord_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['event_id'])) {
			$filters['ord_evt_event_id'] = [$this->options['event_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['created_before'])) {
			$filters['ord_timestamp'] = '<= \''.$this->options['created_before'].'\'';
		}

		if (isset($this->options['created_after'])) {
			$filters['ord_timestamp'] = '>= \''.$this->options['created_after'].'\'';
		}

		if (isset($this->options['test_mode'])) {
			if ($this->options['test_mode'] == true) {
				$filters['ord_test_mode'] = "IS TRUE";
			} else {
				$filters['ord_test_mode'] = "IS FALSE OR ord_test_mode IS NULL";
			}
		}

		if (isset($this->options['order_finished'])) {
			if ($this->options['order_finished']) {
				$filters['ord_order_id'] = 'IN (SELECT odi_ord_order_id FROM odi_order_items WHERE odi_status = '.OrderItem::STATUS_PAID.')';
			} else {
				$filters['ord_order_id'] = 'IN (SELECT odi_ord_order_id FROM odi_order_items WHERE odi_status = '.OrderItem::STATUS_UNPAID.' OR odi_status = '.OrderItem::STATUS_ERROR.')';
			}
		}

		return $this->_get_resultsv2('ord_orders', $filters, $this->order_by, $only_count, $debug);
	}

	// CHANGED: Updated load method

	// NEW: Added count_all method

}

?>
