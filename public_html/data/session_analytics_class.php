<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');

class SessionAnalyticException extends SystemClassException {}

class SessionAnalytic extends SystemBase {	public static $prefix = 'sev';
	public static $tablename = 'sev_session_analytics';
	public static $pkey_column = 'sev_session_analytic_id';
	public static $permanent_delete_actions = array(	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	

	public static $fields = array(		
		'sev_session_analytic_id' => 'Primary key - SessionAnalytic ID',
		'sev_usr_user_id' => '',
		'sev_evt_event_id' => '',
		'sev_evs_event_session_id' => '',
		'sev_type' => '',
		'sev_time' => '',
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
		'sev_session_analytic_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'sev_usr_user_id' => array('type'=>'int4'),
		'sev_evt_event_id' => array('type'=>'int4'),
		'sev_evs_event_session_id' => array('type'=>'int4'),
		'sev_type' => array('type'=>'int2'),
		'sev_time' => array('type'=>'timestamp(6)'),
	);
			
	public static $required_fields = array();
	
	public static $field_constraints = array();
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array('sev_time' => 'now()');

}

class MultiSessionAnalytic extends SystemMultiBase {
	protected static $model_class = 'SessionAnalytic';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['session_id'])) {
			$filters['sev_evs_event_session_id'] = [$this->options['session_id'], PDO::PARAM_INT];
		}

		return $this->_get_resultsv2('sev_session_analytics', $filters, $this->order_by, $only_count, $debug);
	}

	// CHANGED: Updated load method

	// NEW: Added count_all method

}

?>
