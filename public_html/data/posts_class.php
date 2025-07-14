<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/Globalvars.php');
$settings = Globalvars::get_instance();
PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');

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

	function prepare() {	
		//CHECK FOR DUPLICATES
		if($this->check_for_duplicate(array('pst_link'))){
			throw new SystemAuthenticationError(
					'This page link is a duplicate.');
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
		$groups = Group::get_groups_in_category('post_tag', false, 'objects');
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
	
	static function get_num_posts_for_tag($tag, $numperpage=NULL, $page_offset=NULL){ 
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
		return $group_members->count_all();
	}

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['user_id'])) {
			$filters['pst_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['link'])) {
			$filters['pst_link'] = [$this->options['link'], PDO::PARAM_STR];
		}

		if (isset($this->options['published'])) {
			$filters['pst_is_published'] = $this->options['published'] ? "= TRUE" : "= FALSE";
		}

		if (isset($this->options['pinned'])) {
			$filters['pst_is_pinned'] = $this->options['pinned'] ? "= TRUE" : "= FALSE";
		}

		if (isset($this->options['listed'])) {
			$filters['pst_is_on_homepage'] = $this->options['listed'] ? "= TRUE" : "= FALSE";
		}

		if (isset($this->options['deleted'])) {
			$filters['pst_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('pst_posts', $filters, $this->order_by, $only_count, $debug);
	}


	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new Post($row->pst_post_id);
			$child->load_from_data($row, array_keys(Post::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}

}


?>
