<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');

PathHelper::requireOnce('data/content_versions_class.php');
PathHelper::requireOnce('data/groups_class.php');

class CouponCodeProductException extends SystemClassException {}

class CouponCodeProduct extends SystemBase {	public static $prefix = 'ccp';
	public static $tablename = 'ccp_coupon_code_products';
	public static $pkey_column = 'ccp_coupon_code_product_id';
	public static $permanent_delete_actions = array(
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

	public static $fields = array(
		'ccp_coupon_code_product_id' => 'Primary key - CouponCodeProduct ID',
		'ccp_ccd_coupon_code_id' => 'Coupon id',
		'ccp_pro_product_id' => 'Product id',
	);

	/**
	 * Field specifications define database column properties and schema constraints
	 * Available options:
	 *   'type' => 'varchar(255)' | 'int4' | 'int8' | 'text' | 'timestamp(6)' | 'numeric(10,2)' | 'bool' | etc.
	 *   'serial' => true/false - Auto-incrementing field
	 *   'is_nullable' => true/false - Whether NULL values are allowed
	 *   'unique' => true - Field must be unique (single field constraint)
	 *   'unique_with' => array('field1', 'field2') - Composite unique constraint with other fields
	 */
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
	protected static $model_class = 'CouponCodeProduct';

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

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        if (isset($this->options['coupon_code_id'])) {
            $filters['ccp_ccd_coupon_code_id'] = [$this->options['coupon_code_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['product_id'])) {
            $filters['ccp_pro_product_id'] = [$this->options['product_id'], PDO::PARAM_INT];
        }

        return $this->_get_resultsv2('ccp_coupon_code_products', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
