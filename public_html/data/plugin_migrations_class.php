<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class PluginMigrationException extends SystemBaseException {}
class PluginMigrationNotSentException extends PluginMigrationException {}

class PluginMigration extends SystemBase {    public static $prefix = 'plm';
    public static $tablename = 'plm_plugin_migrations';
    public static $pkey_column = 'plm_plugin_migration_id';
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
        'plm_plugin_migration_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
        'plm_plugin_name' => array('type'=>'varchar(255)', 'is_nullable'=>false, 'required'=>true),
        'plm_migration_id' => array('type'=>'varchar(255)', 'is_nullable'=>false, 'required'=>true),
        'plm_version' => array('type'=>'varchar(20)', 'is_nullable'=>false, 'required'=>true),
        'plm_applied_time' => array('type'=>'timestamp(6)'),
        'plm_rollback_time' => array('type'=>'timestamp(6)'),
        'plm_status' => array('type'=>'varchar(20)'),
        'plm_up_sql' => array('type'=>'text'),
        'plm_down_sql' => array('type'=>'text'),
        'plm_error_message' => array('type'=>'text'),
    );

    public static $field_constraints = array(
        // Note: Unique constraints should be defined in field_specifications, not field_constraints
        // 'plm_plugin_name_migration_id_unique' => array(
        //     'type' => 'unique',
        //     'fields' => array('plm_plugin_name', 'plm_migration_id')
        // )
    );

    function authenticate_write($data) {
        if ($data['current_user_permission'] < 10) {
            throw new SystemAuthenticationError(
                'Current user does not have permission to edit this entry in '. static::$tablename);
        }
    }
}

class MultiPluginMigration extends SystemMultiBase {
	protected static $model_class = 'PluginMigration';

    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        return $this->_get_resultsv2('plm_plugin_migrations', $filters, $this->order_by, $only_count, $debug);
    }
}

?>