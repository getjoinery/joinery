<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');

class EventLogException extends SystemClassException {}

class EventLog extends SystemBase {
	public static $prefix = 'evl';
	public static $tablename = 'evl_event_logs';
	public static $pkey_column = 'evl_event_log_id';
	public static $permanent_delete_actions = array(
		'evl_event_log_id' => 'delete',		
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	

	public static $fields = array(
		'evl_event_log_id' => 'ID of the event_log',
		'evl_event' => 'see above',
		'evl_usr_user_id' => 'User this event_log is associated with',
		'evl_create_time' => 'Time added',
		'evl_was_success' => 'Did it run to completion?',
		'evl_note' => 'Any notes'
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
		'evl_event_log_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'evl_event' => array('type'=>'varchar(255)'),
		'evl_usr_user_id' => array('type'=>'int4'),
		'evl_create_time' => array('type'=>'timestamp(6)'),
		'evl_was_success' => array('type'=>'bool'),
		'evl_note' => array('type'=>'varchar(255)'),
	);
			
	public static $required_fields = array();
	
	public static $field_constraints = array();
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
	'evl_create_time'=> 'now()',);
	

	
}

class MultiEventLog extends SystemMultiBase {

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

	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new EventLog($row->evl_event_log_id);
			$child->load_from_data($row, array_keys(EventLog::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}

}


?>
