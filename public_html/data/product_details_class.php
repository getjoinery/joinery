<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SystemClass.php');

class ProductDetailException extends SystemClassException {}
class ProductDetailNotSentException extends ProductDetailException {};

class ProductDetail extends SystemBase {	public static $prefix = 'prd';
	public static $tablename = 'prd_product_details';
	public static $pkey_column = 'prd_product_detail_id';
	public static $permanent_delete_actions = array(	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(		'prd_product_detail_id' => 'Primary key - ProductDetail ID',
		'prd_pro_product_id' => 'Product id',
		'prd_prv_product_version_id' => 'Product version',
		'prd_usr_user_id' => 'Person who purchased the item',
		'prd_num_sessions' => 'Number of sessions purchased',
		'prd_num_used' => 'Number of sessions used',
		'prd_notes' => 'notes',
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
		'prd_product_detail_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'prd_pro_product_id' => array('type'=>'int4'),
		'prd_prv_product_version_id' => array('type'=>'int4'),
		'prd_usr_user_id' => array('type'=>'int4'),
		'prd_num_sessions' => array('type'=>'int4'),
		'prd_num_used' => array('type'=>'int4'),
		'prd_notes' => array('type'=>'text'),
	);	
	public static $required_fields = array('prd_usr_user_id', 'prd_num_used');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array();

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
