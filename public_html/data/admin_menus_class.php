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


class AdminMenuException extends SystemClassException {}

class AdminMenu extends SystemBase {

	public static $prefix = 'amu';
	public static $tablename = 'amu_admin_menus';
	public static $pkey_column = 'amu_admin_menu_id';
	public static $permanent_delete_actions = array(
		 
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

	public static $fields = array(
		'amu_admin_menu_id' => 'ID of the admin_menu',
		'amu_menudisplay' => 'Display Name', 
		'amu_slug' => 'Display Name', 
		'amu_parent_menu_id' => 'amu_admin_menu_id of parent if a subitem', 
		'amu_defaultpage' => 'link to the page, just the filename',
		'amu_order' => 'The order',
		'amu_min_permission' => 'Min permission 1-10',
		'amu_disable' => 'If disabled',
		'amu_icon' => 'Icon for the menu item',
		'amu_setting_activate' => 'Setting that will turn this on',
	);

	public static $field_specifications = array(
		'amu_admin_menu_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false), 
		'amu_menudisplay' => array('type'=>'varchar(32)'),
		'amu_slug' => array('type'=>'varchar(32)'),
		'amu_parent_menu_id' => array('type'=>'int4'),
		'amu_defaultpage' => array('type'=>'varchar(64)'),
		'amu_order' => array('type'=>'int2'),
		'amu_min_permission' => array('type'=>'int4'),
		'amu_disable' => array('type'=>'int2'),
		'amu_icon' => array('type'=>'varchar(16)'),
		'amu_setting_activate' => array('type'=>'varchar(64)'),
	);


	public static $required_fields = array(
		'amu_menudisplay', 'amu_defaultpage', 'amu_order', 'amu_min_permission');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array(
		'amu_disable' => 0, 
		);		 
	

	function authenticate_write($data) {
		// If the user's ID doesn't match, we have to make
		// sure they have admin access, otherwise denied.
		if ($data['current_user_permission'] < 10) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}

}

class MultiAdminMenu extends SystemMultiBase {

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $entry) {
			$option_display = $entry->get('amu_menudisplay'); 
			$items[$option_display] = $entry->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}
	
	static function getadminmenu($user_permission, $current_menu_slug, $get_all=false){
		require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
		$settings = Globalvars::get_instance();
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		
		//TOP MENU
		if($get_all){
			$sql = "SELECT * FROM amu_admin_menus WHERE true ORDER BY amu_admin_menus.amu_order ASC";
		}
		else{
			$sql = "SELECT * FROM amu_admin_menus WHERE amu_min_permission <= :currpermission AND amu_disable=0 ORDER BY amu_admin_menus.amu_order ASC";
		}
		
		try{
			$q = $dblink->prepare($sql);
			if(!$get_all){
				$q->bindParam(':currpermission', $user_permission, PDO::PARAM_INT);
			}
			$count = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}
		$entries = $q->fetchAll();
		$entries2 = $entries;

	
		$finalmenu = array();
		$current_parent_menu = null;
		foreach ($entries as $entry){
			//IF THE SETTING IS OFF, SKIP IT 
			if($entry->amu_setting_activate && !$settings->get_setting($entry->amu_setting_activate)){
				//DO NOT DISPLAY IT
				continue;
			}
			
			$has_subs = FALSE;
			foreach ($entries2 as $entry2){
				if($entry2->amu_parent_menu_id == $entry->amu_admin_menu_id){
					$has_subs = TRUE;
				}
			}			
			
			if($entry->amu_defaultpage[0] === '/'){
				$defaultpage_link = $entry->amu_defaultpage;
			}
			else{
				$defaultpage_link = '/admin/'.$entry->amu_defaultpage;
			}
			
			$finalmenu[$entry->amu_admin_menu_id] = array('parent'=>$entry->amu_parent_menu_id, 'currentmain'=>FALSE, 'currentsub'=>FALSE, 'defaultpage'=>$defaultpage_link, 'display'=>$entry->amu_menudisplay, 'icon'=>$entry->amu_icon, 'has_subs'=>$has_subs);
		
		
			if($current_menu_slug == $entry->amu_slug){
				$finalmenu[$entry->amu_admin_menu_id]['currentsub'] = 1;
				if($entry->amu_parent_menu_id){
					$current_parent_menu = $entry->amu_parent_menu_id;	
				}
				else{
					$finalmenu[$entry->amu_admin_menu_id]['currentmain'] = 1;
				}
			}
		}
		
		if($current_parent_menu){
			$finalmenu[$current_parent_menu]['currentmain'] = 1;	
		}

		return $finalmenu;
	}

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        if (isset($this->options['admin_menu_id'])) {
            $filters['amu_admin_menu_id'] = [$this->options['admin_menu_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['parent_menu_id'])) {
            $filters['amu_parent_menu_id'] = [$this->options['parent_menu_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['has_parent_menu_id'])) {
            $filters['amu_parent_menu_id'] = "IS NOT NULL AND amu_parent_menu_id != 0";
        }
    
        if (isset($this->options['is_not_disabled'])) {
            $filters['amu_disabled'] = "IS NOT NULL AND amu_disabled != false";
        }
        
        if (isset($this->options['has_no_parent_menu_id'])) {
            $filters['amu_parent_menu_id'] = "IS NULL OR amu_parent_menu_id = 0";
        }

        return $this->_get_resultsv2('amu_admin_menus', $filters, $this->order_by, $only_count, $debug);
    }

	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new AdminMenu($row->amu_admin_menu_id);
			$child->load_from_data($row, array_keys(AdminMenu::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}
}


?>
