<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');

class EventSessionFileException extends SystemClassException {}

class EventSessionFile extends SystemBase {	public static $prefix = 'esf';
	public static $tablename = 'esf_event_session_files';
	public static $pkey_column = 'esf_event_session_file_id';
	public static $permanent_delete_actions = array(	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	

	public static $fields = array(		'esf_evs_event_session_id' => 'see above',
		'esf_fil_file_id' => 'User this event_session_file is associated with',
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
		'esf_event_session_file_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'esf_evs_event_session_id' => array('type'=>'int4'),
		'esf_fil_file_id' => array('type'=>'int4'),
	);
			
	public static $required_fields = array('esf_evs_event_session_id', 'esf_fil_file_id');
	
	public static $field_constraints = array();
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array();

}

class MultiEventSessionFile extends SystemMultiBase {
	protected static $model_class = 'EventSessionFile';

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['file_id'])) {
            $filters['esf_fil_file_id'] = [$this->options['file_id'], PDO::PARAM_INT];
        }

        return $this->_get_resultsv2('esf_event_session_files', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
