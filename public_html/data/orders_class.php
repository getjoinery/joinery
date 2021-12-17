<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FieldConstraints.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SingleRowAccessor.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Validator.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/data/order_items_class.php');

class OrderException extends SystemClassException {}

class Order extends SystemBase {
	
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
		'ord_refund_time' => '***DEPRECATED***Time of last refund', 
		'ord_refund_note' => '***DEPRECATED***Note for the refund',
		'ord_stripe_charge_id' => 'Charge ID from stripe',
		'ord_stripe_invoice_id' => 'Stripe invoice for subscriptions'
	);

	function load() {
		parent::load();

		$this->data = SingleRowFetch('ord_orders', 'ord_order_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);

		if ($this->data === NULL) {
			throw new OrderException('Invalid order ID');
		}
	}
	
	function is_stripe_order(){
		if($this->get('ord_stripe_session_id') || $this->get('ord_stripe_payment_intent_id') || $this->get('ord_stripe_charge_id') || $this->get('ord_stripe_invoice_id')){
			return true;
		}
		else{
			return false;
		}
	}

	function save() {
		// Saving requires some session control for authentication checking and whatnot
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('ord_order_id' => $this->key);
			//throw new OrderException('Order are immutable, cannot be edited.');
		} else {
			$p_keys = NULL;
			// Creating a new order
			$rowdata['ord_timestamp'] = 'now()';
			unset($rowdata['ord_order_id']);
		}
		
		
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, "ord_orders", $p_keys, $rowdata, FALSE, 0);
			

		$this->key = $p_keys_return['ord_order_id'];
		
		
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
	
	function permanent_delete() {
		
		$dbhelper = DbConnector::get_instance(); 
		$dblink = $dbhelper->get_db_link();
		
		$this_transaction = false;
		if(!$dblink->inTransaction()){
			$dblink->beginTransaction();
			$this_transaction = true;
		}


		$sql = 'DELETE FROM ord_orders WHERE ord_order_id=:ord_order_id';
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':ord_order_id', $this->key, PDO::PARAM_INT);
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}	
	
		$order_items = new MultiOrderItem(array('order_id' => $this->key));
		$order_items->load();
	
		foreach ($order_items as $order_item){
			$order_item->permanent_delete();
		}
		
		$sql = 'UPDATE evr_event_registrants SET evr_ord_order_id=NULL where evr_ord_order_id=:ord_order_id';
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':ord_order_id', $this->key, PDO::PARAM_INT);
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}		
			
		if($this_transaction){
			$dblink->commit();
		}
		
		$this->key = NULL;

		return TRUE;
		
	}	
	

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

	function authenticate_read($session) {
		if ($session->get_permission() < 5 && $session->get_user_id() != $this->get('ord_usr_user_id')) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to perform this action.');
		}
	}
	
	function authenticate_write($session) {
		if ($session->get_permission() < 5 && $session->get_user_id() != $this->get('ord_usr_user_id')) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to perform this action.');
		}
	}	

	function get_order_items() {
		$multi_order_item = new MultiOrderItem(array('order_id' => $this->key));
		$multi_order_item->load();
		return $multi_order_item;
	}
	
	static function InitDB($mode='structure'){
	
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS ord_orders_ord_order_id_seq
				INCREMENT BY 1
				NO MAXVALUE
				NO MINVALUE
				CACHE 1;';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}			
		
		$sql = '
			CREATE TABLE IF NOT EXISTS "public"."ord_orders" (
			  "ord_order_id" int4 NOT NULL DEFAULT nextval(\'ord_orders_ord_order_id_seq\'::regclass),
			  "ord_usr_user_id" int4,
			  "ord_total_cost" numeric(10,2),
			  "ord_timestamp" timestamp(6) DEFAULT now(),
			  "ord_raw_response" text COLLATE "pg_catalog"."default",
			  "ord_billing_address_id" int4,
			  "ord_stripe_session_id" varchar(64) COLLATE "pg_catalog"."default",
			  "ord_stripe_payment_intent_id" varchar(32) COLLATE "pg_catalog"."default",
			  "ord_raw_cart" text COLLATE "pg_catalog"."default",
			  "ord_serialized_cart" text COLLATE "pg_catalog"."default",
			  "ord_status" int4, 
			  "ord_error" varchar(255) COLLATE "pg_catalog"."default",
			  "ord_refund_amount" => int4,
			  "ord_refund_time" => timestamp(6),
			  "ord_refund_note" varchar(255),
			  "ord_stripe_charge_id" varchar(64),
			  "ord_stripe_invoice_id" varchar(64)
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."ord_orders" ADD CONSTRAINT "ord_orders_pkey" PRIMARY KEY ("ord_order_id");';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}

		//FOR FUTURE
		//ALTER TABLE table_name ADD COLUMN IF NOT EXISTS column_name INTEGER;
	}			
}


class MultiOrder extends SystemMultiBase {

	private function _get_results($only_count=FALSE) {
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('order_id', $this->options)) {
			$where_clauses[] = 'ord_order_id = ?';
			$bind_params[] = array($this->options['order_id'], PDO::PARAM_INT);
		}

		if (array_key_exists('user_id', $this->options)) {
			$where_clauses[] = 'ord_usr_user_id = ?';
			$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		}
		
		if (array_key_exists('event_id', $this->options)) {
			$where_clauses[] = 'ord_evt_event_id = ?';
			$bind_params[] = array($this->options['event_id'], PDO::PARAM_INT);
		}	

		if (array_key_exists('created_before', $this->options)) {
			$where_clauses[] = 'ord_timestamp <= ?';
			$bind_params[] = array($this->options['created_before'], PDO::PARAM_STR);
		}	

		if (array_key_exists('created_after', $this->options)) {
			$where_clauses[] = 'ord_timestamp >= ?';
			$bind_params[] = array($this->options['created_after'], PDO::PARAM_STR);
		}			

		if (array_key_exists('order_finished', $this->options)) {
			if($this->options['order_finished']) {
				$where_clauses[] = 'ord_order_id IN (SELECT odi_ord_order_id FROM odi_order_items WHERE odi_status = '.OrderItem::STATUS_DONE.')';
			} else {
				$where_clauses[] = 'ord_order_id IN (SELECT odi_ord_order_id FROM odi_order_items WHERE odi_status = '.OrderItem::STATUS_NEW.' OR odi_status = '.OrderItem::STATUS_PENDING.')';
			}
		}

		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM ord_orders
				' . $where_clause;
		} else {
			$sql = 'SELECT * FROM ord_orders
				' . $where_clause . ' ORDER BY ';

			if ($this->order_by === NULL) {
				$sql .= 'ord_order_id DESC';
			} else {
				$sort_clauses = array();
				if (array_key_exists('ord_order_id', $this->order_by)) {
					$sort_clauses[] = 'ord_order_id ' . $this->order_by['ord_order_id'];
				}
				$sql .= implode(',', $sort_clauses);
			}
			$sql .= $this->generate_limit_and_offset();
		}

		try {
			$q = $dblink->prepare($sql);

			$total_params = count($bind_params);
			for($i=0;$i<$total_params;$i++) {
				list($param, $type) = $bind_params[$i];
				$q->bindValue($i+1, $param, $type);
			}
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}

		return $q;
	}

	function load() {
		$q = $this->_get_results();
		foreach($q->fetchAll() as $row) {
			$child = new Order($row->ord_order_id);
			$child->load_from_data($row, array_keys(Order::$fields));
			$this->add($child);
		}
	}

	function count_all() {
		$q = $this->_get_results(TRUE);
		$counter = $q->fetch();
		return $counter->count_all;
	}
}

?>
