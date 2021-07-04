<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FieldConstraints.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SingleRowAccessor.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Validator.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/data/group_members_class.php');

class GroupException extends SystemClassException {}

class Group extends SystemBase {

	const GROUP_TYPE_USER = 1;
	const GROUP_TYPE_EVENT = 2;
	const GROUP_TYPE_POST_TAG = 3;

	public static $fields = array(
		'grp_group_id' => 'ID of the group',
		'grp_name' => 'Group Name',
		'grp_usr_user_id_created' => 'User who created the group',
		'grp_create_time' => 'Created',
		'grp_update_time' => 'Updated',
		'grp_delete_time' => 'Is this group deleted?',
		'grp_type' => 'Type of group:  1-user, 2-event, 3-post'
	);
	
	public static $constants = array();

	public static $required = array(
		'grp_name');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $default_values = array(
		'grp_create_time' => 'now()', 
		'grp_update_time' => 'now()'
		);		
	
	public static function get_by_name($name) {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = "SELECT grp_group_id FROM grp_groups
			WHERE grp_name = :grp_name";

		try{
			$q = $dblink->prepare($sql);
			$q->bindValue(':grp_name', $name, PDO::PARAM_STR);
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}

		if (!$q->rowCount()) {
			return FALSE;
		}

		$r = $q->fetch();

		return new Group($r->grp_group_id, TRUE);
	}	
	
	public static function add_group($name, $user_id, $type){
		if(!$name){
			throw new GroupException('You cannot create a group without a name.');
			exit();			
		}
		
		if(strlen($name) > 100){
			throw new GroupException('The group name "'.$name.'" is too long.');
			exit();	
		}

		if($group = Group::get_by_name($name)){
			throw new GroupException('A group named "'.$name.'" already exists.');
			exit();	
		}
		else{

			$group = new Group(NULL);
			$group->set('grp_name', $name);
			$group->set('grp_usr_user_id_created', $user_id);
			$group->set('grp_type', $type);
			$group->prepare();
			$group->save();
			$group->load();
			return $group;
		}
		
	}	
	
	function add_member($user_id=NULL, $event_id=NULL, $post_id=NULL){ 
	
		if(!$this->is_member_in_group($user_id, $event_id, $post_id)){
			$groupmember = new GroupMember(NULL);
			$groupmember->set('grm_usr_user_id', $user_id);	
			$groupmember->set('grm_evt_event_id', $event_id);
			$groupmember->set('grm_pst_post_id', $post_id);
			$groupmember->set('grm_grp_group_id', $this->key);
			$groupmember->prepare();
			$groupmember->save();
			
			$this->set('grp_update_time', 'now()');
			$this->save();
		}
	}
	
	function remove_member($user_id=NULL, $event_id=NULL, $post_id=NULL){

		if(!$user_id && !$event_id && !$post_id){
			throw new GroupException('To remove a group member, user_id, event_id, or post_id is required.');
			exit();	
		}
		$searches = array('group_id' => $this->key);
		if($user_id){
			$searches['user_id'] = $user_id;
		}
		if($event_id){
			$searches['event_id'] = $event_id;
		}
		if($post_id){
			$searches['post_id'] = $post_id;
		}		
		$group_members = new MultiGroupMember($searches);
		$group_members->load();
		$group_member = $group_members->get(0);
		$group_member->remove();
	}	
	
	function remove_all_members(){
		$searches = array('group_id' => $this->key);	
		$group_members = new MultiGroupMember($searches);
		$group_members->load();
		foreach($group_members as $group_member){
			$group_member->remove();
		}
	}	
	
	function is_member_in_group($user_id=NULL, $event_id=NULL, $post_id=NULL) { 
		$searches = array('group_id' => $this->key);
		if($user_id){
			$searches['user_id'] = $user_id;
		}
		if($event_id){
			$searches['event_id'] = $event_id;
		}
		if($post_id){
			$searches['post_id'] = $post_id;
		}		
		$group_members = new MultiGroupMember($searches);
		
		if ($group_members->count_all() > 0) {
			$group_members->load();
			return $group_members->get(0);
		}
		return NULL;
	}	
	
	function get_member_list() {
		$group_members = new MultiGroupMember(array(
			'group_id' => $this->key,
		));
		
		$group_members->load();
		
		return $group_members;
	}	
	
	function get_member_count() {
		$count = new MultiGroupMember(array(
			'group_id' => $this->key,
		));
		
		$numrecords = $count->count_all();
		return $numrecords;
	}		
	
	private function _check_for_duplicate_group() {
		$count = new MultiGroup(array(
			'group_name' => $this->get('grp_name'),
		));
		
		if ($count->count_all() > 0) {
			$count->load();
			return $count->get(0);
		}
		return NULL;
	}		
	

	function prepare() {
		if ($this->data === NULL) {
			throw new GroupException('This has no data.');
		}
		
		//CHECK FOR DUPLICATES
		if(!$this->key){
			if($this->_check_for_duplicate_group()){
				throw new GroupException(
				'This group already exists');
			}
		}

		if ($this->key === NULL) {
			foreach (static::$zero_variables as $variable) {
				if ($this->key === NULL && $this->get($variable) === NULL) {
					echo $variable;
					$this->set($variable, 0);
				}
			}

		}
		
		if ($this->key === NULL) {
			foreach (static::$default_values as $variable=>$value) {
				if ($this->key === NULL && $this->get($variable) === NULL) { 
					$this->set($variable, $value);
				}
			}
		}		

		CheckRequiredFields($this, self::$required, self::$fields);

		foreach (self::$field_constraints as $field => $constraints) {
			foreach($constraints as $constraint) {
				if (gettype($constraint) == 'array') {
					$params = array();
					$params[] = self::$fields[$field];
					$params[] = $this->get($field);
					for($i=1;$i<count($constraint);$i++) {
						$params[] = $constraint[$i];
					}
					call_user_func_array($constraint[0], $params);
				} else {
					call_user_func($constraint, self::$fields[$field], $this->get($field));
				}
			}
		}

	}

	function load() {
		parent::load();
		$this->data = SingleRowFetch('grp_groups', 'grp_group_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($this->data === NULL) {
			throw new GroupException(
				'This group does not exist');
		}
	}
	
	
	function authenticate_write($session, $other_data=NULL) {
		$current_user = $session->get_user_id();
		if ($this->get('grp_usr_user_id') != $current_user) {
			// If the user's ID doesn't match , we have to make
			// sure they have admin access, otherwise denied.
			if ($session->get_permission() < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this group.');
			}
		}
	}

	function save() {
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('grp_group_id' => $this->key);
			// Editing an existing record
		} else {
			$p_keys = NULL;
			// Creating a new record
			unset($rowdata['grp_group_id']);
			//$rowdata['grp_create_time'] = 'now()';
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, 'grp_groups', $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['grp_group_id'];
	}


	function soft_delete(){
		$this->set('grp_delete_time', 'now()');
		$this->save();
		return true;
	}
	
	function undelete(){
		$this->set('grp_delete_time', NULL);
		$this->save();	
		return true;
	}
	
	function permanent_delete(){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$this_transaction = false;
		if(!$dblink->inTransaction()){
			$dblink->beginTransaction();
			$this_transaction = true;
		}

		$group_members = new MultiGroupMember(
			array('group_id' => $this->key),  //SEARCH CRITERIA
			NULL,  //SORT AND DIRECTION array($usrsort=>$usrsdirection)
			NULL,  //NUM PER PAGE
			NULL,  //OFFSET
			NULL  //AND OR OR
		);
		$group_members->load();
		
		foreach ($group_members as $group_member){
			$group_member->remove();
		}	

		$sql = 'DELETE FROM grp_groups WHERE grp_group_id=:grp_group_id';
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':grp_group_id', $this->key, PDO::PARAM_INT);
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
	
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS grp_groups_grp_group_id_seq
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
			CREATE TABLE IF NOT EXISTS "public"."grp_groups" (
			  "grp_group_id" int4 NOT NULL DEFAULT nextval(\'grp_groups_grp_group_id_seq\'::regclass),
			  "grp_name" varchar(100) COLLATE "pg_catalog"."default" NOT NULL,
			  "grp_usr_user_id_created" int4,
			  "grp_create_time" timestamp(6) NOT NULL,
			  "grp_update_time" timestamp(6),
			  "grp_delete_time" timestamp(6)
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."grp_groups" ADD CONSTRAINT "grp_groups_pkey" PRIMARY KEY ("grp_group_id");';
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

class MultiGroup extends SystemMultiBase {

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $entry) {
			$option_display = $entry->get('grp_name'); 
			$items[$option_display] = $entry->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	private function _get_results($only_count=FALSE) { 
		$where_clauses = array();
		$bind_params = array();
		
		if (array_key_exists('group_id', $this->options)) {
			$where_clauses[] = 'grp_group_id = ?';
			$bind_params[] = array($this->options['group_id'], PDO::PARAM_INT);
		}		

		if (array_key_exists('group_name', $this->options)) {
			$where_clauses[] = 'grp_name = ?';
			$bind_params[] = array($this->options['group_name'], PDO::PARAM_STR);
		}
	
		if (array_key_exists('user_id', $this->options)) {
			$where_clauses[] = 'grp_usr_user_id = ?';
			$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		}		

		if (array_key_exists('type', $this->options)) {
			$where_clauses[] = 'grp_type = ?';
			$bind_params[] = array($this->options['type'], PDO::PARAM_INT);
		}	
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM grp_groups ' . $where_clause;
		} else {
			$sql = 'SELECT * FROM grp_groups
				' . $where_clause . '
				ORDER BY ';
				
			if (!$this->order_by) {
				$sql .= " grp_group_id ASC ";
			}
			else {
				if (array_key_exists('group_id', $this->order_by)) {
					$sql .= ' grp_group_id ' . $this->order_by['group_id'];
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
			$child = new Group($row->grp_group_id);
			$child->load_from_data($row, array_keys(Group::$fields));
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
