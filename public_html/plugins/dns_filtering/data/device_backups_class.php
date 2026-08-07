<?php
// PathHelper is already loaded by the time this file is included

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class SdDeviceBackupException extends SystemBaseException {}

class SdDeviceBackup extends SystemBase {

	public static $prefix = 'sbk';
	public static $tablename = 'sbk_device_backups';
	public static $pkey_column = 'sbk_device_backup_id';

	protected static $foreign_key_actions = array(
		'sbk_usr_user_id' => array('action' => 'cascade'),
	);

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
	    'sbk_device_backup_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'sbk_device_backup_name' => array('type'=>'varchar(64)'),
	    'sbk_usr_user_id' => array('type'=>'int4'),
	    'sbk_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'sbk_delete_time' => array('type'=>'timestamp(6)'),
	    'sbk_deactivation_pin' => array('type'=>'varchar(10)'),
	);

	function get_readable_name(){
		return preg_replace('/^user\d+-/', '', $this->get('sbk_device_backup_name'));

	}

}

class MultiSdDeviceBackup extends SystemMultiBase {
	protected static $model_class = 'SdDeviceBackup';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $sddevice_backup) {
			$items[$sddevice_backup->key] = '('.$sddevice_backup->key.') '.$sddevice_backup->get('sbk_device_backup_name');
		}
		if ($include_new) {
			$items['Enter New Below'] = 'new';
		}
		return $items;

	}

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['user_id'])) {
            $filters['sbk_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['deleted'])) {
            $filters['sbk_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }

        return $this->_get_resultsv2('sbk_device_backups', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
