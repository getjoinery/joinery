<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class SessionAnalyticException extends SystemBaseException {}

class SessionAnalytic extends SystemBase {	public static $prefix = 'sev';
	public static $tablename = 'sev_session_analytics';
	public static $pkey_column = 'sev_session_analytic_id';
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
	    'sev_session_analytic_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'sev_usr_user_id' => array('type'=>'int4'),
	    'sev_evt_event_id' => array('type'=>'int4'),
	    'sev_evs_event_session_id' => array('type'=>'int4'),
	    'sev_type' => array('type'=>'int2'),
	    'sev_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	public static $field_constraints = array();

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
