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

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['product_id'])) {
			$filters['prv_pro_product_id'] = [$this->options['product_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['is_active'])) {
			if ($this->options['is_active']) {
				$filters['prv_status'] = "> 0";
			}
		}

		if (isset($this->options['is_monthly_plan'])) {
			$filters['prv_plan_order_month'] = "> 0";
		}

		if (isset($this->options['is_yearly_plan'])) {
			$filters['prv_plan_order_year'] = "> 0";
		}

		return $this->_get_resultsv2('prv_product_versions', $filters, $this->order_by, $only_count, $debug);
	}

	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new ProductVersion($row->prv_product_version_id);
			$child->load_from_data($row, array_keys(ProductVersion::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}

}


?>