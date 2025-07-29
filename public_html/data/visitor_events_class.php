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

class VisitorEvent extends SystemBase {
	public static $prefix = 'vse';
	public static $tablename = 'vse_visitor_events';
	public static $pkey_column = 'vse_visitor_event_id';
	public static $permanent_delete_actions = array(	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	

	public static $fields = array(		'vse_visitor_event_id' => 'Primary key - VisitorEvent ID',
		'vse_visitor_id' => 'Visitor id',
		'vse_usr_user_id' => 'The user id',
		'vse_type' => 'Type of record',
		'vse_ip' => 'User ip',
		'vse_page' => 'The page',
		'vse_referrer' => 'Referring site',
		'vse_source' => 'For tracking',
		'vse_campaign' => 'For tracking',
		'vse_timestamp' => 'Timestamp',
		'vse_medium' => 'For tracking',
		'vse_content' => 'For tracking',
		'vse_is_404' => 'Is this a 404?',
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
		'vse_visitor_event_id' => array('type'=>'int8', 'serial'=>true),
		'vse_visitor_id' => array('type'=>'varchar(20)'),
		'vse_usr_user_id' => array('type'=>'int4'),
		'vse_type' => array('type'=>'int2'),
		'vse_ip' => array('type'=>'varchar(64)'),
		'vse_page' => array('type'=>'varchar(255)'),
		'vse_referrer' => array('type'=>'varchar(255)'),
		'vse_source' => array('type'=>'varchar(255)'),
		'vse_campaign' => array('type'=>'varchar(255)'),
		'vse_timestamp' => array('type'=>'timestamp(6)'),
		'vse_medium' => array('type'=>'varchar(255)'),
		'vse_content' => array('type'=>'varchar(255)'),
		'vse_is_404' => array('type'=>'bool'),
	);

	public static $required_fields = array();
	
	public static $field_constraints = array();
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array('vse_timestamp' => 'now()');
	

	
}

class MultiVisitorEvent extends SystemMultiBase {

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        // Note: 'code' filter removed - vse_code field does not exist in model
        
        return $this->_get_resultsv2('vse_visitor_events', $filters, $this->order_by, $only_count, $debug);
    }
    
    function load($debug = false) {
        parent::load();
        $q = $this->getMultiResults(false, $debug);
        foreach($q->fetchAll() as $row) {
            $child = new VisitorEvent($row->vse_visitor_event_id);
            $child->load_from_data($row, array_keys(VisitorEvent::$fields));
            $this->add($child);
        }
    }
    
    function count_all($debug = false) {
        $q = $this->getMultiResults(TRUE, $debug);
        return $q;
    }

}


?>
