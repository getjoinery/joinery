<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemBase.php');
PathHelper::requireOnce('includes/Validator.php');

class CouponCodeUseException extends SystemBaseException {}

class CouponCodeUse extends SystemBase {	public static $prefix = 'ccu';
	public static $tablename = 'ccu_coupon_code_uses';
	public static $pkey_column = 'ccu_coupon_code_use_id';
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
	    'ccu_coupon_code_use_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'ccu_ccd_coupon_code_id' => array('type'=>'int4', 'required'=>true),
	    'ccu_amount_discount' => array('type'=>'numeric(10,2)'),
	    'ccu_percent_discount' => array('type'=>'int4'),
	    'ccu_odi_order_item_id' => array('type'=>'int4', 'required'=>true),
	    'ccu_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'ccu_delete_time' => array('type'=>'timestamp(6)'),
	);

	public static $field_constraints = array(
		/*'ccu_code' => array(
			array('WordLength', 0, 64),
			'NoCaps',
			),*/
	);	

	/*
	function prepare() {

	}	
	*/
	
	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}
	
}

class MultiCouponCodeUse extends SystemMultiBase {
	protected static $model_class = 'CouponCodeUse';

/*
	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $coupon_code_use) {
			$items['('.$coupon_code_use->key.') '.$coupon_code_use->get('ccu_coupon_code_use')] = $coupon_code_use->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}
	*/

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['coupon_code_use_id'])) {
            $filters['ccu_coupon_code_use_id'] = [$this->options['coupon_code_use_id'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['coupon_code_id'])) {
            $filters['ccu_ccd_coupon_code_id'] = [$this->options['coupon_code_id'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['order_id'])) {
            $filters['ccu_ord_order_id'] = [$this->options['order_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['deleted'])) {
            $filters['ccu_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }

        return $this->_get_resultsv2('ccu_coupon_code_uses', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
