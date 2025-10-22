<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class EventSessionFileException extends SystemBaseException {}

class EventSessionFile extends SystemBase {	public static $prefix = 'esf';
	public static $tablename = 'esf_event_session_files';
	public static $pkey_column = 'esf_event_session_file_id';

	protected static $foreign_key_actions = [
		'esf_fil_file_id' => ['action' => 'prevent', 'message' => 'Cannot delete file - event sessions exist']
	];

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
	    'esf_event_session_file_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'esf_evs_event_session_id' => array('type'=>'int4', 'required'=>true),
	    'esf_fil_file_id' => array('type'=>'int4', 'required'=>true),
	);

	public static $field_constraints = array();

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
