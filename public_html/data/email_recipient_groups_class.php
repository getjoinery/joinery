<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemBase.php');
PathHelper::requireOnce('includes/Validator.php');

class EmailRecipientGroupException extends SystemBaseException {}

class EmailRecipientGroup extends SystemBase {	public static $prefix = 'erg';
	public static $tablename = 'erg_email_recipient_groups';
	public static $pkey_column = 'erg_email_recipient_group_id';
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
	    'erg_email_recipient_group_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'erg_grp_group_id' => array('type'=>'int4'),
	    'erg_evt_event_id' => array('type'=>'int4'),
	    'erg_eml_email_id' => array('type'=>'int4', 'required'=>true),
	    'erg_operation' => array('type'=>'varchar(6)'),
	);

	public static $field_constraints = array();	

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}
	
}

class MultiEmailRecipientGroup extends SystemMultiBase {
	protected static $model_class = 'EmailRecipientGroup';

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['group_id'])) {
            $filters['erg_grp_group_id'] = [$this->options['group_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['email_id'])) {
            $filters['erg_eml_email_id'] = [$this->options['email_id'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['event_id'])) {
            $filters['erg_evt_event_id'] = [$this->options['event_id'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['operation'])) {
            $filters['erg_operation'] = [$this->options['operation'], PDO::PARAM_STR];
        }

        if (isset($this->options['sent'])) {
            if ($this->options['sent']) {
                $filters['erg_sent_time'] = "IS NOT NULL";
            } else {
                $filters['erg_sent_time'] = "IS NULL";
            }
        }

        return $this->_get_resultsv2('erg_email_recipient_groups', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
