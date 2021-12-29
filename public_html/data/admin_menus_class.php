<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FieldConstraints.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SingleRowAccessor.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Validator.php');


class AdminMenuException extends SystemClassException {}

class AdminMenu extends SystemBase {


	public static $fields = array(
		'amu_admin_menu_id' => 'ID of the admin_menu',
		'amu_menudisplay' => 'Display Name', 
		'amu_parent_menu_id' => 'amu_admin_menu_id of parent if a subitem', 
		'amu_defaultpage' => 'link to the page, just the filename',
		'amu_order' => 'The order',
		'amu_min_permission' => 'Min permission 1-10',
		'amu_disable' => 'If disabled',
		'amu_icon' => 'Icon for the menu item'
	);


	public static $required_fields = array(
		'amu_menudisplay');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array(
		'amu_disable' => 0, 
		);		

	function prepare() {
		
	}

	function load() {
		parent::load();
		$this->data = SingleRowFetch('amu_admin_menus', 'amu_admin_menu_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($this->data === NULL) {
			throw new AdminMenuException(
				'This admin_menu does not exist');
		}
	}
	
	
	function authenticate_write($session, $other_data=NULL) {
		$current_user = $session->get_user_id();

		if ($session->get_permission() < 10) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this admin_menu.');
		}

	}

	function save() {
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('amu_admin_menu_id' => $this->key);
			// Editing an existing record
		} else {
			$p_keys = NULL;
			// Creating a new record
			unset($rowdata['amu_admin_menu_id']);
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, 'amu_admin_menus', $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['amu_admin_menu_id'];
	}


	
	function permanent_delete(){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$this_transaction = false;
		if(!$dblink->inTransaction()){
			$dblink->beginTransaction();
			$this_transaction = true;
		}	

		$sql = 'DELETE FROM amu_admin_menus WHERE amu_admin_menu_id=:amu_admin_menu_id OR amu_parent_menu_id=:amu_admin_menu_id';
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':amu_admin_menu_id', $this->key, PDO::PARAM_INT);
			$count = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}
		
		if($this_transaction){
			$dblink->commit();
		}
		
		$this->key = NULL;
		
		return true;		
	}
	

	static function InitDB($mode='structure'){
	
		//ADMIN MENUS
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS amu_admin_menus_amu_admin_menu_id_seq
				INCREMENT BY 1
				NO MAXVALUE
				NO MINVALUE
				CACHE 1;';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}			
		
		$sql = '
			CREATE TABLE IF NOT EXISTS "public"."amu_admin_menus" (
			  "amu_admin_menu_id" int4 NOT NULL DEFAULT nextval(\'amu_admin_menus_amu_admin_menu_id_seq\'::regclass),
			  "amu_menudisplay" varchar(32) COLLATE "pg_catalog"."default" NOT NULL DEFAULT \'\'::character varying,
			  "amu_parent_menu_id" int4 DEFAULT \'-1\'::integer,
			  "amu_defaultpage" varchar(64) COLLATE "pg_catalog"."default" NOT NULL DEFAULT \'\'::character varying,
			  "amu_order" int2 NOT NULL DEFAULT (0)::smallint,
			  "amu_min_permission" int4 NOT NULL DEFAULT 0,
			  "amu_disable" int2 NOT NULL DEFAULT (0)::smallint,
			  "amu_icon" varchar(16) COLLATE "pg_catalog"."default"
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."amu_admin_menus" ADD CONSTRAINT "amu_admin_menus_pkey" PRIMARY KEY ("amu_admin_menu_id");';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}

		//FOR FUTURE
		//ALTER TABLE table_name ADD COLUMN IF NOT EXISTS column_name INTEGER;
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
	
	static function getadminmenu($user_permission, $current_menuid, $get_all=false){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		
		//TOP MENU
		if($get_all){
			$sql = "SELECT amu_admin_menu_id, amu_icon,amu_menudisplay, amu_defaultpage,amu_parent_menu_id FROM amu_admin_menus WHERE true ORDER BY amu_admin_menus.amu_order ASC";
		}
		else{
			$sql = "SELECT amu_admin_menu_id, amu_icon,amu_menudisplay, amu_defaultpage,amu_parent_menu_id FROM amu_admin_menus WHERE amu_min_permission <= :currpermission AND amu_disable=0 ORDER BY amu_admin_menus.amu_order ASC";
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
	   

	   //FIND OUT WHICH SUBTAB WE ARE ON BY GETTING THE CURRENT FILE
	   $thisfile = basename($_SERVER['PHP_SELF']);

		$firsttime=0;
		while(current($_GET)){
			$thisgetvar = key($_GET);
			$thisgetval = current($_GET);

			if($firsttime == 0){
				$thisfile .= "?$thisgetvar=$thisgetval";
				$firsttime=1;
			}
			else{
				$thisfile .= "&$thisgetvar=$thisgetval";
			}
			next($_GET);
		}

	
		$finalmenu = array();
		foreach ($entries as $entry){
			$has_subs = FALSE;
			foreach ($entries2 as $entry2){
				if($entry2->amu_parent_menu_id == $entry->amu_admin_menu_id){
					$has_subs = TRUE;
				}
			}			
			
			
			$finalmenu[$entry->amu_admin_menu_id] = array('parent'=>$entry->amu_parent_menu_id, 'currentmain'=>FALSE, 'currentsub'=>FALSE, 'defaultpage'=>$entry->amu_defaultpage, 'display'=>$entry->amu_menudisplay, 'icon'=>$entry->amu_icon, 'has_subs'=>$has_subs);

			if($current_menuid == $entry->amu_admin_menu_id){
				$finalmenu[$entry->amu_admin_menu_id]['currentmain'] = 1;
			}
			else if($thisfile == $entry->amu_defaultpage){
				$finalmenu[$entry->amu_admin_menu_id]['currentsub'] = 1;
			}
		}
		
		return $finalmenu;
	}

	private function _get_results($only_count=FALSE) { 
		$where_clauses = array();
		$bind_params = array();
		
		if (array_key_exists('admin_menu_id', $this->options)) {
			$where_clauses[] = 'amu_admin_menu_id = ?';
			$bind_params[] = array($this->options['admin_menu_id'], PDO::PARAM_INT);
		}		

		if (array_key_exists('parent_menu_id', $this->options)) {
			$where_clauses[] = 'amu_parent_menu_id = ?';
			$bind_params[] = array($this->options['parent_menu_id'], PDO::PARAM_INT);
		}	

		if (array_key_exists('has_parent_menu_id', $this->options)) {
			$where_clauses[] = '(amu_parent_menu_id IS NOT NULL OR amu_parent_menu_id != 0)';
		}
	
		if (array_key_exists('is_not_disabled', $this->options)) {
			$where_clauses[] = '(amu_disabled IS NOT NULL OR amu_disabled != false)';
		}
		
		if (array_key_exists('has_no_parent_menu_id', $this->options)) {
			$where_clauses[] = '(amu_parent_menu_id IS NULL OR amu_parent_menu_id = 0)';
		}
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM amu_admin_menus ' . $where_clause;
		} else {
			$sql = 'SELECT * FROM amu_admin_menus
				' . $where_clause . '
				ORDER BY ';
				
			if (!$this->order_by) {
				$sql .= " amu_admin_menu_id ASC ";
			}
			else {
				if (array_key_exists('admin_menu_id', $this->order_by)) {
					$sql .= ' amu_admin_menu_id ' . $this->order_by['admin_menu_id'];
				}		
				if (array_key_exists('order', $this->order_by)) {
					$sql .= ' amu_order ' . $this->order_by['order'];
				}	
			}				

			$sql .= ' '.$this->generate_limit_and_offset();				
		}
		

		$q = DbConnector::GetPreparedStatement($sql);

		$total_params = count($bind_params);
		for ($i=0; $i<$total_params; $i++) {
			list($param, $type) = $bind_params[$i];
			$q->bindValue($i+1, $param, $type);
		}
		$q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);

		return $q;
	}

	function load() {
		$q = $this->_get_results();
		foreach($q->fetchAll() as $row) {
			$child = new AdminMenu($row->amu_admin_menu_id);
			$child->load_from_data($row, array_keys(AdminMenu::$fields));
			$this->add($child);
		}
	}

	function count_all() {
		$q = $this->_get_results(TRUE);
		$counter = $q->fetch();
		return $counter->count;
	}
}


?>
