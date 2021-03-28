<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');	

class CommentException extends SystemClassException {}
class CommentNotSentException extends CommentException {};

class Comment extends SystemBase {


	public static $fields = array(
		'cmt_comment_id' => 'Comment id',
		'cmt_comment_id_parent' => 'Parent comment for threaded',
		'cmt_usr_user_id' => 'Comment author',
		'cmt_author_name' => 'Author name',
		'cmt_pst_post_id' => 'Post to attach to the comment',
		'cmt_body' => 'The comment',
		'cmt_created_time' => 'Time_sent',
		'cmt_is_approved' => 'Is it deleted',
		'cmt_delete_time' => 'Time of deletion',
	);

	public static $timestamp_fields = array(
		'usr_email_is_verified_time', 'usr_lastlogin_time', 'usr_admin_disabled_time',
		'usr_signup_date');

	public static $constants = array();

	public static $required = array(
		'cmt_body');

	public static $field_constraints = array(

	);
	
	public static $zero_variables = array(
				);
				
	public static $default_values = array(
		'cmt_created_time'=> 'now()',
		'cmt_is_approved' => TRUE,
	);
	
	function display_title(){
		if($this->get('cmt_body')){
			return substr(strip_tags($this->get('cmt_body')), 0, 100);
		}
		else{
			return '';
		}
	}
		
	
	function get_sanitized_comment(){
		$url = '~(?:(https?)://([^\s<]+)|(www\.[^\s<]+?\.[^\s<]+))(?<![\.,:])~i'; 
		$body = $this->get('cmt_body');
		$body = htmlspecialchars($body);
		$body = preg_replace($url, '<a href="$0" rel="nofollow" title="$0">$0</a>', $body);	
		return $body;
	}
	


	function load() {
		parent::load();
		$this->data = SingleRowFetch('cmt_comments', 'cmt_comment_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($this->data === NULL) {
			throw new CommentException(
				'This comment number does not exist');
		}
	}

	function prepare() {
		if ($this->data === NULL) {
			throw new CommentException('This comment has no data.');
		}
		
		if ($this->key === NULL) {
			foreach (static::$zero_variables as $variable) {
				if ($this->key === NULL && $this->get($variable) === NULL) {
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
	}	
	function authenticate_write($session, $other_data=NULL) {
		$current_user = $session->get_user_id();
		if ($this->get('cmt_usr_user_id') != $current_user) {
			// If the user's ID doesn't match, we have to make
			// sure they have admin access, otherwise denied.
			if ($session->get_permission() < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this comment.');
			}
		}
	}

	
	function save() {
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('cmt_comment_id' => $this->key);
			// Editing an existing
		} else {
			$p_keys = NULL;
			// Creating a new
			unset($rowdata['cmt_comment_id']);
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, 'cmt_comments', $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['cmt_comment_id'];
	}

	function soft_delete(){
		$this->set('cmt_delete_time', 'now()');
		$this->save();
		return true;
	}
	
	function undelete(){
		$this->set('cmt_delete_time', NULL);
		$this->save();	
		return true;
	}
	
	function permanent_delete(){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'DELETE FROM cmt_comments WHERE cmt_comment_id=:cmt_comment_id';
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':cmt_comment_id', $this->key, PDO::PARAM_INT);
			$count = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}
		
		$this->key = NULL;
		
		return true;		
	}
	
	static function add_comment($post_id, $session, $data){
		$settings = Globalvars::get_instance();
		if(!$session->get_user_id()){
			if(strlen($data['email'] > 0)){
				throw new SystemDisplayableError(
					'Please leave the "Extra email" field blank.');			
			}
			if(strlen($data['comment'] > 0)){
				throw new SystemDisplayableError(
					'Please leave the extra comment field blank.');			
			}		
		

			if(!FormWriterMaster::honeypot_check($data)){
				throw new SystemDisplayableError(
					'Please leave the "Extra email" field blank.');			
			}
			

			if(!FormWriterMaster::antispam_question_check($data, 'blog')){
				throw new SystemDisplayableError(
					'Please type the correct value into the anti-spam field.');			
			}
					
			
			
			$captcha_success = FormWriterMaster::captcha_check($data, 'blog');
			if (!$captcha_success) {
				$errormsg = 'Sorry, you must click the CAPTCHA to submit the form.';
				throw new SystemDisplayableError($errormsg);	
			}	
		}
			
		
		
		$comment = new Comment(NULL);  
		if($session->get_user_id()){
			$comment->set('cmt_usr_user_id', $session->get_user_id()); 
		}
		
		$safe_comment = strip_tags(iconv(mb_detect_encoding($data['cmt'], mb_detect_order(), true), "UTF-8", $data['cmt']));
		$safe_name = strip_tags(iconv(mb_detect_encoding($data['name'], mb_detect_order(), true), "UTF-8", $data['name']));
		
		$comment->set('cmt_pst_post_id', $post_id);
		$comment->set('cmt_author_name', $safe_name);
		$comment->set('cmt_body', $safe_comment);
		if($settings->get_setting('default_comment_status') == 'approved'){
			$comment->set('cmt_is_approved', TRUE);
		}
		else{
			$comment->set('cmt_is_approved', FALSE);
		}
		$comment->prepare();	
		$comment->save();	

		return $comment;
	}
		

	static function InitDB($mode='structure'){
	
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS cmt_comments_cmt_comment_id_seq
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
			CREATE TABLE IF NOT EXISTS "public"."cmt_comments" (
			  "cmt_comment_id" int4 NOT NULL DEFAULT nextval(\'cmt_comments_cmt_comment_id_seq\'::regclass),
			  "cmt_comment_id_parent" int4,
			  "cmt_usr_user_id" int4,
			  "cmt_pst_post_id" int4 NOT NULL,
			  "cmt_body" text COLLATE "pg_catalog"."default",
			  "cmt_created_time" timestamp(6),
			  "cmt_is_approved" bool DEFAULT false,
			  "cmt_author_name" varchar(255) COLLATE "pg_catalog"."default",
			  "cmt_delete_time" timestamp(6)
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."cmt_comments" ADD CONSTRAINT "cmt_comments_pkey" PRIMARY KEY ("cmt_comment_id");';
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

class MultiComment extends SystemMultiBase {

	private function _get_results($only_count=FALSE) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('author_id', $this->options)) {
			$where_clauses[] = 'cmt_usr_user_id = ?';
			$bind_params[] = array($this->options['author_id'], PDO::PARAM_INT);
		}
	
		if (array_key_exists('approved', $this->options)) {
			$where_clauses[] = 'cmt_is_approved = ' . ($this->options['approved'] ? 'TRUE' : 'FALSE');
		}	

		if (array_key_exists('deleted', $this->options)) {
			$where_clauses[] = 'cmt_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
		}	
		
		if (array_key_exists('post_id', $this->options)) {
			$where_clauses[] = 'cmt_pst_post_id = ?';
			$bind_params[] = array($this->options['post_id'], PDO::PARAM_INT);
		}		
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM cmt_comments ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM cmt_comments
				' . $where_clause . '
				ORDER BY ';

			if (!$this->order_by) {
				$sql .= " cmt_comment_id ASC ";
			}
			else {
				if (array_key_exists('comment_id', $this->order_by)) {
					$sql .= ' cmt_comment_id ' . $this->order_by['comment_id'];
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
			$child = new Comment($row->cmt_comment_id);
			$child->load_from_data($row, array_keys(Comment::$fields));
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
