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
	public static $access_class = 'config';
	public static $write_permission = 10;
	public static $pkey_column = 'amu_admin_menu_id';

	protected static $foreign_key_actions = [
		// Self-referential (a submenu points at its parent menu), so it
		// doesn't fit the {prefix}_{target_prefix}_..._id convention.
		'amu_parent_menu_id' => ['action' => 'null', 'source_table' => 'amu_admin_menus'],
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
	    // Wide enough for what a sandboxed plugin's manifest may declare
	    // (SandboxPluginManager::menus): an 80-character page title, a
	    // 57-character slug, a page URL carrying its plugin and page names.
	    'amu_menudisplay' => array('type'=>'varchar(100)', 'required'=>true),
	    'amu_slug' => array('type'=>'varchar(64)', 'unique'=>true),
	    'amu_parent_menu_id' => array('type'=>'int4'),
	    'amu_defaultpage' => array('type'=>'varchar(255)', 'required'=>true),
	    'amu_order' => array('type'=>'int2', 'required'=>true),
	    // Not read: an entry shows to whoever may open its page
	    // (visible_entries()). Written 0; existing tables hold it NOT NULL.
	    'amu_min_permission' => array('type'=>'int4', 'default'=>0),   // not read or written: who sees an entry is who may open its page; old migrations still name it
	    'amu_disable' => array('type'=>'int2', 'default'=>0),
	    'amu_icon' => array('type'=>'varchar(16)'),
	    // App platform (docs/mobile_apps.md): a non-empty value flips this
	    // entry's app-navigation destination to {type: native, screen,
	    // fallback_url} — clients that know the screen render it natively,
	    // older builds keep loading the web page. Declared as nativeScreen in
	    // admin_menus.json / plugin.json menu entries.
	    'amu_native_screen' => array('type'=>'varchar(32)'),
	    'amu_setting_activate' => array('type'=>'varchar(64)'),
	    'amu_location' => array('type'=>'varchar(32)', 'default'=>'admin_sidebar', 'is_nullable'=>false),
	    'amu_visibility' => array('type'=>'varchar(8)', 'default'=>'in', 'is_nullable'=>false),
	);

	const LOCATIONS = array('admin_sidebar', 'user_dropdown', 'member_settings');
	const VISIBILITIES = array('in', 'out', 'both');

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
	
	static function getadminmenu($user_permission, $current_menu_slug, $get_all=false, $location='admin_sidebar'){
		require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
		$settings = Globalvars::get_instance();
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		//TOP MENU
		if($get_all){
			$sql = "SELECT * FROM amu_admin_menus WHERE amu_location = :location ORDER BY amu_admin_menus.amu_order ASC";
		}
		else{
			$sql = "SELECT * FROM amu_admin_menus WHERE amu_disable=0 AND amu_location = :location ORDER BY amu_admin_menus.amu_order ASC";
		}

		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':location', $location, PDO::PARAM_STR);
			$count = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}
		$entries = $q->fetchAll();
		if (!$get_all) {
			$entries = self::visible_entries($entries, (int)$user_permission);
		}
		$entries2 = $entries;

		$finalmenu = array();
		$current_parent_menu = null;
		// A number beside an entry: how many things wait on its page
		// (AdminMenuCounts). The menu editor (get_all) draws none.
		$counts = $get_all ? array() : AdminMenuCounts::all();
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
			
			$finalmenu[$entry->amu_admin_menu_id] = array('parent'=>$entry->amu_parent_menu_id, 'currentmain'=>FALSE, 'currentsub'=>FALSE, 'defaultpage'=>$defaultpage_link, 'display'=>$entry->amu_menudisplay, 'icon'=>$entry->amu_icon, 'has_subs'=>$has_subs, 'count'=>(int)($counts[$entry->amu_slug] ?? 0));

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

		// A section shows the sum of its entries' counts, so a collapsed
		// section still says something waits inside it.
		foreach ($finalmenu as $id => $item) {
			if ($item['parent'] && $item['count'] > 0 && isset($finalmenu[$item['parent']])) {
				$finalmenu[$item['parent']]['count'] += $item['count'];
			}
		}

		return $finalmenu;
	}

	/**
	 * The entries a reader at $permission sees (spec declared_permissions 3.4):
	 * an entry shows to whoever may open the page it links to
	 * (AdminPageAccess::levelForMenuUrl()), and an entry with no page of its
	 * own, a section, shows when one of its entries does. The menu editor can
	 * hide, order and rename an entry; who sees it is the page's.
	 *
	 * @param object[] $entries amu_admin_menus rows
	 * @return object[] the visible ones, in their order
	 */
	public static function visible_entries(array $entries, int $permission): array {
		$opens = array();
		$children = array();
		foreach ($entries as $e) {
			$page = trim((string)$e->amu_defaultpage);
			$opens[$e->amu_admin_menu_id] = $page === '' ? null
				: AdminPageAccess::levelForMenuUrl($page) <= $permission;
			if ($e->amu_parent_menu_id) {
				$children[$e->amu_parent_menu_id][] = $e->amu_admin_menu_id;
			}
		}
		$visible = function ($id) use (&$visible, $opens, $children) {
			if ($opens[$id] === true) return true;
			foreach ($children[$id] ?? array() as $child) {
				if (isset($opens[$child]) && $visible($child)) return true;
			}
			return false;
		};
		return array_values(array_filter($entries, function ($e) use ($visible) {
			return $visible($e->amu_admin_menu_id);
		}));
	}

	/**
	 * Get user dropdown menu items filtered by login state, the page each links to, setting, and disable.
	 * Returns AdminMenu objects ordered by amu_order ASC, amu_slug ASC.
	 *
	 * @param bool $is_logged_in Whether the current session is authenticated
	 * @param int $user_permission Effective permission level (0 when logged out)
	 * @param string $location Menu location to read ('user_dropdown' for the
	 *                         member nav, 'member_settings' for the settings rail)
	 * @return AdminMenu[] Array of loaded AdminMenu objects
	 */
	static function get_user_dropdown_items($is_logged_in, $user_permission, $location = 'user_dropdown') {
		$settings = Globalvars::get_instance();
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		// Visibility filter: logged-in sees in/both, logged-out sees out/both
		$allowed_visibilities = $is_logged_in ? array('in', 'both') : array('out', 'both');
		$placeholders = implode(',', array_fill(0, count($allowed_visibilities), '?'));

		// Who sees an entry is who may open its page (visible_entries()).
		$permission_value = $is_logged_in ? (int)$user_permission : 0;

		$sql = "SELECT * FROM amu_admin_menus
				WHERE amu_location = ?
				AND amu_disable = 0
				AND amu_visibility IN ({$placeholders})
				ORDER BY amu_order ASC, amu_slug ASC";

		$params = array_merge([$location], $allowed_visibilities);

		$q = $dblink->prepare($sql);
		$q->execute($params);
		$rows = self::visible_entries($q->fetchAll(PDO::FETCH_OBJ), $permission_value);

		$results = array();
		foreach ($rows as $row) {
			// Honor setting_activate gate
			if (!empty($row->amu_setting_activate)) {
				if (!$settings->get_setting($row->amu_setting_activate, true, true)) {
					continue;
				}
			}
			$obj = new AdminMenu($row->amu_admin_menu_id);
			// Hydrate from row data we already have to avoid an extra query per item
			$obj->load_from_data($row, array_keys((array)$row));
			$results[] = $obj;
		}

		return $results;
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
