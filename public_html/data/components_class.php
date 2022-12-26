<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/DbConnector.php');
require_once($siteDir . '/includes/FieldConstraints.php');
require_once($siteDir . '/includes/Globalvars.php');
require_once($siteDir . '/includes/LibraryFunctions.php');
require_once($siteDir . '/includes/SingleRowAccessor.php');
require_once($siteDir . '/includes/SystemClass.php');
require_once($siteDir . '/includes/Validator.php');

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

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('link', $this->options)) {
			$where_clauses[] = 'com_link = ?';
			$bind_params[] = array($this->options['link'], PDO::PARAM_STR);
		}
	
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}


		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM com_components ' . $where_clause;
		} else {
			$sql = 'SELECT * FROM com_components
				' . $where_clause . '
				ORDER BY ';
			
			if (!$this->order_by) {
				$sql .= " com_component_id ASC ";
			}
			else {
				if (array_key_exists('component_id', $this->order_by)) {
					$sql .= ' com_component_id ' . $this->order_by['component_id'];
				}			
			}
				
			$sql .= ' '.$this->generate_limit_and_offset();	

		}			
		

		$q = DbConnector::GetPreparedStatement($sql);

		if($debug){
			echo $sql. "<br>\n";
			print_r($this->options);
		}

		$total_params = count($bind_params);
		for ($i=0; $i<$total_params; $i++) {
			list($param, $type) = $bind_params[$i];
			$q->bindValue($i+1, $param, $type);
		}
		$q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);

		return $q;
	}

	function load($debug = false) {
		parent::load();
		$q = $this->_get_results(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new Component($row->com_component_id);
			$child->load_from_data($row, array_keys(Component::$fields));
			$this->add($child);
		}
	}
}


?>
