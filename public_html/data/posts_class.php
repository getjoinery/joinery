<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
$settings = Globalvars::get_instance();
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

require_once(PathHelper::getIncludePath('data/content_versions_class.php'));
require_once(PathHelper::getIncludePath('data/groups_class.php'));

class PostException extends SystemBaseException {}

class Post extends SystemBase {	public static $prefix = 'pst';
	public static $tablename = 'pst_posts';
	public static $pkey_column = 'pst_post_id';
	public static $url_namespace = 'post';  //SUBDIRECTORY WHERE ITEMS ARE LOCATED EXAMPLE: DOMAIN.COM/URL_NAMESPACE/THIS_ITEM

	protected static $foreign_key_actions = [
		'pst_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
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
	    'pst_post_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'pst_title' => array('type'=>'varchar(255)'),
	    'pst_link' => array('type'=>'varchar(255)'),
	    'pst_usr_user_id' => array('type'=>'int4', 'required'=>true),
	    'pst_body' => array('type'=>'text'),
	    'pst_is_published' => array('type'=>'bool'),
	    'pst_published_time' => array('type'=>'timestamp(6)'),
	    'pst_is_on_homepage' => array('type'=>'bool', 'default'=>true),
	    'pst_is_pinned' => array('type'=>'bool'),
	    'pst_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'pst_short_description' => array('type'=>'varchar(255)'),
	    'pst_delete_time' => array('type'=>'timestamp(6)'),
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
	protected static $model_class = 'Post';

	function get_post_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $post) {
			$items[$post->key] = '('.$post->key.') '.$post->get('pst_title');
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

}

?>
