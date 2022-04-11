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
	public $prefix = 'pst';
	public $tablename = 'pst_posts';
	public $pkey_column = 'pst_post_id';
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
		'pst_create_time' => 'Time Created',
		'pst_short_description' => 'Short description, no html, max 255 chars',
		'pst_delete_time' => 'Time of deletion',
	);


	public static $required_fields = array();

	public static $field_constraints = array();	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
	'pst_create_time' => 'now()', 
	'pst_is_on_homepage' => true
	);	

	static function check_if_exists($key) {
		$data = SingleRowFetch('pst_posts', 'pst_post_id',
			$key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($data === NULL) {
			return FALSE;
		}
		else{
			return TRUE;
		}
	}
	
	static function get_by_link($link){
		$results = new MultiPost(array('link' => $link, 'deleted'=>false));
		$numresult = $results->count_all();

		if($numresult){
			$results->load();
			return $results->get(0);
		}
		else{
			return false;
		}

	}
	
	public function check_for_duplicate_link($link) {
		$results = new MultiPost(array('link' => $link));
		$results->load();

		if(count($results) > 1){
			return true;	
		}
		else if(count($results) == 1){
			$result = $results->get(0); 
			if($result->key == $this->key){
				return false;
			}
			else{
				return true;
			}
		}
		else{
			return false;
		}
	}	

	function get_url(){ 
		$settings = Globalvars::get_instance();
		$blog_subdirectory = $settings->get_setting('blog_subdirectory');
		if($blog_subdirectory){
			return '/'.$blog_subdirectory.'/'.$this->get('pst_link');
		}
		else{
			return $this->get('pst_link');
		}
		
	}		
	
	function get_tags($return_type = 'name'){ 
		$tags = array();
		$group_members = new MultiGroupMember(
			array('post_id' => $this->key),  //SEARCH CRITERIA
		);
		$group_members->load();

		foreach ($group_members as $group_member){
			$group = new Group($group_member->get('grm_grp_group_id'), TRUE);
			if($return_type == 'name'){
				$tags[] = $group->get('grp_name');
			}
			else{
				$tags[] = $group->key;
			}
		}	
		return $tags;
	}	

	
	function save_tags($tags_array){
		if(empty($tags_array)){
			return false;
		}
		
		$session = SessionControl::get_instance();

		//OLD TAGS
		$post_tag_ids = $this->get_tags('id');
		foreach ($post_tag_ids as $post_tag_id){
			$group = new Group($post_tag_id, TRUE);
			$group->remove_member(NULL, NULL, $this->key);
		}		
		
		//NEW TAGS
		foreach ($tags_array as $tag){
			$tag = trim($tag);
			$tag = preg_replace("/[^A-Za-z0-9 -_]/", '', $tag);
			
			if(!$group = Group::get_by_name($tag)){
				$group = Group::add_group($tag, $session->get_user_id(), Group::GROUP_TYPE_POST_TAG);
			}
			$group->add_member(NULL, NULL, $this->key);
		}		
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
		if($this->check_for_duplicate_link($this->get('pst_link'))){
			throw new SystemAuthenticationError(
					'This page link is a duplicate.');
		}
		
		if(!$this->get('pst_link')){
			$tmp_link = '';
			$tmp_link .= '/'. date('Y') . '/' . date('m') . '/' . date('d') . '/';
			$tmp_link .= strtolower(preg_replace('![^a-z0-9]+!i','-',$this->get('pst_title')));

			if($this->check_for_duplicate_link($tmp_link)){
				$tmp_link .= '-1';
				if($this->check_for_duplicate_link($tmp_link)){
					throw new SystemAuthenticationError(
							'This page link is a duplicate.  Try a new title.');
				}
			}
			$this->set('pst_link', $tmp_link);
			
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

	function save() {
		if ($this->key) {
			//SAVE THE OLD VERSION IN THE CONTENT_VERSION TABLE
			ContentVersion::NewVersion(ContentVersion::TYPE_POST, $this->key, $this->get('pst_body'), $this->get('pst_title'), $this->get('pst_title'));			
		}
		parent::save();
	}
	
	
	static function InitDB($mode='structure'){
	
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS pst_posts_pst_post_id_seq
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
			CREATE TABLE IF NOT EXISTS "public"."pst_posts" (
			  "pst_post_id" int4 NOT NULL DEFAULT nextval(\'pst_posts_pst_post_id_seq\'::regclass),
			  "pst_usr_user_id" int4 NOT NULL,
			  "pst_link" varchar(255) COLLATE "pg_catalog"."default",
			  "pst_title" varchar(255) COLLATE "pg_catalog"."default",
			  "pst_body" text COLLATE "pg_catalog"."default",
			  "pst_published_time" timestamp(6),
			  "pst_is_published" bool DEFAULT true,
			  "pst_is_on_homepage" bool DEFAULT true,
			  "pst_create_time" timestamp(6),
			  "pst_short_description" varchar(255) COLLATE "pg_catalog"."default",
			  "pst_delete_time" timestamp(6)
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."pst_posts" ADD CONSTRAINT "pst_posts_pkey" PRIMARY KEY ("pst_post_id");';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}

		
		try{		
			$sql = 'CREATE INDEX CONCURRENTLY pst_posts_pst_link ON pst_posts USING HASH (pst_link);';
			$q = $dburl->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}
	
		//FOR FUTURE
		//ALTER TABLE table_name ADD COLUMN IF NOT EXISTS column_name INTEGER;
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
		$group_members = new MultiGroupMember(
			array('has_post_id' => TRUE),  //SEARCH CRITERIA
		);
		$group_members->load();

		foreach ($group_members as $group_member){
			$group = new Group($group_member->get('grm_grp_group_id'), TRUE);
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
		$group = Group::get_by_name($tag);
		if(!$group){
			return false;
		}
		
		$group_members = new MultiGroupMember(
			array('group_id' => $group->key),  //SEARCH CRITERIA
			array('post_id'=>'desc'),
			$numperpage,
			$page_offset
		);
		$group_members->load();

		$posts = new MultiPost;
		foreach ($group_members as $group_member){
			$post = new Post($group_member->get('grm_pst_post_id'), TRUE);
			$posts->add($post);
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
		
		if (array_key_exists('deleted', $this->options)) {
			$where_clauses[] = 'pst_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
		}		
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM pst_posts ' . $where_clause;
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
		$q = $this->_get_results();
		foreach($q->fetchAll() as $row) {
			$child = new Post($row->pst_post_id);
			$child->load_from_data($row, array_keys(Post::$fields));
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
