<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemBase.php');
PathHelper::requireOnce('includes/Validator.php');

class ProductVersionException extends SystemBaseException {}

class ProductVersion extends SystemBase {	public static $prefix = 'esf';
	public static $tablename = 'prv_product_versions';
	public static $pkey_column = 'prv_product_version_id';
	
	public static $permanent_delete_actions = array(	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	

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
	    'prv_product_version_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'prv_pro_product_id' => array('type'=>'int4', 'required'=>true),
	    'prv_version_name' => array('type'=>'varchar(100)', 'required'=>true),
	    'prv_version_price' => array('type'=>'numeric(10,2)', 'required'=>true),
	    'prv_status' => array('type'=>'int2', 'required'=>true),
	    'prv_order' => array('type'=>'int4'),
	    'prv_price_type' => array('type'=>'varchar(10)'),
	    'prv_trial_period_days' => array('type'=>'int4'),
	    'prv_plan_order_month' => array('type'=>'int4'),
	    'prv_plan_order_year' => array('type'=>'int4'),
	);

	public static $field_constraints = array();

	public function is_subscription(){
		if($this->get('prv_price_type') == 'day' || $this->get('prv_price_type') == 'week' || $this->get('prv_price_type') == 'month' || $this->get('prv_price_type') == 'year'){
			return $this->get('prv_price_type');
		}
		return false;
	}	
	
}

class MultiProductVersion extends SystemMultiBase {
	protected static $model_class = 'ProductVersion';

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

}

?>
