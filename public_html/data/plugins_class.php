<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SystemClass.php');
	

class PluginException extends SystemClassException {}
class PluginNotSentException extends PluginException {};

class Plugin extends SystemBase {
	public static $prefix = 'plg';
	public static $tablename = 'plg_plugins';
	public static $pkey_column = 'plg_plugin_id';
	public static $permanent_delete_actions = array(
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	

	public static $fields = array(
		'plg_plugin_id' => 'Primary key - Plugin ID',
		'plg_name' => 'Name of the plugin',
		'plg_activated_time' => 'Activation time',
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
		'plg_plugin_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'plg_name' => array('type'=>'varchar(128)'),
		'plg_activated_time' => array('type'=>'timestamp(6)'),
	);

	public static $required_fields = array('plg_name');

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

class MultiPlugin extends SystemMultiBase {

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        

        
        return $this->_get_resultsv2('plg_plugins', $filters, $this->order_by, $only_count, $debug);
    }
    
    function load($debug = false) {
        parent::load();
        $q = $this->getMultiResults(false, $debug);
        foreach($q->fetchAll() as $row) {
            $child = new Plugin($row->plg_plugin_id);
            $child->load_from_data($row, array_keys(Plugin::$fields));
            $this->add($child);
        }
    }
    
    function count_all($debug = false) {
        $q = $this->getMultiResults(TRUE, $debug);
        return $q;
    }

}



?>
