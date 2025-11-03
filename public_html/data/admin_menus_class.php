<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
$settings = Globalvars::get_instance();
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class AdminMenuException extends SystemBaseException {}

class AdminMenu extends SystemBase {	public static $prefix = 'amu';
	public static $tablename = 'amu_admin_menus';
	public static $pkey_column = 'amu_admin_menu_id';

	protected static $foreign_key_actions = [
		'adm_adm_admin_menu_id_parent' => ['action' => 'null']
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
	    'amu_admin_menu_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'amu_menudisplay' => array('type'=>'varchar(32)', 'required'=>true),
	    'amu_slug' => array('type'=>'varchar(32)'),
	    'amu_parent_menu_id' => array('type'=>'int4'),
	    'amu_defaultpage' => array('type'=>'varchar(64)', 'required'=>true),
	    'amu_order' => array('type'=>'int2', 'required'=>true),
	    'amu_min_permission' => array('type'=>'int4', 'required'=>true),
	    'amu_disable' => array('type'=>'int2', 'default'=>0),
	    'amu_icon' => array('type'=>'varchar(16)'),
	    'amu_setting_activate' => array('type'=>'varchar(64)'),
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
	protected static $model_class = 'AdminMenu';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $entry) {
			$option_display = $entry->get('amu_menudisplay');
			$items[$entry->key] = $option_display;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}
	
	static function getadminmenu($user_permission, $current_menu_slug, $get_all=false){
		require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
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
			if($entry->amu_setting_activate && !$settings->get_setting($entry->amu_setting_activate, true, true)){
				//DO NOT DISPLAY IT
				continue;
			}
			
			$has_subs = FALSE;
			foreach ($entries2 as $entry2){
				if($entry2->amu_parent_menu_id == $entry->amu_admin_menu_id){
					$has_subs = TRUE;
				}
			}			
			
			if(!empty($entry->amu_defaultpage) && $entry->amu_defaultpage[0] === '/'){
				$defaultpage_link = $entry->amu_defaultpage;
			}
			else{
				$defaultpage_link = '/admin/'.($entry->amu_defaultpage ?? '');
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
}

?>
