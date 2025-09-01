<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');

class VisitorEventException extends SystemClassException {}

class VisitorEvent extends SystemBase {	public static $prefix = 'vse';
	public static $tablename = 'vse_visitor_events';
	public static $pkey_column = 'vse_visitor_event_id';
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
	    'vse_visitor_event_id' => array('type'=>'int8', 'serial'=>true),
	    'vse_visitor_id' => array('type'=>'varchar(20)'),
	    'vse_usr_user_id' => array('type'=>'int4'),
	    'vse_type' => array('type'=>'int2'),
	    'vse_ip' => array('type'=>'varchar(64)'),
	    'vse_page' => array('type'=>'varchar(255)'),
	    'vse_referrer' => array('type'=>'varchar(255)'),
	    'vse_source' => array('type'=>'varchar(255)'),
	    'vse_campaign' => array('type'=>'varchar(255)'),
	    'vse_timestamp' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'vse_medium' => array('type'=>'varchar(255)'),
	    'vse_content' => array('type'=>'varchar(255)'),
	    'vse_is_404' => array('type'=>'bool'),
	);

	public static $field_constraints = array();

}

class MultiVisitorEvent extends SystemMultiBase {
	protected static $model_class = 'VisitorEvent';

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        // Note: 'code' filter removed - vse_code field does not exist in model
        
        return $this->_get_resultsv2('vse_visitor_events', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
