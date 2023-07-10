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

require_once($siteDir . '/data/content_versions_class.php');
require_once($siteDir . '/data/groups_class.php');

class PostException extends SystemClassException {}

class Post extends SystemBase {
	public static $prefix = 'pst';
	public static $tablename = 'pst_posts';
	public static $pkey_column = 'pst_post_id';
	public static $url_namespace = 'post';  //SUBDIRECTORY WHERE ITEMS ARE LOCATED EXAMPLE: DOMAIN.COM/URL_NAMESPACE/THIS_ITEM
	public static $permanent_delete_actions = array(
		'pst_post_id' => 'delete',	
		'cmt_pst_post_id' => 'delete',
		'grm_pst_post_id' => 'delete'
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'pst_post_id' => 'ID of the post',
		'pst_title' => 'Post Title',
		'pst_link' => 'Link of the post',
		'pst_usr_user_id' => 'User this post is associated with',
		'pst_body' => 'Body of the post',
		'pst_is_published' => 'Is this post published?',
		'pst_published_time' => 'Time published',
		'pst_is_on_homepage' => 'On homepage',
		'pst_is_pinned' => 'On homepage',
		'pst_create_time' => 'Time Created',
		'pst_short_description' => 'Short description, no html, max 255 chars',
		'pst_delete_time' => 'Time of deletion',
	);

	public static $field_specifications = array(
		'pst_post_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'pst_title' => array('type'=>'varchar(255)'),
		'pst_link' => array('type'=>'varchar(255)'),
		'pst_usr_user_id' => array('type'=>'int4'),
		'pst_body' => array('type'=>'text'),
		'pst_is_published' => array('type'=>'bool'),
		'pst_published_time' => array('type'=>'timestamp(6)'),
		'pst_is_on_homepage' => array('type'=>'bool'),
		'pst_is_pinned' => array('type'=>'bool'),
		'pst_create_time' => array('type'=>'timestamp(6)'),
		'pst_short_description' => array('type'=>'varchar(255)'),
		'pst_delete_time' => array('type'=>'timestamp(6)'),
	);

	public static $required_fields = array('pst_usr_user_id');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
	'pst_create_time' => 'now()', 
	'pst_is_on_homepage' => true
	);	
	

	function save_tags($tags_array){
		$tags_array = array_filter($tags_array);

		if(empty($tags_array)){
			return false;
		}
		
		$session = SessionControl::get_instance();

		//ADD IN ALL THE NEW TAGS
		$new_post_tag_ids = array();

		foreach ($tags_array as $tag){
			$tag = trim($tag);
			$tag = preg_replace("/[^A-Za-z0-9 -_]/", '', $tag);
			//DON'T SAVE BLANK TAGS
			if($tag == ''){
				continue;
			}
			
			if(!$group = Group::get_by_name($tag)){
				$group = Group::add_group($tag, $session->get_user_id(), 'post_tag');
			}
			$new_post_tag_ids[] = $group->key;
			$group->add_member($this->key);	
		}	

		//NOW REMOVE THE TAGS THAT NO LONGER APPLY
		$old_post_tag_ids = Group::get_groups_for_member($this->key, 'post_tag', false, 'ids');
		$tag_ids_removed = array_diff($old_post_tag_ids, $new_post_tag_ids);
		
		foreach($tag_ids_removed as $tag_id_removed){
			$group = new Group($tag_id_removed, true);
			$group->remove_member($this->key);	
		}
		
		return true;
				
	}	

	function load($debug = false) {
		parent::load();
		$this->data = SingleRowFetch('pst_posts', 'pst_post_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($this->data === NULL) {
			throw new PostException(
				'This post does not exist');
		}
	}
	
	function prepare() {
		
		//CHECK FOR DUPLICATES
		if($this->check_for_duplicate(array('pst_link'))){
			throw new SystemAuthenticationError(
					'This page link is a duplicate.');
		}

	}	
	
	
	function authenticate_write($session, $other_data=NULL) {
		$current_user = $session->get_user_id();
		if ($this->get('pst_usr_user_id') != $current_user) {
			// If the user's ID doesn't match , we have to make
			// sure they have admin access, otherwise denied.
			if ($session->get_permission() < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this post.');
			}
		}
	}

	function save($debug=false) {
		if ($this->key) {
			//SAVE THE OLD VERSION IN THE CONTENT_VERSION TABLE
			ContentVersion::NewVersion(ContentVersion::TYPE_POST, $this->key, $this->get('pst_body'), $this->get('pst_title'), $this->get('pst_title'));			
		}
		parent::save($debug);
	}
	
	function permanent_delete($debug=false){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		
		$this_transaction = false;
		if(!$dblink->inTransaction()){
			$dblink->beginTransaction();
			$this_transaction = true;
		}		
		
		//DELETE ANY GROUP MEMBERSHIPS
		$groups = Group::get_groups_in_category('post_tag');
		foreach($groups as $group){
			$group->remove_member($this->key);
		}
		
		parent::permanent_delete($debug);
		
		if($this_transaction){
			$dblink->commit();
		}	
		
		return true;
	}
	
}

class MultiPost extends SystemMultiBase {


	function get_post_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $post) {
			$items['('.$post->key.') '.$post->get('pst_title')] = $post->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	static function get_all_tags($return_type = 'name'){ 
		$tags = array();
		$groups = Group::get_groups_in_category('post_tag');

		foreach ($groups as $group){
			if($return_type == 'name'){
				$tags[] = $group->get('grp_name');
			}
			else{
				$tags[] = $group->key;
			}
		}	
		return array_unique($tags);
	}
	
	static function get_posts_for_tag($tag, $numperpage=NULL, $page_offset=NULL){ 
		$group = Group::get_by_name($tag, 'post_tag');

		if(!$group){
			return false;
		}
		
		$group_members = new MultiGroupMember(
			array('group_id' => $group->key),  //SEARCH CRITERIA
			array('group_member_id'=>'desc'),
			$numperpage,
			$page_offset
		);
		$group_members->load();

		$posts = new MultiPost;
		foreach ($group_members as $group_member){
			$post = new Post($group_member->get('grm_foreign_key_id'), TRUE);
			if(!$post->get('pst_delete_time') && $post->get('pst_is_on_homepage') && $post->get('pst_published_time')){ 
				$posts->add($post);
			}
		}	

		return $posts;
	}	

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('user_id', $this->options)) {
		 	$where_clauses[] = 'pst_usr_user_id = ?';
		 	$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		} 
		
		if (array_key_exists('link', $this->options)) {
			$where_clauses[] = 'pst_link = ?';
			$bind_params[] = array($this->options['link'], PDO::PARAM_STR);
		}			

		if (array_key_exists('published', $this->options)) {
		 	$where_clauses[] = 'pst_is_published = ' . ($this->options['published'] ? 'TRUE' : 'FALSE');
		}
	
		if (array_key_exists('pinned', $this->options)) {
		 	$where_clauses[] = 'pst_is_pinned = ' . ($this->options['pinned'] ? 'TRUE' : 'FALSE');
		}
	
		if (array_key_exists('listed', $this->options)) {
		 	$where_clauses[] = 'pst_is_on_homepage = ' . ($this->options['listed'] ? 'TRUE' : 'FALSE');
		}
		
		if (array_key_exists('deleted', $this->options)) {
			$where_clauses[] = 'pst_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
		}		
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM pst_posts ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM pst_posts
				' . $where_clause . '
				ORDER BY ';

			if (!$this->order_by) {
				$sql .= " pst_post_id ASC ";
			}
			else {
				if (array_key_exists('post_id', $this->order_by)) {
					$sql .= ' pst_post_id ' . $this->order_by['post_id'];
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
			$child = new Post($row->pst_post_id);
			$child->load_from_data($row, array_keys(Post::$fields));
			$this->add($child);
		}
	}

}


?>
