<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SessionControl.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemBase.php');
PathHelper::requireOnce('includes/Validator.php');

class ItemRelationTypeException extends SystemBaseException {}

class ItemRelationType extends SystemBase {
	public static $prefix = 'itt';
	public static $tablename = 'itt_item_relation_types';
	public static $pkey_column = 'itt_item_relation_type_id';
	public static $permanent_delete_actions = array(
		'itr_itt_item_relation_type_id' => 'delete',
	); //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

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
	    'itt_item_relation_type_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'itt_name' => array('type'=>'varchar(100)'),
	);

	public static $field_constraints = array();

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
