<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class EventLogException extends SystemBaseException {}

class EventLog extends SystemBase {	public static $prefix = 'evl';
	public static $tablename = 'evl_event_logs';
	public static $pkey_column = 'evl_event_log_id';
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
	    'evl_event_log_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'evl_event' => array('type'=>'varchar(255)'),
	    'evl_usr_user_id' => array('type'=>'int4'),
	    'evl_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'evl_was_success' => array('type'=>'bool'),
	    'evl_note' => array('type'=>'varchar(255)'),
	);

}

class MultiEventLog extends SystemMultiBase {
	protected static $model_class = 'EventLog';

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['user_id'])) {
            $filters['evl_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['event'])) {
            $filters['evl_event'] = [$this->options['event'], PDO::PARAM_STR];
        }

        return $this->_get_resultsv2('evl_event_logs', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
