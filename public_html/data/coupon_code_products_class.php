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
	public static $prefix = 'ccp';
	public static $tablename = 'ccp_coupon_code_products';
	public static $pkey_column = 'ccp_coupon_code_product_id';
	public static $permanent_delete_actions = array(
		'ccp_coupon_code_product_id' => 'prevent',
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

	public static $fields = array(
		'ccp_coupon_code_product_id' => 'ID of the coupon_code_product',
		'ccp_ccd_coupon_code_id' => 'Coupon id',
		'ccp_pro_product_id' => 'Product id',
	);

	public static $field_specifications = array(
		'ccp_coupon_code_product_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'ccp_ccd_coupon_code_id' =>  array('type'=>'int4'),
		'ccp_pro_product_id' =>  array('type'=>'int4'),
	);

	public static $required_fields = array('ccp_ccd_coupon_code_id'
		);

	public static $field_constraints = array();	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array();	

	
	
	function prepare() {

	}	
	
	
	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
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
			$sql = 'SELECT COUNT(1) as count_all FROM ccp_coupon_code_products ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM ccp_coupon_code_products
				' . $where_clause . '
				ORDER BY ';

			if (empty($this->order_by)) {
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
		parent::load();
		$q = $this->_get_results(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new CouponCodeProduct($row->ccp_coupon_code_product_id);
			$child->load_from_data($row, array_keys(CouponCodeProduct::$fields));
			$this->add($child);
		}
	}

}


?>
