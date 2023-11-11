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
	public static function get_by_name($name, $category, $return_deleted=false) {
		if(!$name){
			throw new GroupException('A name is required to get groups by name.');
			exit();	
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = "SELECT grp_group_id FROM grp_groups
			WHERE grp_name = :grp_name AND grp_delete_time IS ".($return_deleted ? 'NOT NULL' : 'NULL');
		$sql .= ' AND grp_category = :category LIMIT 1';

		try{
			$q = $dblink->prepare($sql);
			$q->bindValue(':grp_name', $name, PDO::PARAM_STR);
			$q->bindValue(':category', $category, PDO::PARAM_STR);
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
		
		if($group = Group::get_by_name($name, $category)){
			throw new GroupException('A group named "'.$name.'" already exists.');
			exit();	
		}
		else{

			$group = new Group(NULL);
			$group->set('grp_name', $name);
			$group->set('grp_usr_user_id_created', $user_id);
			$group->set('grp_category', $category);
			$group->prepare();
			$group->save();
			$group->load();
			return $group;
		}
		
	}
	
	//RETURNS A LIST OF GROUPS WITH THE SPECIFIED CATEGORY
	public static function get_groups_in_category($category, $return_deleted=false, $return_type = 'objects'){
		if(!$category){
			throw new GroupException('A category is required to get groups in category.');
			exit();	
		}
		
		$groups_out = new MultiGroup(array(
			'category' => $category,
			'return_deleted' => $return_deleted
		));
			
		$groups_out->load();
		
		
		if($return_type == 'objects'){
			return $groups_out; 
		}
		else if($return_type == 'names'){
			$names_out = array();
			foreach ($groups_out as $group_out){
				$names_out[] = $group_out->get('grp_name');
			}
			return $names_out;
			
		}
		else if($return_type == 'ids'){
			$ids_out = array();
			foreach ($groups_out as $group_out){
				$ids_out[] = $group_out->key;
			}
			return $ids_out;			
		}
		else{
			throw new GroupException('Unknown return type for get_groups_for_member.');
			exit();				
		}		
	}

	//RETURNS A LIST OF GROUPS FOR A MEMBER WITH SPECIFIED CATEGORY
	//CATEGORIES ARE 'USER', 'POST_TAG'
	//RETURN_TYPE IS 'OBJECTS', 'NAMES', 'IDS'
	public static function get_groups_for_member($foreign_key_id, $category, $return_deleted=false, $return_type = 'objects') { 
		if(!$foreign_key_id){
			throw new GroupException('To get groups for a group member an foreign_key_id is required.');
			exit();	
		}
		

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
	
		$sql = 'SELECT DISTINCT grp_group_id FROM grp_groups INNER JOIN grm_group_members ON grp_groups.grp_group_id=grm_group_members.grm_grp_group_id 
				WHERE grm_foreign_key_id = :foreign_key_id AND grp_delete_time IS '.($return_deleted ? 'NOT NULL' : 'NULL');
				
		$sql .= ' AND grp_category = :category ';		

		try{
			$q = $dblink->prepare($sql);
			$q->bindValue(':foreign_key_id', $foreign_key_id, PDO::PARAM_INT);
			$q->bindValue(':category', $category, PDO::PARAM_STR);
			


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
		
		if($return_type == 'objects'){
			return $groups_out; 
		}
		else if($return_type == 'names'){
			$names_out = array();
			foreach ($groups_out as $group_out){
				$names_out[] = $group_out->get('grp_name');
			}
			return $names_out;
			
		}
		else if($return_type == 'ids'){
			$ids_out = array();
			foreach ($groups_out as $group_out){
				$ids_out[] = $group_out->key;
			}
			return $ids_out;			
		}
		else{
			throw new GroupException('Unknown return type for get_groups_for_member.');
			exit();				
		}
		
	}	

	//ADD A FOREIGN KEY TO AN ARRAY OF GROUPS IN GROUP_NAMES_ARRAY
	static function AddMemberBulkByName($foreign_key_id, $group_names_array, $category){
		if(!$foreign_key_id){
			throw new GroupException('To add to groups to a group member a foreign_key_id is required.');
			exit();	
		}
		if(!$foreign_key_id){
			throw new GroupException('To add to groups to a group member a category is required.');
			exit();	
		}		
		if(empty($group_names_array)){
			return false;
		}
		
		$session = SessionControl::get_instance();

		//ADD IN ALL THE NEW TAGS
		$new_post_tag_ids = array();

		foreach ($group_names_array as $group_name){
			//DON'T SAVE BLANK TAGS
			if($group_name == ''){
				continue;
			}
			
			if(!$group = Group::get_by_name($group_name, $category)){
				$group = Group::add_group($group_name, $session->get_user_id(), $category);
			}
			$new_post_tag_ids[] = $group->key;
			$group->add_member($foreign_key_id);	
		}	

		//NOW REMOVE THE TAGS THAT NO LONGER APPLY
		$old_post_tag_ids = Group::get_groups_for_member($foreign_key_id, 'post_tag', false, 'ids');
		$tag_ids_removed = array_diff($old_post_tag_ids, $new_post_tag_ids);
		
		foreach($tag_ids_removed as $tag_id_removed){
			$group = new Group($tag_id_removed, true);
			$group->remove_member($foreign_key_id);	
		}
		
		return true;
				
	}
	
	//ADD A MEMBER TO THIS GROUP
	function add_member($foreign_key_id){ 
		if(!$foreign_key_id){
			throw new GroupException('To add a group member an id is required.');
			exit();	
		}
	
	
		if(!$groupmember = $this->is_member_in_group($foreign_key_id)){
			
			$groupmember = new GroupMember(NULL);
			$groupmember->set('grm_foreign_key_id', $foreign_key_id);	
			$groupmember->set('grm_grp_group_id', $this->key);
			$groupmember->prepare();
			$groupmember->save();
			
			$this->set('grp_update_time', 'now()');
			$this->save();
			return $groupmember;
		}
		else{
			return $groupmember;
		}
	}
	
	
	
	//REMOVE A MEMBER FROM THIS GROUP
	function remove_member($foreign_key_id){

		if(!$foreign_key_id){
			throw new GroupException('To remove a group member an id is required.');
			exit();	
		}
		
		if($group_member = $this->is_member_in_group($foreign_key_id)){
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

		$group_members = new MultiGroupMember(
			$searches,
			NULL,
			1,
			0,
			'AND');
		$group_members->load();
		
		if ($group_members->count() > 0) {
			return $group_members->get(0);
		}
		else{
			return false;
		}
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
		
		return $count->count_all();
	}				
	

	function prepare() {
		//CHECK FOR DUPLICATES
		if(!$this->key){
			if($this->check_for_duplicate(array('grp_name'))){
				throw new GroupException(
				'This group already exists');
			}
		}

	}
	
	
	function authenticate_write($data) {
		if ($this->get(static::$prefix.'_usr_user_id') != $data['current_user_id']) {
			// If the user's ID doesn't match, we have to make
			// sure they have admin access, otherwise denied.
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this entry in '. static::$tablename);
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

		if (array_key_exists('deleted', $this->options)) {
			$where_clauses[] = 'grp_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
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
				
			if (empty($this->order_by)) {
				$sql .= " grp_group_id ASC ";
			}
			else {
				if (array_key_exists('group_id', $this->order_by)) {
					$sql .= ' grp_group_id ' . $this->order_by['group_id'];
				}	
				if (array_key_exists('group_name', $this->order_by)) {
					$sql .= ' grp_name ' . $this->order_by['group_name'];
				}						
				if (array_key_exists('grp_update_time', $this->order_by)) {
					$sql .= ' grp_update_time ' . $this->order_by['grp_update_time'];
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
