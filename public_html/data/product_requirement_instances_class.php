<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

require_once(PathHelper::getIncludePath('data/products_class.php'));
require_once(PathHelper::getIncludePath('data/product_requirements_class.php'));

class ProductRequirementInstanceException extends SystemBaseException {}
class DisplayableProductRequirementInstanceException extends ProductRequirementInstanceException implements DisplayableErrorMessage {}
class DisplayablePermanentProductRequirementInstanceException extends ProductRequirementInstanceException implements DisplayablePermanentErrorMessage {}

class ProductRequirementInstance extends SystemBase {	public static $prefix = 'pri';
	public static $tablename = 'pri_product_requirement_instances';
	public static $pkey_column = 'pri_product_requirement_instance_id';
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
	    'pri_product_requirement_instance_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'pri_pro_product_id' => array('type'=>'int4', 'required'=>true),
	    'pri_prq_product_requirement_id' => array('type'=>'int4', 'required'=>true),
	    'pri_delete_time' => array('type'=>'timestamp(6)'),
	); 

function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}	

}

class MultiProductRequirementInstance extends SystemMultiBase {
	protected static $model_class = 'ProductRequirementInstance';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $item) {
			$option_display = $item->get('prq_title'); 
			$items[$option_display] = $item->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['product_id'])) {
			$filters['pri_pro_product_id'] = [$this->options['product_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['product_requirement_id'])) {
			$filters['pri_prq_product_requirement_id'] = [$this->options['product_requirement_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['deleted'])) {
			$filters['pri_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('pri_product_requirement_instances', $filters, $this->order_by, $only_count, $debug);
	}

}

?>
