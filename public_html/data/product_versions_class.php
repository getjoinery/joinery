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

class ProductVersionException extends SystemClassException {}

class ProductVersion extends SystemBase {
	public static $prefix = 'esf';
	public static $tablename = 'prv_product_versions';
	public static $pkey_column = 'prv_product_version_id';
	
	public static $permanent_delete_actions = array(
		'prv_product_version_id' => 'delete',		
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	

	public static $fields = array(
		'prv_product_version_id' => 'ID of the product_version',
		'prv_pro_product_id' => 'Product this version is attached to',
		'prv_version_name' => 'Name of the product version',
		'prv_version_price' => 'Price of this version',
		'prv_status' => 'Status, 0 or 1',
		'prv_order' => 'Order of display',
		'prv_price_type' => 'Type of price...values:  "single", "user", "day", "week", "month", "year"',
		'prv_trial_period_days' => 'Trial period for subscriptions',
		'prv_plan_order_month' => 'Order for this product version to appear on the monthly /pricing page',
		'prv_plan_order_year' => 'Order for this product version to appear on the yearly /pricing page',
	);

	public static $field_specifications = array(
		'prv_product_version_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'prv_pro_product_id' => array('type'=>'int4'),
		'prv_version_name' => array('type'=>'varchar(100)'),
		'prv_version_price' => array('type'=>'numeric(10,2)'),
		'prv_status' => array('type'=>'int2'),
		'prv_order' => array('type'=>'int4'),
		'prv_price_type' => array('type'=>'varchar(10)'),
		'prv_trial_period_days' => array('type'=>'int4'),
		'prv_plan_order_month' => array('type'=>'int4'),
		'prv_plan_order_year' => array('type'=>'int4'),
	);
	
	
	public static $required_fields = array('prv_pro_product_id', 'prv_version_name', 'prv_version_price', 'prv_status');
	
	public static $field_constraints = array();
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array();
	
	public function is_subscription(){
		if($this->get('prv_price_type') == 'day' || $this->get('prv_price_type') == 'week' || $this->get('prv_price_type') == 'month' || $this->get('prv_price_type') == 'year'){
			return $this->get('prv_price_type');
		}
		return false;
	}	
	
}

class MultiProductVersion extends SystemMultiBase {

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('product_id', $this->options)) {
		 	$where_clauses[] = 'prv_pro_product_id = ?';
		 	$bind_params[] = array($this->options['product_id'], PDO::PARAM_INT);
		} 
		
		if (array_key_exists('is_active', $this->options)) {
			if($this->options['is_active']){
				$where_clauses[] = 'prv_status > 0';
			}
		}	

		//THIS IS FOR PULLING STUFF FOR THE /PRICING PAGE
		if (array_key_exists('is_monthly_plan', $this->options)) {
			$where_clauses[] = 'prv_plan_order_month > 0';
		}			

		if (array_key_exists('is_yearly_plan', $this->options)) {
			$where_clauses[] = 'prv_plan_order_year > 0';
		}	
		
			
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM prv_product_versions ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM prv_product_versions
				' . $where_clause . '
				ORDER BY ';

			if (empty($this->order_by)) {
				$sql .= " prv_product_version_id ASC ";
			}
			else {

				if (array_key_exists('product_version_id', $this->order_by)) {
					$sql .= ' prv_product_version_id ' . $this->order_by['product_version_id'];
				}
				
				if (array_key_exists('plan_order_month', $this->order_by)) {
					$sql .= ' prv_plan_order_month '. $this->order_by['plan_order_month'];
				}	

				if (array_key_exists('plan_order_year', $this->order_by)) {
					$sql .= ' prv_plan_order_year '. $this->order_by['plan_order_year'];
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
			$child = new ProductVersion($row->prv_product_version_id);
			$child->load_from_data($row, array_keys(ProductVersion::$fields));
			$this->add($child);
		}
	}

}


?>