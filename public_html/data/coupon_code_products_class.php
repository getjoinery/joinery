<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/DbConnector.php');
require_once($siteDir . '/includes/FieldConstraints.php');
require_once($siteDir . '/includes/Globalvars.php');
require_once($siteDir . '/includes/LibraryFunctions.php');
require_once($siteDir . '/includes/SingleRowAccessor.php');
require_once($siteDir . '/includes/SystemClass.php');
require_once($siteDir . '/includes/Validator.php');

require_once($siteDir . '/data/content_versions_class.php');
require_once($siteDir . '/data/groups_class.php');

class CouponCodeProductException extends SystemClassException {}

class CouponCodeProduct extends SystemBase {
	public $prefix = 'ccp';
	public $tablename = 'ccp_coupon_code_products';
	public $pkey_column = 'ccp_coupon_code_product_id';
	public static $permanent_delete_actions = array(
		'ccp_coupon_code_product_id' => 'prevent',
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

	public static $fields = array(
		'ccp_coupon_code_product_id' => 'ID of the coupon_code_product',
		'ccp_ccd_coupon_code_id' => 'Coupon id',
		'ccp_pro_product_id' => 'Product id',
	);

	public static $required_fields = array(
		);

	public static $field_constraints = array();	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array();	

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
	
	
	function prepare() {

	}	
	
	
	function authenticate_write($session, $other_data=NULL) {
		// If the user's ID doesn't match , we have to make
		// sure they have admin access, otherwise denied.
		if ($session->get_permission() < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this coupon_code_product.');
		}
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

	function _get_results($only_count=FALSE, $debug = false) { 
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

		if($debug){
			echo $sql. "<br>\n";
			print_r($this->options);
		}

		$total_params = count($bind_params);
		for ($i=0; $i<$total_params; $i++) {
			list($param, $type) = $bind_params[$i];
			$q->bindValue($i+1, $param, $type);
		}
		$q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);

		return $q;
	}

	function load($debug = false) {
		$q = $this->_get_results();
		foreach($q->fetchAll() as $row) {
			$child = new CouponCodeProduct($row->ccp_coupon_code_product_id);
			$child->load_from_data($row, array_keys(CouponCodeProduct::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->_get_results(TRUE);
		$counter = $q->fetch();
		return $counter->count;
	}
}


?>
