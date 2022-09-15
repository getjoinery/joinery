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
	public static $prefix = 'grm';
	public static $tablename = 'grm_group_members';
	public static $pkey_column = 'grm_group_member_id';
	public static $permanent_delete_actions = array(
		'grm_group_member_id' => 'delete',	
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'grm_group_member_id' => 'ID of the group member',
		'grm_grp_group_id' => 'group id',
		'grm_foreign_key_id' => 'Foreign key pointing to the member in this group',
	);

	public static $field_specifications = array(
		'grm_group_member_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'grm_grp_group_id' => array('type'=>'int4'),
		'grm_foreign_key_id' => array('type'=>'int8'),
	);	

	public static $required_fields = array('grm_grp_group_id');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array(
		);		
		
	
	private function _check_for_duplicates() {
		
		$count = new MultiGroupMember(array(
			'group_id' => $this->get('grm_grp_group_id'),
			'foreign_key_id' => $this->get('grm_foreign_key_id'),
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

	function save($debug=false) {
		if(!$this->key){
			if($this->_check_for_duplicates()){
				return FALSE;
			}			
		}
		parent::save($debug);
	}
	

	
}

class MultiGroupMember extends SystemMultiBase {
	function get_user_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $item) {
			$user = new User($item->get('grm_foreign_key_id'), TRUE);
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
			$event = new Event($item->get('grm_foreign_key_id'), TRUE);
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

		if (array_key_exists('foreign_key_id', $this->options)) {
			$where_clauses[] = 'grm_foreign_key_id = ?';
			$bind_params[] = array($this->options['foreign_key_id'], PDO::PARAM_INT);
		}	
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM grm_group_members ' . $where_clause;
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
		parent::load();
		$q = $this->_get_results(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new GroupMember($row->grm_group_member_id);
			$child->load_from_data($row, array_keys(GroupMember::$fields));
			$this->add($child);
		}
	}

}


?>
