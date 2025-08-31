<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php'); 

class DebugEmailLogException extends SystemClassException {}

class DebugEmailLog extends SystemBase {	public static $prefix = 'del';
	public static $tablename = 'del_debug_email_logs';
	public static $pkey_column = 'del_debug_email_log_id';
	public static $permanent_delete_actions = array(	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'del_debug_email_log_id' => 'Primary key - DebugEmailLog ID',
		'del_subject' => 'subject of the email',
		'del_recipient_email' => 'recipient email',
		'del_body' => 'Body of the email',
		'del_create_time' => 'Time added',
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
		'del_debug_email_log_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'del_subject' => array('type'=>'varchar(255)'),
		'del_recipient_email' => array('type'=>'varchar(255)'),
		'del_body' => array('type'=>'text'),
		'del_create_time' =>  array('type'=>'timestamp(6)'),
	);
			
	public static $required_fields = array();
	
	public static $field_constraints = array();
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
	'del_create_time'=> 'now()',);
	
}

class MultiDebugEmailLog extends SystemMultiBase {
	protected static $model_class = 'DebugEmailLog';

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        return $this->_get_resultsv2('del_debug_email_logs', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
