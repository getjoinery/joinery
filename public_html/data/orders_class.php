<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/DbConnector.php');
require_once($siteDir . '/includes/FieldConstraints.php');
require_once($siteDir . '/includes/LibraryFunctions.php');
require_once($siteDir . '/includes/SessionControl.php');
require_once($siteDir . '/includes/SingleRowAccessor.php');
require_once($siteDir . '/includes/SystemClass.php');
require_once($siteDir . '/includes/Validator.php');

require_once($siteDir . '/data/order_items_class.php');

class OrderException extends SystemClassException {}

class Order extends SystemBase {
	public static $prefix = 'ord';
	public static $tablename = 'ord_orders';
	public static $pkey_column = 'ord_order_id';
	public static $permanent_delete_actions = array(
		'ord_order_id' => 'delete',	
		'odi_ord_order_id' => 'delete',
		'cls_ord_order_id' => 'delete',
		'evr_ord_order_id' => 'null',
		'ccu_ord_order_id' => 'null',
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	
	
	public const STATUS_UNPAID = 1;
	public const STATUS_PAID = 2;
	public const STATUS_ERROR = 3;


	public static $fields = array(
		'ord_order_id' => 'Order ID',
		'ord_usr_user_id' => 'User of the order',
		'ord_timestamp' => 'Time of order',
		'ord_total_cost' => 'Total cost of the order',
		'ord_billing_address_id' => 'ID of the billing address associated with this order (if there is one)',
		'ord_stripe_session_id' => 'Stripe session id for stripe checkout',
		'ord_stripe_payment_intent_id' => 'Payment intent id for stripe checkout',
		'ord_raw_response' => 'Raw response sent to stripe checkout webhook',
		'ord_raw_cart' => 'Raw cart output before processing',
		'ord_serialized_cart' => 'Saved cart for display later',
		'ord_status' => '1=unpaid, 2=paid, 3=error',
		'ord_error' => 'Error if the order does not go through.',
		'ord_refund_amount' => 'Amount refunded',
		'ord_refund_time' => 'Time of last refund', 
		'ord_refund_note' => 'Note for the refund',
		'ord_stripe_charge_id' => 'Charge ID from stripe',
		'ord_stripe_invoice_id' => 'Stripe invoice for subscriptions',
		'ord_test_mode' => 'This is a test order',
		'ord_stripe_subscription_id_temp' => 'Temporary storage for subscription ids coming from stripe checkout webhook'
	);

	public static $field_specifications = array(
		'ord_order_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'ord_usr_user_id' => array('type'=>'int4'),
		'ord_timestamp' => array('type'=>'timestamp(6)'),
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
		'ord_test_mode' => array('type'=>'bool'),
		'ord_stripe_subscription_id_temp' =>  array('type'=>'varchar(255)'),
	);

	public static $required_fields = array();

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array(
		'ord_timestamp' => 'now()',
		'ord_test_mode' => false
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
		$order->load_from_data($data, array_keys(Order::$fields));
		return $order;
	}
	
	public static function GetByStripePaymentIntent($payment_intent) {
		$data = SingleRowFetch('ord_orders', 'ord_stripe_payment_intent_id',
			$payment_intent, PDO::PARAM_STR, SINGLE_ROW_ALL_COLUMNS);

		if ($data === NULL) {
			return NULL;
		}

		$order = new Order($data->ord_order_id);
		$order->load_from_data($data, array_keys(Order::$fields));
		return $order;
	}	

	public static function GetByStripeCharge($charge) {
		$data = SingleRowFetch('ord_orders', 'ord_stripe_charge_id',
			$charge, PDO::PARAM_STR, SINGLE_ROW_ALL_COLUMNS);

		if ($data === NULL) {
			return NULL;
		}

		$order = new Order($data->ord_order_id);
		$order->load_from_data($data, array_keys(Order::$fields));
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
	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new Order($row->ord_order_id);
			$child->load_from_data($row, array_keys(Order::$fields));
			$this->add($child);
		}
	}

	// NEW: Added count_all method
	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}

}

?>
