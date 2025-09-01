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
	    'ccp_coupon_code_product_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'ccp_ccd_coupon_code_id' => array('type'=>'int4', 'required'=>true),
	    'ccp_pro_product_id' => array('type'=>'int4'),
	);

	public static $field_constraints = array();	

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
