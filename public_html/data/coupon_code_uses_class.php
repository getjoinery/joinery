<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');


class CouponCodeUseException extends SystemClassException {}

class CouponCodeUse extends SystemBase {

	public static $prefix = 'ccu';
	public static $tablename = 'ccu_coupon_code_uses';
	public static $pkey_column = 'ccu_coupon_code_use_id';
	public static $permanent_delete_actions = array(
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'ccu_coupon_code_use_id' => 'Primary key - Coupon code use ID',
		'ccu_ccd_coupon_code_id' => 'The ID of the coupon code',
		'ccu_amount_discount' => 'Amount in currency of the coupon at time of use',
		'ccu_percent_discount' => 'Percent of coupon at time of use',
		'ccu_odi_order_item_id' => 'Order id of use',
		'ccu_create_time' => 'Time Created',
		'ccu_delete_time' => 'Time deleted'
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
		'ccu_coupon_code_use_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'ccu_ccd_coupon_code_id' => array('type'=>'int4'),
		'ccu_amount_discount' => array('type'=>'numeric(10,2)'),
		'ccu_percent_discount' => array('type'=>'int4'),
		'ccu_odi_order_item_id' => array('type'=>'int4'),
		'ccu_create_time' => array('type'=>'timestamp(6)'),
		'ccu_delete_time' => array('type'=>'timestamp(6)'),
	);
			
	public static $required_fields = array(
		'ccu_ccd_coupon_code_id',
		'ccu_odi_order_item_id'
		);

	public static $field_constraints = array(
		/*'ccu_code' => array(
			array('WordLength', 0, 64),
			'NoCaps',
			),*/
	);	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
		'ccu_create_time' => 'now()'
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

	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new CouponCodeUse($row->ccu_coupon_code_use_id);
			$child->load_from_data($row, array_keys(CouponCodeUse::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}

}


?>
