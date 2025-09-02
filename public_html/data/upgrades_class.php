<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SystemBase.php');

class UpgradeException extends SystemBaseException {}
class UpgradeNotSentException extends UpgradeException {};

class Upgrade extends SystemBase {	public static $prefix = 'upg';
	public static $tablename = 'upg_upgrades';
	public static $pkey_column = 'upg_upgrade_id';
	public static $permanent_delete_actions = array(
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	

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
	    'upg_upgrade_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'upg_major_version' => array('type'=>'int4', 'required'=>true),
	    'upg_minor_version' => array('type'=>'int4', 'required'=>true),
	    'upg_name' => array('type'=>'varchar(64)', 'required'=>true),
	    'upg_release_notes' => array('type'=>'text'),
	    'upg_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	public static $field_constraints = array();	

	function authenticate_write($data) {
		if ($this->get(static::$prefix.'_usr_user_id') != $data['current_user_id']) {
			// If the user's ID doesn't match, we have to make
			// sure they have admin access, otherwise denied.
			if ($data['current_user_permission'] < 8) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this entry in '. static::$tablename);
			}
		}
	}

}

class MultiUpgrade extends SystemMultiBase {
	protected static $model_class = 'Upgrade';

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        // Note: 'user_id_recipient' filter removed - upg_usr_user_id_recipient field does not exist in model
        
        if (isset($this->options['major_version'])) {
            $filters['upg_major_version'] = [$this->options['major_version'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['minor_version'])) {
            $filters['upg_minor_version'] = [$this->options['minor_version'], PDO::PARAM_INT];
        }
        
        return $this->_get_resultsv2('upg_upgrades', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
