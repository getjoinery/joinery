<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SessionControl.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');

class ProductGroupException extends SystemClassException {}

class ProductGroup extends SystemBase {
	
	
	public static $prefix = 'prg';
	public static $tablename = 'prg_product_groups';
	public static $pkey_column = 'prg_product_group_id';
	public static $permanent_delete_actions = array(
		'prg_product_group_id' => 'delete',	
		'pro_prg_product_group_id' => 'prevent'
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'prg_product_group_id' => 'ID for this product group',
		'prg_max_items' => 'Max # of items allowed in the cart from this product group.',
		'prg_error' => 'Error message associated with too many items in the cart',
		'prg_name' => 'Name of the product group',
		'prg_description' => 'Description of the product group',
		'prg_type' => 'Type of group, like category or product plan group',
	);

	public static $field_specifications = array(
		'prg_product_group_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'prg_max_items' => array('type'=>'int4'),
		'prg_error' => array('type'=>'text'),
		'prg_name' => array('type'=>'varchar(100)'),
		'prg_description' => array('type'=>'text'),
		'prg_type' => array('type'=>'int4'),
	);

	public static $required_fields = array('prg_name');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array('prg_max_items' => 0);

}

class MultiProductGroup extends SystemMultiBase {

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $item) {
			$items[$item->get('prg_name')] = $item->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['type'])) {
			$filters['prg_type'] = [$this->options['type'], PDO::PARAM_INT];
		}

		return $this->_get_resultsv2('prg_product_groups', $filters, $this->order_by, $only_count, $debug);
	}

	// CHANGED: Updated load method
	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new ProductGroup($row->prg_product_group_id);
			$child->load_from_data($row, array_keys(ProductGroup::$fields));
			$this->add($child);
		}
	}

	// NEW: Added count_all method
	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}

}

?>
