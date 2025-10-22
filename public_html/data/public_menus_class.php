<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class PublicMenuException extends SystemBaseException {}

class PublicMenu extends SystemBase {	public static $prefix = 'pmu';
	public static $tablename = 'pmu_public_menus';
	public static $pkey_column = 'pmu_public_menu_id';
	public static $permanent_delete_actions = array(	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

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
	    'pmu_public_menu_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'pmu_name' => array('type'=>'varchar(100)', 'required'=>true),
	    'pmu_link' => array('type'=>'varchar(100)', 'required'=>true),
	    'pmu_is_active' => array('type'=>'bool', 'default'=>1),
	    'pmu_parent_menu_id' => array('type'=>'int4'),
	    'pmu_order' => array('type'=>'int2'),
	);	

	public static $field_constraints = array();	

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
