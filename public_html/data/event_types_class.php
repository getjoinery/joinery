<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class EventTypeException extends SystemBaseException {}

class EventType extends SystemBase {	public static $prefix = 'ety';
	public static $tablename = 'ety_event_types';
	public static $pkey_column = 'ety_event_type_id';

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
	    'ety_event_type_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'ety_name' => array('type'=>'varchar(100)'),
	);

}

class MultiEventType extends SystemMultiBase {
	protected static $model_class = 'EventType';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $item) {
			$items[$item->key] = $item->get('ety_name');
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
}

?>
