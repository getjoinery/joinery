<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FieldConstraints.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SingleRowAccessor.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Validator.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/data/content_versions_class.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/data/groups_class.php');

class CouponCodeProductException extends SystemClassException {}

class CouponCodeProduct extends SystemBase {

	public static $fields = array(
		'ccp_coupon_code_product_id' => 'ID of the coupon_code_product',
		'ccp_ccd_coupon_code_id' => 'Coupon id',
		'ccp_pro_product_id' => 'Product id',
	);

	public static $constants = array();

	public static $required = array(
		);

	public static $field_constraints = array();	
	
	public static $zero_variables = array();
	
	public static $default_values = array();	

	static function check_if_exists($key) {
		$data = SingleRowFetch('ccp_coupon_code_products', 'ccp_coupon_code_product_id',
			$key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($data === NULL) {
			return FALSE;
		}
		else{
			return TRUE;
		}
	}
	

	function load() {
		parent::load();
		$this->data = SingleRowFetch('ccp_coupon_code_products', 'ccp_coupon_code_product_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($this->data === NULL) {
			throw new CouponCodeProductException(
				'This coupon_code_product does not exist');
		}
	}
	
	function prepare() {
		if ($this->data === NULL) {
			throw new CouponCodeProductException('This has no data.');
		}


		if ($this->key === NULL) {
			foreach (static::$zero_variables as $variable) {
				if ($this->key === NULL && $this->get($variable) === NULL) {
					$this->set($variable, 0);
				}
			}

		}
		
		if ($this->key === NULL) {
			foreach (static::$default_values as $variable=>$value) {
				if ($this->key === NULL && $this->get($variable) === NULL) { 
					$this->set($variable, $value);
				}
			}
		}		

		CheckRequiredFields($this, self::$required, self::$fields);

		foreach (self::$field_constraints as $field => $constraints) {
			foreach($constraints as $constraint) {
				if (gettype($constraint) == 'array') {
					$params = array();
					$params[] = self::$fields[$field];
					$params[] = $this->get($field);
					for($i=1;$i<count($constraint);$i++) {
						$params[] = $constraint[$i];
					}
					call_user_func_array($constraint[0], $params);
				} else {
					call_user_func($constraint, self::$fields[$field], $this->get($field));
				}
			}
		}		

	}	
	
	
	function authenticate_write($session, $other_data=NULL) {
		// If the user's ID doesn't match , we have to make
		// sure they have admin access, otherwise denied.
		if ($session->get_permission() < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this coupon_code_product.');
		}
	}

	function save() {
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('ccp_coupon_code_product_id' => $this->key);
			// Editing an existing record
		} else {
			$p_keys = NULL;
			// Creating a new record
			unset($rowdata['ccp_coupon_code_product_id']);
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, 'ccp_coupon_code_products', $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['ccp_coupon_code_product_id'];
	}

	
	function permanent_delete(){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'DELETE FROM ccp_coupon_code_products WHERE ccp_coupon_code_product_id=:ccp_coupon_code_product_id';
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':ccp_coupon_code_product_id', $this->key, PDO::PARAM_INT);
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
				CREATE SEQUENCE IF NOT EXISTS ccp_coupon_code_products_ccp_coupon_code_product_id_seq
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
			CREATE TABLE IF NOT EXISTS "public"."ccp_coupon_code_products" (
			  "ccp_coupon_code_product_id" int4 NOT NULL DEFAULT nextval(\'ccp_coupon_code_products_ccp_coupon_code_product_id_seq\'::regclass),
			  "ccp_ccd_coupon_code_id" int4 NOT NULL,
			  "ccp_pro_product_id" varchar(255) COLLATE "pg_catalog"."default"
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."ccp_coupon_code_products" ADD CONSTRAINT "ccp_coupon_code_products_pkey" PRIMARY KEY ("ccp_coupon_code_product_id");';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}

		/*
		try{		
			$sql = 'CREATE INDEX CONCURRENTLY ccp_coupon_code_products_ccp_link ON ccp_coupon_code_products USING HASH (ccp_link);';
			$q = $dburl->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}
		*/
		//FOR FUTURE
		//ALTER TABLE table_name ADD COLUMN IF NOT EXISTS column_name INTEGER;
	}		
	
}

class MultiCouponCodeProduct extends SystemMultiBase {


	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $coupon_code_product) {
			$items[$coupon_code_product->get('ccp_coupon_code_product_label')] = $coupon_code_product->get('ccp_coupon_code_product_value');
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	private function _get_results($only_count=FALSE) { 
		$where_clauses = array();
		$bind_params = array();
		
		if (array_key_exists('coupon_code_id', $this->options)) {
			$where_clauses[] = 'ccp_ccd_coupon_code_id = ?';
			$bind_params[] = array($this->options['coupon_code_id'], PDO::PARAM_STR);
		}			
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM ccp_coupon_code_products ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM ccp_coupon_code_products
				' . $where_clause . '
				ORDER BY ';

			if (!$this->order_by) {
				$sql .= " ccp_coupon_code_product_id ASC ";
			}
			else {
				if (array_key_exists('coupon_code_product_id', $this->order_by)) {
					$sql .= ' ccp_coupon_code_product_id ' . $this->order_by['coupon_code_product_id'];
				}			
			}
			
			$sql .= ' '.$this->generate_limit_and_offset();	
		}

		$q = DbConnector::GetPreparedStatement($sql);

		$total_params = count($bind_params);
		for ($i=0; $i<$total_params; $i++) {
			list($param, $type) = $bind_params[$i];
			$q->bindValue($i+1, $param, $type);
		}
		$q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);

		return $q;
	}

	function load() {
		$q = $this->_get_results();
		foreach($q->fetchAll() as $row) {
			$child = new CouponCodeProduct($row->ccp_coupon_code_product_id);
			$child->load_from_data($row, array_keys(CouponCodeProduct::$fields));
			$this->add($child);
		}
	}

	function count_all() {
		$q = $this->_get_results(TRUE);
		$counter = $q->fetch();
		return $counter->count;
	}
}


?>
