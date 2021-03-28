<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FieldConstraints.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SingleRowAccessor.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Validator.php');

class PageContentException extends SystemClassException {}

class PageContent extends SystemBase {

	public static $fields = array(
		'pac_page_content_id' => 'ID of the page_content',
		'pac_location_name' => 'Location of the content',
		'pac_title' => 'PageContent Title',
		'pac_link' => 'Link of the page_content, if it is a standalone page',
		'pac_usr_user_id' => 'User this page_content is associated with',
		'pac_body' => 'Body of the page_content',
		'pac_is_published' => 'Is this page_content published?',
		'pac_published_time' => 'Time published',
		'pac_create_time' => 'Time Created',
		'pac_script_filename' => 'Filename to look for if we want to run a script before rendering',
		'pac_delete_time' => 'Time of deletion',
	);

	public static $constants = array();

	public static $required = array(
		);

	public static $field_constraints = array();	
	
	public static $zero_variables = array();
	
	public static $default_values = array(
	'pac_create_time' => 'now()', 'pac_is_published' => FALSE
	);	
	
	static function get_by_link($link){
		$results = new MultiPageContent(array('link' => $link, 'deleted'=>false));
		$results->load();

		if(count($results)){	
			return $results->get(0);	
		}
		else{
			return false;
		}
	}
	
	function get_filled_content(){

		//LOOK FOR THE SCRIPT FILE AND REPLACE CONTENT PLACEHOLDERS {{}}
		if($this->get('pac_script_filename')){
			$logic_path = LibraryFunctions::get_logic_file_path($this->get('pac_script_filename'));
			require_once ($logic_path);

			$content_out = $this->get('pac_body');
			
			foreach($replace_values as $var=>$val){
				$content_out = str_replace('{{'.$var.'}}', $val, $content_out);
			}

			return $content_out;
		}
		else{
			return $this->get('pac_body');
		}
	}	
	
	public function check_for_duplicate_link($link) {
		if(!$link){
			//EMPTY LINK IS OK
			return false;
		}
		$results = new MultiPageContent(array('link' => $link));
		$numresults = $results->count_all();


		if($numresults > 1){
			return true;	
		}
		else if($numresults == 1){
			$results->load();
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

	static function check_if_exists($key) {
		$data = SingleRowFetch('pac_page_contents', 'pac_page_content_id',
			$key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($data === NULL) {
			return FALSE;
		}
		else{
			return TRUE;
		}
	}

	function load() {
		parent::load();
		$this->data = SingleRowFetch('pac_page_contents', 'pac_page_content_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($this->data === NULL) {
			throw new PageContentException(
				'This page_content does not exist');
		}
	}
	
	function prepare() {
		if ($this->data === NULL) {
			throw new PageContentException('This has no data.');
		}
		
		//CHECK FOR DUPLICATES
		if($this->check_for_duplicate_link($this->get('pac_link'))){
			throw new SystemAuthenticationError(
					'This page link is a duplicate.');
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
	
	
	function authenticate_write($session, $other_data=NULL) {
		$current_user = $session->get_user_id();
		if ($this->get('pac_usr_user_id') != $current_user) {
			// If the user's ID doesn't match , we have to make
			// sure they have admin access, otherwise denied.
			if ($session->get_permission() < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this page_content.');
			}
		}
	}

	function save() {
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('pac_page_content_id' => $this->key);
			// Editing an existing record
			
			//SAVE THE OLD VERSION IN THE CONTENT_VERSION TABLE
			ContentVersion::NewVersion(ContentVersion::TYPE_PAGE_CONTENT, $this->key, $this->get('pac_body'), $this->get('pac_title'), $this->get('pac_title'));
		} else {
			$p_keys = NULL;
			// Creating a new record
			unset($rowdata['pac_page_content_id']);
			$rowdata['pac_create_time'] = 'now()';
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, 'pac_page_contents', $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['pac_page_content_id'];
	}

	function soft_delete(){
		$this->set('pac_delete_time', 'now()');
		$this->save();
		return true;
	}
	
	function undelete(){
		$this->set('pac_delete_time', NULL);
		$this->save();	
		return true;
	}
	
	function permanent_delete(){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'DELETE FROM pac_page_contents WHERE pac_page_content_id=:pac_page_content_id';
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':pac_page_content_id', $this->key, PDO::PARAM_INT);
			$count = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}
		
		$this->key = NULL;
		
		return true;		
	}
	
	static public function GetPublicActions() { 
		return self::$public_actions;
	}
	
	static function InitDB($mode='structure'){
	
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS pac_page_contents_pac_page_content_id_seq
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
			CREATE TABLE IF NOT EXISTS "public"."pac_page_contents" (
			  "pac_page_content_id" int4 NOT NULL DEFAULT nextval(\'pac_page_contents_pac_page_content_id_seq\'::regclass),
			  "pac_usr_user_id" int4,
			  "pac_location_name" varchar COLLATE "pg_catalog"."default",
			  "pac_title" varchar COLLATE "pg_catalog"."default",
			  "pac_link" varchar COLLATE "pg_catalog"."default",
			  "pac_body" text COLLATE "pg_catalog"."default",
			  "pac_published_time" timestamp(6),
			  "pac_create_time" timestamp(6),
			  "pac_is_published" bool,
			  "pac_script_filename" varchar COLLATE "pg_catalog"."default",
			  "pac_delete_time" timestamp(6)
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."pac_page_contents" ADD CONSTRAINT "pac_page_contents_pkey" PRIMARY KEY ("pac_page_content_id");';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}

		
		try{		
			$sql = 'CREATE INDEX CONCURRENTLY pac_page_contents_pac_link ON pac_page_contents USING HASH (pac_link);';
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

class MultiPageContent extends SystemMultiBase {


	function get_page_content_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $page_content) {
			$items['('.$page_content->key.') '.$page_content->get('pac_title')] = $page_content->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	private function _get_results($only_count=FALSE) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('user_id', $this->options)) {
		 	$where_clauses[] = 'pac_usr_user_id = ?';
		 	$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		} 
		
		if (array_key_exists('link', $this->options)) {
			$where_clauses[] = 'pac_link = ?';
			$bind_params[] = array($this->options['link'], PDO::PARAM_STR);
		}

		if (array_key_exists('has_link', $this->options)) {
			$where_clauses[] = 'LENGTH(pac_link) > 0';
		}
		
		if (array_key_exists('deleted', $this->options)) {
			$where_clauses[] = 'pac_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
		}	
		
		if (array_key_exists('published', $this->options)) {
		 	$where_clauses[] = 'pac_is_published = ' . ($this->options['published'] ? 'TRUE' : 'FALSE');
		} 		
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM pac_page_contents ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM pac_page_contents
				' . $where_clause . '
				ORDER BY ';

			if (!$this->order_by) {
				$sql .= " pac_page_content_id ASC ";
			}
			else {
				if (array_key_exists('page_content_id', $this->order_by)) {
					$sql .= ' pac_page_content_id ' . $this->order_by['page_content_id'];
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
			$child = new PageContent($row->pac_page_content_id);
			$child->load_from_data($row, array_keys(PageContent::$fields));
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
