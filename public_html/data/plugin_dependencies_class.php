<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class PluginDependencyException extends SystemBaseException {}
class PluginDependencyNotSentException extends PluginDependencyException {}

class PluginDependency extends SystemBase {    public static $prefix = 'pld';
    public static $tablename = 'pld_plugin_dependencies';
    public static $pkey_column = 'pld_plugin_dependency_id';

    protected static $foreign_key_actions = [
        'pdp_plg_plugin_id_dependee' => ['action' => 'prevent', 'message' => 'Cannot delete plugin - dependencies exist'],
        'pdp_plv_plugin_version_id' => ['action' => 'prevent', 'message' => 'Cannot delete plugin version - dependencies exist']
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
        'pld_plugin_dependency_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
        'pld_plugin_name' => array('type'=>'varchar(255)', 'is_nullable'=>false, 'required'=>true),
        'pld_depends_on' => array('type'=>'varchar(255)', 'is_nullable'=>false, 'required'=>true),
        'pld_version_constraint' => array('type'=>'varchar(50)'),
        'pld_dependency_type' => array('type'=>'varchar(20)'),
    );

    public static $field_constraints = array(
        // Note: Unique constraints should be defined in field_specifications, not field_constraints
        // 'pld_plugin_name_depends_on_unique' => array(
        //     'type' => 'unique',
        //     'fields' => array('pld_plugin_name', 'pld_depends_on')
        // )
    );

    function authenticate_write($data) {
        if ($data['current_user_permission'] < 10) {
            throw new SystemAuthenticationError(
                'Current user does not have permission to edit this entry in '. static::$tablename);
        }
    }
}

class MultiPluginDependency extends SystemMultiBase {
	protected static $model_class = 'PluginDependency';

    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        return $this->_get_resultsv2('pld_plugin_dependencies', $filters, $this->order_by, $only_count, $debug);
    }
}

?>