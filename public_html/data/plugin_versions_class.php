<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SystemClass.php');

class PluginVersionException extends SystemClassException {}
class PluginVersionNotSentException extends PluginVersionException {}

class PluginVersion extends SystemBase {
    public static $prefix = 'plv';
    public static $tablename = 'plv_plugin_versions';
    public static $pkey_column = 'plv_plugin_version_id';
    public static $permanent_delete_actions = array(
    );  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

    public static $fields = array(
        'plv_plugin_version_id' => 'Primary key - Plugin Version ID',
        'plv_plugin_name' => 'Plugin name',
        'plv_installed_version' => 'Currently installed version',
        'plv_available_version' => 'Available version for update',
        'plv_last_check_time' => 'Last time version was checked',
        'plv_update_available' => 'Whether update is available',
        'plv_fingerprint' => 'File fingerprint for change detection',
        'plv_metadata' => 'Plugin metadata JSON',
    );

    /**
     * Field specifications define database column properties and schema constraints
     */
    public static $field_specifications = array(
        'plv_plugin_version_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
        'plv_plugin_name' => array('type'=>'varchar(255)', 'is_nullable'=>false, 'unique'=>true),
        'plv_installed_version' => array('type'=>'varchar(20)', 'is_nullable'=>false),
        'plv_available_version' => array('type'=>'varchar(20)'),
        'plv_last_check_time' => array('type'=>'timestamp(6)'),
        'plv_update_available' => array('type'=>'bool'),
        'plv_fingerprint' => array('type'=>'varchar(64)'),
        'plv_metadata' => array('type'=>'text'),
    );

    public static $required_fields = array('plv_plugin_name', 'plv_installed_version');

    public static $field_constraints = array();

    public static $zero_variables = array();

    public static $initial_default_values = array();

    function authenticate_write($data) {
        if ($data['current_user_permission'] < 10) {
            throw new SystemAuthenticationError(
                'Current user does not have permission to edit this entry in '. static::$tablename);
        }
    }
}

class MultiPluginVersion extends SystemMultiBase {

    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        return $this->_get_resultsv2('plv_plugin_versions', $filters, $this->order_by, $only_count, $debug);
    }
    
    function load($debug = false) {
        parent::load();
        $q = $this->getMultiResults(false, $debug);
        foreach($q->fetchAll() as $row) {
            $child = new PluginVersion($row->plv_plugin_version_id);
            $child->load_from_data($row, array_keys(PluginVersion::$fields));
            $this->add($child);
        }
    }
    
    function count_all($debug = false) {
        $q = $this->getMultiResults(TRUE, $debug);
        return $q;
    }
}

?>