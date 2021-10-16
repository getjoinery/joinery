<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FieldConstraints.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SingleRowAccessor.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Validator.php');

require_once($_SERVER['DOCUMENT_ROOT'].'/data/users_class.php');

	
class GroupMemberException extends SystemClassException {}

class GroupMember extends SystemBase {

	public static $fields = array(
		'grm_group_member_id' => 'ID of the group member',
		'grm_grp_group_id' => 'group id',
		'grm_usr_user_id' => 'User in group',
		'grm_evt_event_id' => 'Event in group',
		'grm_pst_post_id' => 'Post in group'
	);
	
	public static $constants = array();

	public static $required = array('grm_grp_group_id');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $default_values = array(
		);		
		
	
	private function _check_for_duplicates() {
		
		$count = new MultiGroupMember(array(
			'group_id' => $this->get('grm_grp_group_id'),
			'user_id' => $this->get('grm_usr_user_id'),
			'event_id' => $this->get('grm_evt_event_id'),
			'post_id' => $this->get('grm_pst_post_id')
		));
		 
		if ($count->count_all() > 0) {
			$count->load();
			return $count->get(0);
		}
		return NULL;
	}	
	
	function remove(){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		
		$q = $dblink->prepare('DELETE FROM grm_group_members WHERE grm_group_member_id=?');
		$q->bindValue(1, $this->key, PDO::PARAM_INT);
		
		$success = $q->execute();
		
		return $success;		
	}	
	

	function prepare() {	
		if ($this->data === NULL) {
			throw new GroupMemberException('This has no data.');
		}

		//MAKE SURE THE RECORD HAS ONLY ONE FOREIGN KEY
		$count = 0;
		if($this->get('grm_usr_user_id')){
			$count++;
		}
		if($this->get('grm_evt_event_id')){
			$count++;
		}
		if($this->get('grm_pst_post_id')){
			$count++;
		}
		if($count != 1){
			throw new GroupMemberException('This GroupMember has more than one or zero foreign keys.');
		}
		
		if(!$this->key){
			if($this->_check_for_duplicates()){
				throw new GroupMemberException('This is a duplicate.');
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
		$this->data = SingleRowFetch('grm_group_members', 'grm_group_member_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($this->data === NULL) {
			throw new VideoException(
				'This group_member does not exist');
		}
	}
	
	
	function authenticate_write($session, $other_data=NULL) {
		$current_user = $session->get_user_id();
		if ($this->get('grm_usr_user_id') != $current_user) {
			// If the user's ID doesn't match , we have to make
			// sure they have admin access, otherwise denied.
			if ($session->get_permission() < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this group_member.');
			}
		}
	}

	function save() {
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('grm_group_member_id' => $this->key);
			// Editing an existing record
		} else {
			$p_keys = NULL;
			// Creating a new record
			unset($rowdata['grm_group_member_id']);
			//$rowdata['grm_create_time'] = 'now()';
			
			if($this->_check_for_duplicates()){
				return FALSE;
			}
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, 'grm_group_members', $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['grm_group_member_id'];
	}
	
	static function InitDB($mode='structure'){
	
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS grm_group_members_grm_group_member_id_seq
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
			CREATE TABLE IF NOT EXISTS "public"."grm_group_members" (
			  "grm_group_member_id" int4 NOT NULL DEFAULT nextval(\'grm_group_members_grm_group_member_id_seq\'::regclass),
			  "grm_usr_user_id" int4,
			  "grm_evt_event_id" int4,
			  "grm_pst_post_id" int4,
			  "grm_grp_group_id" int4 NOT NULL,
			  "grm_created_time" timestamp(6) DEFAULT now()
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."grm_group_members" ADD CONSTRAINT "grm_group_members_pkey" PRIMARY KEY ("grm_group_member_id");';
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

class MultiGroupMember extends SystemMultiBase {
	function get_user_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $item) {
			$user = new User($item->get('grm_usr_user_id'), TRUE);
			$items[$user->display_name()] = $user->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;
	}

	function get_event_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $item) {
			$event = new Event($item->get('grm_evt_event_id'), TRUE);
			$items[$event->get('evt_name')] = $event->key;
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
			$where_clauses[] = 'grm_grp_group_id = ?';
			$bind_params[] = array($this->options['group_id'], PDO::PARAM_INT);
		}

		if (array_key_exists('user_id', $this->options)) {
			$where_clauses[] = 'grm_usr_user_id = ?';
			$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		}	

		if (array_key_exists('event_id', $this->options)) {
			$where_clauses[] = 'grm_evt_event_id = ?';
			$bind_params[] = array($this->options['event_id'], PDO::PARAM_INT);
		}

		if (array_key_exists('post_id', $this->options)) {
			$where_clauses[] = 'grm_pst_post_id = ?';
			$bind_params[] = array($this->options['post_id'], PDO::PARAM_INT);
		}

		if (array_key_exists('has_post_id', $this->options)) {
			$where_clauses[] = 'grm_pst_post_id IS NOT NULL';
		}		

		if (array_key_exists('has_event_id', $this->options)) {
			$where_clauses[] = 'grm_evt_event_id IS NOT NULL';
		}	

		if (array_key_exists('has_user_id', $this->options)) {
			$where_clauses[] = 'grm_usr_user_id IS NOT NULL';
		}	
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM grm_group_members ' . $where_clause;
		} else {
			$sql = 'SELECT * FROM grm_group_members
				' . $where_clause . '
				ORDER BY ';
				
			if (!$this->order_by) {
				$sql .= " grm_group_member_id ASC ";
			}
			else {
				if (array_key_exists('group_member_id', $this->order_by)) {
					$sql .= ' grm_group_member_id ' . $this->order_by['group_member_id'];
				}
				if (array_key_exists('post_id', $this->order_by)) {
					$sql .= ' grm_pst_post_id ' . $this->order_by['post_id'];
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
			$child = new GroupMember($row->grm_group_member_id);
			$child->load_from_data($row, array_keys(GroupMember::$fields));
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
