<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SystemClass.php');

class PluginMigrationException extends SystemClassException {}
class PluginMigrationNotSentException extends PluginMigrationException {}

class PluginMigration extends SystemBase {
    public static $prefix = 'plm';
    public static $tablename = 'plm_plugin_migrations';
    public static $pkey_column = 'plm_plugin_migration_id';
    public static $permanent_delete_actions = array(
    );  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

    public static $fields = array(
        'plm_plugin_migration_id' => 'Primary key - Plugin Migration ID',
        'plm_plugin_name' => 'Plugin name',
        'plm_migration_id' => 'Migration identifier',
        'plm_version' => 'Migration version',
        'plm_applied_time' => 'When migration was applied',
        'plm_rollback_time' => 'When migration was rolled back',
        'plm_status' => 'Migration status',
        'plm_up_sql' => 'SQL for applying migration',
        'plm_down_sql' => 'SQL for rolling back migration',
        'plm_error_message' => 'Error message if migration failed',
    );

    /**
     * Field specifications define database column properties and schema constraints
     */
    public static $field_specifications = array(
        'plm_plugin_migration_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
        'plm_plugin_name' => array('type'=>'varchar(255)', 'is_nullable'=>false),
        'plm_migration_id' => array('type'=>'varchar(255)', 'is_nullable'=>false),
        'plm_version' => array('type'=>'varchar(20)', 'is_nullable'=>false),
        'plm_applied_time' => array('type'=>'timestamp(6)'),
        'plm_rollback_time' => array('type'=>'timestamp(6)'),
        'plm_status' => array('type'=>'varchar(20)'),
        'plm_up_sql' => array('type'=>'text'),
        'plm_down_sql' => array('type'=>'text'),
        'plm_error_message' => array('type'=>'text'),
    );

    public static $required_fields = array('plm_plugin_name', 'plm_migration_id', 'plm_version');

    public static $field_constraints = array(
        'plm_plugin_name_migration_id_unique' => array(
            'type' => 'unique',
            'fields' => array('plm_plugin_name', 'plm_migration_id')
        )
    );

    public static $zero_variables = array();

    public static $initial_default_values = array();

    function authenticate_write($data) {
        if ($data['current_user_permission'] < 10) {
            throw new SystemAuthenticationError(
                'Current user does not have permission to edit this entry in '. static::$tablename);
        }
    }
}

class MultiPluginMigration extends SystemMultiBase {

    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        return $this->_get_resultsv2('plm_plugin_migrations', $filters, $this->order_by, $only_count, $debug);
    }
    
    function load($debug = false) {
        parent::load();
        $q = $this->getMultiResults(false, $debug);
        foreach($q->fetchAll() as $row) {
            $child = new PluginMigration($row->plm_plugin_migration_id);
            $child->load_from_data($row, array_keys(PluginMigration::$fields));
            $this->add($child);
        }
    }
    
    function count_all($debug = false) {
        $q = $this->getMultiResults(TRUE, $debug);
        return $q;
    }
}

?>