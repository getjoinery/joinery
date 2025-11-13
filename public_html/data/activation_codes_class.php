<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class ActivationCodeException extends SystemBaseException {}

class ActivationCode extends SystemBase {	public static $prefix = 'act';
	public static $tablename = 'act_activation_codes';
	public static $pkey_column = 'act_activation_code_id';
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
	    'act_activation_code_id' => array('type'=>'int8', 'serial'=>true),
	    'act_usr_email' => array('type'=>'varchar(128)'),
	    'act_code' => array('type'=>'varchar(64)', 'required'=>true),
	    'act_expires_time' => array('type'=>'timestamp(6)'),
	    'act_usr_user_id' => array('type'=>'int4'),
	    'act_purpose' => array('type'=>'int2', 'default'=>0),
	    'act_created_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'act_phn_phone_number_id' => array('type'=>'int4'),
	    'act_deleted' => array('type'=>'bool', 'default'=>false),
	);

}

class MultiActivationCode extends SystemMultiBase {
	protected static $model_class = 'ActivationCode';

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['code'])) {
            $filters['act_code'] = [$this->options['code'], PDO::PARAM_STR];
        }

        return $this->_get_resultsv2('act_activation_codes', $filters, $this->order_by, $only_count, $debug);
    }

}

?>