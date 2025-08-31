<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');

class EmailRecipientGroupException extends SystemClassException {}

class EmailRecipientGroup extends SystemBase {	public static $prefix = 'erg';
	public static $tablename = 'erg_email_recipient_groups';
	public static $pkey_column = 'erg_email_recipient_group_id';
	public static $permanent_delete_actions = array(	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

	public static $fields = array(
		'erg_email_recipient_group_id' => 'Primary key - EmailRecipientGroup ID',
		'erg_grp_group_id' => 'Group for recipients to be added',
		'erg_evt_event_id' => 'Event for recipients to be added',
		'erg_eml_email_id' => 'Email foreign key',
		'erg_operation' => 'Add or remove'
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
		'erg_email_recipient_group_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'erg_grp_group_id' => array('type'=>'int4'),
		'erg_evt_event_id' => array('type'=>'int4'),
		'erg_eml_email_id' => array('type'=>'int4'),
		'erg_operation' => array('type'=>'varchar(6)'),
	);

	public static $required_fields = array(
		'erg_eml_email_id');
		
	public static $zero_variables = array();

	public static $field_constraints = array();	
	
	public static $initial_default_values = array();

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
