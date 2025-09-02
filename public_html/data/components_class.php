<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemBase.php');
PathHelper::requireOnce('includes/Validator.php');

class ComponentException extends SystemBaseException {}

class Component extends SystemBase {	public static $prefix = 'com';
	public static $tablename = 'com_components';
	public static $pkey_column = 'com_component_id';
	public static $permanent_delete_actions = array(	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

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
	    'com_component_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'com_title' => array('type'=>'varchar(255)'),
	    'com_order' => array('type'=>'int2'),
	    'com_published_time' => array('type'=>'timestamp(6)'),
	    'com_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'com_script_filename' => array('type'=>'varchar(255)'),
	    'com_delete_time' => array('type'=>'timestamp(6)'),
	);

	public static $field_constraints = array();	

}

class MultiComponent extends SystemMultiBase {
	protected static $model_class = 'Component';

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        return $this->_get_resultsv2('com_components', $filters, $this->order_by, $only_count, $debug);
    }
}

?>
