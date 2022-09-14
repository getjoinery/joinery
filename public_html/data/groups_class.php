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

require_once($siteDir . '/data/group_members_class.php');

class GroupException extends SystemClassException {}

class Group extends SystemBase {
	public static $prefix = 'grp';
	public static $tablename = 'grp_groups';
	public static $pkey_column = 'grp_group_id';
	public static $permanent_delete_actions = array(
		'grp_group_id' => 'delete',	
		'evr_grp_group_id' => 'prevent',
		'evt_grp_group_id' => 'null',
		'grm_grp_group_id' => 'delete',
		'pro_grp_group_id' => 'prevent',
		'erg_grp_group_id' => 'prevent',
		'fil_grp_group_id' => 'null'
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	

	public static $fields = array(
		'grp_group_id' => 'ID of the group',
		'grp_name' => 'Group Name',
		'grp_usr_user_id_created' => 'User who created the group',
		'grp_create_time' => 'Created',
		'grp_update_time' => 'Updated',
		'grp_delete_time' => 'Is this group deleted?',
		//'grp_type' => 'Type of group:  1-user, 2-event, 3-post',
		'grp_category' => 'Dynamic replacement for group type',
	);

	public static $field_specifications = array(
		'grp_group_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'grp_name' => array('type'=>'varchar(100)'),
		'grp_usr_user_id_created' => array('type'=>'int4'),
		'grp_create_time' => array('type'=>'timestamp(6)'),
		'grp_update_time' => array('type'=>'timestamp(6)'),
		'grp_delete_time' => array('type'=>'timestamp(6)'),
		//'grp_type' => array('type'=>'int2'),
		'grp_category' => array('type'=>'varchar(24)'),
	);	

	public static $required_fields = array(
		'grp_name');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array(
		'grp_create_time' => 'now()', 
		'grp_update_time' => 'now()'
		);		
	
	//RETURNS A GROUP OBJECT WITH A SPECIFIED NAME
	public static function get_by_name($name) {
		if(!$name){
			throw new GroupException('A name is required to get groups by name.');
			exit();	
		}

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
	
	//CREATES A NEW GROUP
	public static function add_group($name, $user_id, $category){
		
		
		if(!$name){
			throw new GroupException('You cannot create a group without a name.');
			exit();			
		}
		
		if(strlen($name) > 100){
			throw new GroupException('The group name "'.$name.'" is too long.');
			exit();	
		}

		if(strlen($category) > 24){
			throw new GroupException('The group category "'.$category.'" is too long.');
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
			//$group->set('grp_type', $type);
			$group->set('grp_category', $category);
			$group->prepare();
			$group->save();
			$group->load();
			return $group;
		}
		
	}
	
	//RETURNS A LIST OF GROUPS WITH THE SPECIFIED CATEGORY
	public static function get_groups_in_category($category){
		if(!$category){
			throw new GroupException('A category is required to get groups in category.');
			exit();	
		}
		
		$groups = new MultiGroup(array(
			'category' => $category,
		));
		
		if ($groups->count_all() > 0) {
			$groups->load();
			return $groups;
		}
		return NULL;		
	}

	//RETURNS A LIST OF GROUPS FOR A MEMBER WITH SPECIFIED CATEGORY
	public static function get_groups_for_member($id, $category=NULL) { 
		if(!$id){
			throw new GroupException('To get groups for a group member an id is required.');
			exit();	
		}
		

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
	
		$sql = 'SELECT DISTINCT grp_group_id FROM grp_groups INNER JOIN grm_group_members ON grp_groups.grp_group_id=grm_group_members.grm_grp_group_id 
				WHERE grm_foreign_key_id = :id';
				
		if($category){
			$sql .= ' AND grp_category = :category ';
		}

		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':id', $id, PDO::PARAM_INT);
			if($category){
				$q->bindParam(':category', $category, PDO::PARAM_STR);
			}


			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}


		
		$groups = $q->fetchAll();
		$groups_out = new MultiGroup();
		foreach($groups as $group){
			$group_out = new Group($group->grp_group_id , TRUE);
			$groups_out->add($group_out);
		}
		return $groups_out; //RETURNS A LIST OF GROUPS

		
	}	
	
	//ADD A MEMBER TO THIS GROUP
	function add_member($id){ 
		if(!$id){
			throw new GroupException('To add a group member an id is required.');
			exit();	
		}
	
		if(!$this->is_member_in_group($id)){
			$groupmember = new GroupMember(NULL);
			$groupmember->set('grm_foreign_key_id', $id);	
			$groupmember->set('grm_grp_group_id', $this->key);
			$groupmember->prepare();
			$groupmember->save();
			
			$this->set('grp_update_time', 'now()');
			$this->save();
		}
	}
	
	//REMOVE A MEMBER FROM THIS GROUP
	function remove_member($id){

		if(!$id){
			throw new GroupException('To remove a group member an id is required.');
			exit();	
		}
		$searches = array('group_id' => $this->key);
		$searches['foreign_key_id'] = $id;
	
		$group_members = new MultiGroupMember($searches);
		$exists = $group_members->count_all();
		
		
		if($exists){
			$group_members->load();
			$group_member = $group_members->get(0);
			$group_member->remove();
			return true;
		}
		else{
			return false;
		}
	}	
	
	//REMOVE ALL MEMBERS FROM A GROUP
	function remove_all_members(){
		$searches = array('group_id' => $this->key);	
		$group_members = new MultiGroupMember($searches);
		$group_members->load();
		foreach($group_members as $group_member){
			$group_member->remove();
		}
	}	
	
	//RETURN A GROUP MEMBER OBJECT IF A MEMBER IS IN A GROUP
	function is_member_in_group($id) { 
		if(!$id){
			throw new GroupException('To check a group member an id is required.');
			exit();	
		}	
	
		$searches = array(
		'group_id' => $this->key,
		'foreign_key_id' => $id
		);

		$group_members = new MultiGroupMember($searches);
		
		if ($group_members->count_all() > 0) {
			$group_members->load();
			return $group_members->get(0);
		}
		return false;
	}	
	
	//RETURN A LIST OF GROUP MEMBERS IN A GROUP
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
		
		//CHECK FOR DUPLICATES
		if(!$this->key){
			if($this->_check_for_duplicate_group()){
				throw new GroupException(
				'This group already exists');
			}
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

	function _get_results($only_count=FALSE, $debug = false) { 
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


		if (array_key_exists('category', $this->options)) {
			$where_clauses[] = 'grp_category = ?';
			$bind_params[] = array($this->options['category'], PDO::PARAM_STR);
		}	
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM grp_groups ' . $where_clause;
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
			$child = new Group($row->grp_group_id);
			$child->load_from_data($row, array_keys(Group::$fields));
			$this->add($child);
		}
	}
}


?>
