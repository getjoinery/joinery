<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SystemClass.php');

class PluginDependencyException extends SystemClassException {}
class PluginDependencyNotSentException extends PluginDependencyException {}

class PluginDependency extends SystemBase {
    public static $prefix = 'pld';
    public static $tablename = 'pld_plugin_dependencies';
    public static $pkey_column = 'pld_plugin_dependency_id';
    public static $permanent_delete_actions = array(
    );  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

    public static $fields = array(
        'pld_plugin_dependency_id' => 'Primary key - Plugin Dependency ID',
        'pld_plugin_name' => 'Plugin name that has the dependency',
        'pld_depends_on' => 'Plugin name that this plugin depends on',
        'pld_version_constraint' => 'Version constraint for dependency',
        'pld_dependency_type' => 'Type of dependency (requires, conflicts, etc)',
    );

    /**
     * Field specifications define database column properties and schema constraints
     */
    public static $field_specifications = array(
        'pld_plugin_dependency_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
        'pld_plugin_name' => array('type'=>'varchar(255)', 'is_nullable'=>false),
        'pld_depends_on' => array('type'=>'varchar(255)', 'is_nullable'=>false),
        'pld_version_constraint' => array('type'=>'varchar(50)'),
        'pld_dependency_type' => array('type'=>'varchar(20)'),
    );

    public static $required_fields = array('pld_plugin_name', 'pld_depends_on');

    public static $field_constraints = array(
        // Note: Unique constraints should be defined in field_specifications, not field_constraints
        // 'pld_plugin_name_depends_on_unique' => array(
        //     'type' => 'unique',
        //     'fields' => array('pld_plugin_name', 'pld_depends_on')
        // )
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

class MultiPluginDependency extends SystemMultiBase {

    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        return $this->_get_resultsv2('pld_plugin_dependencies', $filters, $this->order_by, $only_count, $debug);
    }
    
    function load($debug = false) {
        parent::load();
        $q = $this->getMultiResults(false, $debug);
        foreach($q->fetchAll() as $row) {
            $child = new PluginDependency($row->pld_plugin_dependency_id);
            $child->load_from_data($row, array_keys(PluginDependency::$fields));
            $this->add($child);
        }
    }
    
    function count_all($debug = false) {
        $q = $this->getMultiResults(TRUE, $debug);
        return $q;
    }
}

?>