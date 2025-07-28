<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');

PathHelper::requireOnce('data/coupon_code_uses_class.php');


class CouponCodeException extends SystemClassException {}

class CouponCode extends SystemBase {

	public static $prefix = 'ccd';
	public static $tablename = 'ccd_coupon_codes';
	public static $pkey_column = 'ccd_coupon_code_id';
	public static $permanent_delete_actions = array(		'ccp_ccd_coupon_code_id' => 'prevent',
		'ccu_cco_coupon_code_id' => 'null'
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'ccd_coupon_code_id' => 'Primary key - Coupon code ID',
		'ccd_code' => 'The code',
		'ccd_amount_discount' => 'Amount in currency of the coupon',
		'ccd_percent_discount' => 'Percent of coupon',
		'ccd_start_time' => 'Start time of coupon',
		'ccd_end_time' => 'End time of coupon',
		'ccd_is_active' => 'Is it active?',
		'ccd_create_time' => 'Time Created',
		'ccd_delete_time' => 'Time deleted',
		'ccd_max_num_uses' => 'Number of uses',
		'ccd_is_stackable' => 'Does it stack with other coupons?',
		'ccd_usr_user_id_affiliate' => 'User who gets credit for this code',
		'ccd_applies_to' => 'Category of product that this coupon applies to ("All products"=>0, "Subscriptions only"=>1, "One time purchases only"=>2, "Custom"=>3)',
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
		'ccd_coupon_code_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'ccd_code' => array('type'=>'varchar(64)'),
		'ccd_amount_discount' => array('type'=>'numeric(10,2)'),
		'ccd_percent_discount' => array('type'=>'int4'),
		'ccd_start_time' => array('type'=>'timestamp(6)'),
		'ccd_end_time' => array('type'=>'timestamp(6)'),
		'ccd_is_active' => array('type'=>'bool'),
		'ccd_create_time' => array('type'=>'timestamp(6)'),
		'ccd_delete_time' => array('type'=>'timestamp(6)'),
		'ccd_max_num_uses' => array('type'=>'int4'),
		'ccd_is_stackable' => array('type'=>'bool'),
		'ccd_usr_user_id_affiliate' => array('type'=>'int4'),
		'ccd_applies_to' => array('type'=>'int4'),
	);
			
	public static $required_fields = array();

	public static $field_constraints = array(
		'ccd_code' => array(
			array('WordLength', 0, 64),
			'NoCaps',
			),
	);	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
		'ccd_create_time' => 'now()'
	);	

	
	function get_discount($full_price){
		$discount = 0;
		if($this->get('ccd_amount_discount')){
			$discount = $this->get('ccd_amount_discount');
		}
		else if($this->get('ccd_percent_discount')){
			$discount = round(($this->get('ccd_percent_discount') / 100) * $full_price, 2);
		}
		else{
			$discount = 0;
		}

		//NO DISCOUNTS GREATER THAN 100%
		if($discount > $full_price){
			$discount = $full_price;
		}
		
		return $discount;
	}
	
	function get_readable_discount(){
		$settings = Globalvars::get_instance();
		$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')];
		$discount = 0;
		if($this->get('ccd_amount_discount')){
			$discount = $currency_symbol . $this->get('ccd_amount_discount');
		}
		else if($this->get('ccd_percent_discount')){
			$discount = $this->get('ccd_percent_discount') . '%';
		}
		else{
			$discount = 0;
		}
		
		return $discount;		
		
	}
	
	//THIS FUNCTION DETERMINES IF THE COUPON CODE IS VALID, BUT DOES NOT CHECK IF IT'S APPLIES TO A CERTAIN PRODUCT
	function is_valid(){
		//CHECK VALIDITY
		if(!$this->get('ccd_is_active')){
			return false;
		}
		
		$current_time = LibraryFunctions::get_current_time('UTC');
		if($this->get('ccd_start_time') && $this->get('ccd_start_time') < $current_time){
			return false;
		}
		
		if($this->get('ccd_end_time') && $this->get('ccd_end_time') < $current_time){
			return false;
		}

		//CHECK NUMBER OF USES 

		if($max_uses = $this->get('ccd_max_num_uses')){
			$searches = array('coupon_code_id' => $this->key);	
			$coupon_code_uses = new MultiCouponCodeUse($searches);
			$num_uses = $coupon_code_uses->count_all();

			if($num_uses > $max_uses){
				return false;
			}
		}
		
		return true;
	}

	
	function prepare() {
		if(CouponCode::GetByColumn('ccd_code', $this->get('ccd_code')) && !$this->key){
			throw new CouponCodeException('That coupon code already exists.');
		}		

		if($this->get('ccd_amount_discount') && $this->get('ccd_percent_discount')){
			throw new CouponCodeException('Coupon cannot have an amount and a percent.');
		}
		
	}	
	
	
	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}
	
}

class MultiCouponCode extends SystemMultiBase {


	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $coupon_code) {
			$items['('.$coupon_code->key.') '.$coupon_code->get('ccd_coupon_code')] = $coupon_code->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}
	


	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['user_id'])) {
            $filters['ccd_usr_user_id_affiliate'] = [$this->options['user_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['applies_to'])) {
            $filters['ccd_applies_to'] = [$this->options['applies_to'], PDO::PARAM_INT];
        }

		/*
        if (isset($this->options['link'])) {
            $filters['ccd_link'] = "'".$this->options['link']."'"; // Wrap in quotes for raw SQL
        }
		*/

        if (isset($this->options['active'])) {
            $filters['ccd_is_active'] = "= " . ($this->options['active'] ? 'TRUE' : 'FALSE'); // Ensure valid SQL
        }

        if (isset($this->options['deleted'])) {
            $filters['ccd_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }

        return $this->_get_resultsv2('ccd_coupon_codes', $filters, $this->order_by, $only_count, $debug);
    }
	

	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new CouponCode($row->ccd_coupon_code_id);
			$child->load_from_data($row, array_keys(CouponCode::$fields));
			$this->add($child);
		}
	}
	
	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}

}


?>
