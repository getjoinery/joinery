<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');

class PublicMenuException extends SystemClassException {}

class PublicMenu extends SystemBase {	public static $prefix = 'pmu';
	public static $tablename = 'pmu_public_menus';
	public static $pkey_column = 'pmu_public_menu_id';
	public static $permanent_delete_actions = array(	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

	public static $fields = array(		'pmu_public_menu_id' => 'Primary key - PublicMenu ID',
		'pmu_name' => 'Display Name', 
		'pmu_link' => 'link to the page, starting with a slash',
		'pmu_is_active' => 'Is this public_menu active?',
		'pmu_parent_menu_id' => 'pmu_public_menu_id of parent if a subitem',
		'pmu_order' => 'Order of appearance'
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
		'pmu_public_menu_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'pmu_name' => array('type'=>'varchar(100)'),
		'pmu_link' => array('type'=>'varchar(100)'),
		'pmu_is_active' => array('type'=>'bool'),
		'pmu_parent_menu_id' => array('type'=>'int4'),
		'pmu_order' => array('type'=>'int2'),
	);	

	public static $required_fields = array(
		'pmu_name', 'pmu_link');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array(
		'pmu_is_active' => 1, 
		);		

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 10) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}

}

class MultiPublicMenu extends SystemMultiBase {
	protected static $model_class = 'PublicMenu';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $entry) {
			$option_display = $entry->get('pmu_name'); 
			$items[$option_display] = $entry->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}
	
	static function get_sorted_array(){

		$menus = new MultiPublicMenu(
			array(),  //SEARCH CRITERIA
			array('order'=>'ASC'),  //SORT AND DIRECTION array($usrsort=>$usrsdirection)
			100,  //NUM PER PAGE
			0,  //OFFSET
			'OR');
		$menus->load();	
		$menus2 = $menus;

		$sorted_menu = array();
		foreach ($menus as $menu){
			if($menu->get('pmu_is_active')){
				if(!$menu->get('pmu_parent_menu_id')){
					$sorted_menu[$menu->key]['parent'] = true;
				}
				else{
					continue;
				}
				$sorted_menu[$menu->key]['id'] = $menu->key;
				$sorted_menu[$menu->key]['name'] = $menu->get('pmu_name');
				$sorted_menu[$menu->key]['order'] = $menu->get('pmu_order');
				$sorted_menu[$menu->key]['link'] = $menu->get('pmu_link');

				$submenu = array();
				foreach ($menus2 as $menu2){
					if($menu->key == $menu2->get('pmu_parent_menu_id')){
						$submenu[$menu2->key]['id'] = $menu2->key;
						$submenu[$menu2->key]['name'] = $menu2->get('pmu_name');
						$submenu[$menu2->key]['order'] = $menu2->get('pmu_order');
						$submenu[$menu2->key]['link'] = $menu2->get('pmu_link');
						$sorted_menu[$menu2->key]['parent'] = false;
					}
				}
				$sorted_menu[$menu->key]['submenu'] = $submenu;
			}
		}

		return $sorted_menu;
	}

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['public_menu_id'])) {
			$filters['pmu_public_menu_id'] = [$this->options['public_menu_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['parent_menu_id'])) {
			$filters['pmu_parent_menu_id'] = [$this->options['parent_menu_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['has_parent_menu_id'])) {
			$filters['pmu_parent_menu_id'] = "IS NOT NULL OR pmu_parent_menu_id != 0";
		}

		if (isset($this->options['has_no_parent_menu_id'])) {
			$filters['pmu_parent_menu_id'] = "IS NULL OR pmu_parent_menu_id = 0";
		}

		return $this->_get_resultsv2('pmu_public_menus', $filters, $this->order_by, $only_count, $debug);
	}
}

?>
