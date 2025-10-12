<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class ProductDetailException extends SystemBaseException {}
class ProductDetailNotSentException extends ProductDetailException {};

class ProductDetail extends SystemBase {	public static $prefix = 'prd';
	public static $tablename = 'prd_product_details';
	public static $pkey_column = 'prd_product_detail_id';

	protected static $foreign_key_actions = [
		'prd_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
	];

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
	    'prd_product_detail_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'prd_pro_product_id' => array('type'=>'int4'),
	    'prd_prv_product_version_id' => array('type'=>'int4'),
	    'prd_usr_user_id' => array('type'=>'int4', 'required'=>true),
	    'prd_num_sessions' => array('type'=>'int4'),
	    'prd_num_used' => array('type'=>'int4', 'required'=>true),
	    'prd_notes' => array('type'=>'text'),
	);	

	public static $field_constraints = array();	

}

class MultiProductDetail extends SystemMultiBase {
	protected static $model_class = 'ProductDetail';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['product_id'])) {
			$filters['prd_pro_product_id'] = [$this->options['product_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['product_version_id'])) {
			$filters['prd_prv_product_version_id'] = [$this->options['product_version_id'], PDO::PARAM_INT];
		}

		return $this->_get_resultsv2('prd_product_details', $filters, $this->order_by, $only_count, $debug);
	}

	// CHANGED: Updated load method

	// NEW: Added count_all method

}

?>
