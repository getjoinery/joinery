<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FieldConstraints.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SingleRowAccessor.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Validator.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/data/address_class.php');

class OrderItemException extends SystemClassException {}

class OrderItem extends SystemBase {

	public const STATUS_UNPAID = 1;
	public const STATUS_PAID = 2;
	public const STATUS_ERROR = 3;

	public static $fields = array(
		'odi_order_item_id' => 'OrderItem ID',
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
		'odi_stripe_foreign_invoice_id' => 'Stripe invoice id if it is the first of a subscription'
	);

	public static $required_fields = array();

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array(
		'odi_status_change_time' => 'now()'
		);

	function load($for_update=FALSE) {
		parent::load($for_update);

		$this->data = SingleRowFetch('odi_order_items', 'odi_order_item_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS, $for_update);

		if ($this->data === NULL) {
			throw new OrderItemException('Invalid order_item ID');
		}
	}

	function get_order() {
		$order = new Order($this->get('odi_ord_order_id'), TRUE);
		return $order;
	}
	
	function authenticate_write($session) {
		if ($session->get_permission() < 5 && $session->get_user_id() != $this->get('odi_usr_user_id')) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to perform this action.');
		}
	}	

	function save() {
		parent::save();
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			//throw new OrderItemException('Cannot edit an existing order item.');
			$p_keys = array('odi_order_item_id' => $this->key);
		} else {
			$p_keys = NULL;
			// Creating a new order item
			unset($rowdata['odi_order_item_id']);
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, 'odi_order_items', $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['odi_order_item_id'];
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
			$clean_data[$key] = htmlspecialchars($value);
		}
		return $clean_data;
	}

	function get_raw_data() {
		return unserialize(base64_decode($this->get('odi_product_info')));
	}

	function get_product_version($product_id){
		require_once($_SERVER['DOCUMENT_ROOT'] . '/data/products_class.php');
		
		if($this->get('odi_prv_product_version_id')){
			return ProductVersion::GetActiveProductVersion($product_id, $this->get('odi_prv_product_version_id'));
		}
		else{
			return FALSE;
		}
		
	}

	
	function permanent_delete(){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'DELETE FROM odi_order_items WHERE odi_order_item_id=:odi_order_item_id';
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':odi_order_item_id', $this->key, PDO::PARAM_INT);
			$count = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}
		
		$this->key = NULL;
		
		return true;		
	}

	static function InitDB($mode='structure'){
	
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS odi_order_items_odi_order_item_id_seq
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
			CREATE TABLE IF NOT EXISTS "public"."odi_order_items" (
			  "odi_order_item_id" int4 NOT NULL DEFAULT nextval(\'odi_order_items_odi_order_item_id_seq\'::regclass),
			  "odi_ord_order_id" int4 NOT NULL,
			  "odi_pro_product_id" int4 NOT NULL,
			  "odi_product_info" text COLLATE "pg_catalog"."default",
			  "odi_price" numeric(10,2) NOT NULL,
			  "odi_prv_product_version_id" int4,
			  "odi_status" int2,
			  "odi_status_change_time" timestamp(6),
			  "odi_usr_user_id" int4,
			  "odi_evr_event_registrant_id" int4,
			  "odi_comment" varchar(255) COLLATE "pg_catalog"."default",
			  "odi_percent_tax_deductible" int4,
			  "odi_stripe_subscription_id" varchar(255) COLLATE "pg_catalog"."default",
			  "odi_is_subscription" bool,
			  "odi_subscription_cancelled_time" timestamp(6),
			  "odi_refund_amount" int4,
			  "odi_refund_note" varchar(255),
			  "odi_refund_time" timestamp(6),
			  "odi_stripe_foreign_invoice_id" varchar(64)
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."odi_order_items" ADD CONSTRAINT "odi_order_items_pkey" PRIMARY KEY ("odi_order_item_id");';
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


class MultiOrderItem extends SystemMultiBase {

	public function get_prices() {
		$price_array = array();
		foreach($this as $item) {
			$price_array[] = $item->get('odi_price');
		}
		return $price_array;
	}

	private function _get_results($only_count=FALSE) {
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('order_id', $this->options)) {
			$where_clauses[] = 'odi_ord_order_id = ?';
			$bind_params[] = array($this->options['order_id'], PDO::PARAM_INT);
		}

		if (array_key_exists('user_id', $this->options)) {
			$where_clauses[] = 'odi_usr_user_id = ?';
			$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		}
		
		if (array_key_exists('registrant_id', $this->options)) {
			$where_clauses[] = 'odi_evr_event_registrant_id = ?';
			$bind_params[] = array($this->options['registrant_id'], PDO::PARAM_INT);
		}		

		if (array_key_exists('product_id', $this->options)) {
			$where_clauses[] = 'odi_pro_product_id = ?';
			$bind_params[] = array($this->options['product_id'], PDO::PARAM_INT);
		}

		if (array_key_exists('stripe_subscription_id', $this->options)) {
			$where_clauses[] = 'odi_stripe_subscription_id = ?';
			$bind_params[] = array($this->options['stripe_subscription_id'], PDO::PARAM_INT);
		}

		if (array_key_exists('status', $this->options)) {
			$where_clauses[] = 'odi_status = ?';
			$bind_params[] = array($this->options['status'], PDO::PARAM_INT);
		}

		if (array_key_exists('order_date_after', $this->options)) {
			$where_clauses[] = 'odi_status_change_time > ?';
			$bind_params[] = array($this->options['order_date_after'], PDO::PARAM_STR);
		}

		if (array_key_exists('is_subscription', $this->options)) {
			$where_clauses[] = 'odi_is_subscription = TRUE';
		}

		if (array_key_exists('is_active_subscription', $this->options)) {
			$where_clauses[] = '(odi_is_subscription = TRUE AND odi_subscription_cancelled_time IS NULL)';
		}

		if (array_key_exists('is_cancelled_subscription', $this->options)) {
			$where_clauses[] = '(odi_is_subscription = TRUE AND odi_subscription_cancelled_time IS NOT NULL)';
		}

		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		if ($this->order_by) {
			if (array_key_exists('order_item_id', $this->order_by)) {
				$order_by_string = ' odi_order_item_id '. $this->order_by['order_item_id'];
			}	
				
		}
		else {
			$order_by_string = ' odi_order_item_id '. $this->order_by['order_item_id'];
		}
		
		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM odi_order_items
				' . $where_clause;
		} else {
			$sql = 'SELECT * FROM odi_order_items
				' . $where_clause . '
				ORDER BY ' . $order_by_string . ' ' .$this->generate_limit_and_offset();
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
		parent::load();
		$q = $this->_get_results();
		foreach($q->fetchAll() as $row) {
			$child = new OrderItem($row->odi_order_item_id);
			$child->load_from_data($row, array_keys(OrderItem::$fields));
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
