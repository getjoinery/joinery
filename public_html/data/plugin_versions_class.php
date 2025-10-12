<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class PluginVersionException extends SystemBaseException {}
class PluginVersionNotSentException extends PluginVersionException {}

class PluginVersion extends SystemBase {    public static $prefix = 'plv';
    public static $tablename = 'plv_plugin_versions';
    public static $pkey_column = 'plv_plugin_version_id';

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
        'plv_plugin_version_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
        'plv_plugin_name' => array('type'=>'varchar(255)', 'is_nullable'=>false, 'required'=>true, 'unique'=>true),
        'plv_installed_version' => array('type'=>'varchar(20)', 'is_nullable'=>false, 'required'=>true),
        'plv_available_version' => array('type'=>'varchar(20)'),
        'plv_last_check_time' => array('type'=>'timestamp(6)'),
        'plv_update_available' => array('type'=>'bool'),
        'plv_fingerprint' => array('type'=>'varchar(64)'),
        'plv_metadata' => array('type'=>'text'),
    );

    public static $field_constraints = array();

    function authenticate_write($data) {
        if ($data['current_user_permission'] < 10) {
            throw new SystemAuthenticationError(
                'Current user does not have permission to edit this entry in '. static::$tablename);
        }
    }
}

class MultiPluginVersion extends SystemMultiBase {
	protected static $model_class = 'PluginVersion';

    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        return $this->_get_resultsv2('plv_plugin_versions', $filters, $this->order_by, $only_count, $debug);
    }
}

?>