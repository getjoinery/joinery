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

require_once($siteDir.'/data/users_class.php');

	
class GroupMemberException extends SystemClassException {}

class GroupMember extends SystemBase {
	public $prefix = 'grm';
	public $tablename = 'grm_group_members';
	public $pkey_column = 'grm_group_member_id';
	
	public static $fields = array(
		'grm_group_member_id' => 'ID of the group member',
		'grm_grp_group_id' => 'group id',
		'grm_usr_user_id' => 'User in group',
		'grm_evt_event_id' => 'Event in group',
		'grm_pst_post_id' => 'Post in group'
	);
	

	public static $required_fields = array('grm_grp_group_id');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array(
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
		if(!$this->key){
			if($this->_check_for_duplicates()){
				return FALSE;
			}			
		}
		parent::save();
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
	
	function _get_results($only_count=FALSE, $debug = false) { 
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
		$q = $this->_get_results();
		foreach($q->fetchAll() as $row) {
			$child = new GroupMember($row->grm_group_member_id);
			$child->load_from_data($row, array_keys(GroupMember::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->_get_results(TRUE);
		$counter = $q->fetch();
		return $counter->count;
	}
}


?>
