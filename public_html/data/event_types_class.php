<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/DbConnector.php');
require_once($siteDir . '/includes/FieldConstraints.php');
require_once($siteDir . '/includes/LibraryFunctions.php');
require_once($siteDir . '/includes/SessionControl.php');
require_once($siteDir . '/includes/SingleRowAccessor.php');
require_once($siteDir . '/includes/SystemClass.php');
require_once($siteDir . '/includes/Validator.php');

class EventTypeException extends SystemClassException {}

class EventType extends SystemBase {
	public static $prefix = 'ety';
	public static $tablename = 'ety_event_types';
	public static $pkey_column = 'ety_event_type_id';
	public static $permanent_delete_actions = array(
		'ety_event_type_id' => 'delete',	
		'evt_ety_event_type_id' => 'prevent',
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'ety_event_type_id' => 'ID for this event type',
		'ety_name' => 'Name of the event type'
	);

	public static $field_specifications = array(
		'ety_event_type_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'ety_name' =>  array('type'=>'varchar(100)'),
	);
	
	public static $required_fields = array();
	
	public static $field_constraints = array();
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array();
	
	

}

class MultiEventType extends SystemMultiBase {

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $item) {
			$items[$item->get('ety_name')] = $item->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        // No filters currently defined in original, keeping it simple
        
        return $this->_get_resultsv2('ety_event_types', $filters, $this->order_by, $only_count, $debug);
    }

	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new EventType($row->ety_event_type_id);
			$child->load_from_data($row, array_keys(EventType::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}
}

?>
