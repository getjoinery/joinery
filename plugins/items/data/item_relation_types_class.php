<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SessionControl.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');

class ItemRelationTypeException extends SystemClassException {}

class ItemRelationType extends SystemBase {
	public static $prefix = 'itt';
	public static $tablename = 'itt_item_relation_types';
	public static $pkey_column = 'itt_item_relation_type_id';
	public static $permanent_delete_actions = array(
		'itr_itt_item_relation_type_id' => 'delete',
	); //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'itt_item_relation_type_id' => 'ID for this relation type',
		'itt_name' => 'Name of the relation type'
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
		'itt_item_relation_type_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'itt_name' => array('type'=>'varchar(100)'),
	);

public static $required_fields = array();
	
	public static $field_constraints = array();
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array();

}

class MultiItemRelationType extends SystemMultiBase {
	protected static $model_class = 'ItemRelationType';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $item) {
			$items[$item->get('itt_name')] = $item->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];
		// This class doesn't have any filter options in the original, so filters array stays empty
		return $this->_get_resultsv2('itt_item_relation_types', $filters, $this->order_by, $only_count, $debug);
	}
}

?>
