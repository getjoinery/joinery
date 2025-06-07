<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/DbConnector.php');
require_once($siteDir . '/includes/FieldConstraints.php');
require_once($siteDir . '/includes/Globalvars.php');
require_once($siteDir . '/includes/LibraryFunctions.php');
require_once($siteDir . '/includes/SingleRowAccessor.php');
require_once($siteDir . '/includes/SystemClass.php');
require_once($siteDir . '/includes/Validator.php');

class SessionAnalyticException extends SystemClassException {}

class SessionAnalytic extends SystemBase {
	public static $prefix = 'sev';
	public static $tablename = 'sev_session_analytics';
	public static $pkey_column = 'sev_session_analytic_id';
	public static $permanent_delete_actions = array(
		'sev_session_analytic_id' => 'delete',		
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	

	public static $fields = array(
		'sev_session_analytic_id' => 'ID of the session_analytic',
		'sev_usr_user_id' => '',
		'sev_evt_event_id' => '',
		'sev_evs_event_session_id' => '',
		'sev_type' => '',
		'sev_time' => '',
	);

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

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['session_id'])) {
			$filters['sev_evs_event_session_id'] = [$this->options['session_id'], PDO::PARAM_INT];
		}

		return $this->_get_resultsv2('sev_session_analytics', $filters, $this->order_by, $only_count, $debug);
	}

	// CHANGED: Updated load method
	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new SessionAnalytic($row->sev_session_analytic_id);
			$child->load_from_data($row, array_keys(SessionAnalytic::$fields));
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