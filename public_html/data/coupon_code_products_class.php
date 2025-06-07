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
		//'ccp_coupon_code_product_id' => 'prevent',
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

	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new CouponCodeProduct($row->ccp_coupon_code_product_id);
			$child->load_from_data($row, array_keys(CouponCodeProduct::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}

}


?>
