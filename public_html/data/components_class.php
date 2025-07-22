<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');

class ComponentException extends SystemClassException {}

class Component extends SystemBase {
	public static $prefix = 'com';
	public static $tablename = 'com_components';
	public static $pkey_column = 'com_component_id';
	public static $permanent_delete_actions = array(
		'com_component_id' => 'delete',	
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'com_component_id' => 'ID of the url',
		'com_title' => 'Name of component',
		'com_order' => 'Order of the component on the page',
		'com_published_time' => 'Time published',
		'com_create_time' => 'Time Created',
		'com_script_filename' => 'Filename to look for if we want to run a script before rendering',
		'com_delete_time' => 'Time of deletion',
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
		'com_component_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'com_title' => array('type'=>'varchar(255)'),
		'com_order' => array('type'=>'int2'),
		'com_published_time' => array('type'=>'timestamp(6)'),
		'com_create_time' => array('type'=>'timestamp(6)'),
		'com_script_filename' => array('type'=>'varchar(255)'),
		'com_delete_time' => array('type'=>'timestamp(6)'),
	);
	
	public static $required_fields = array();

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array('com_create_time' => 'now()'
		);		
	
	


}

class MultiComponent extends SystemMultiBase {

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['link'])) {
            $filters['com_link'] = [$this->options['link'], PDO::PARAM_STR];
        }

        return $this->_get_resultsv2('com_components', $filters, $this->order_by, $only_count, $debug);
    }

	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new Component($row->com_component_id);
			$child->load_from_data($row, array_keys(Component::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}
}


?>
