<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FieldConstraints.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SingleRowAccessor.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Validator.php');

class UrlException extends SystemClassException {}

class Url extends SystemBase {

	public static $fields = array(
		'url_url_id' => 'ID of the url',
		'url_incoming' => 'Incoming url',
		'url_redirect_url' => 'Url to redirect to',
		'url_redirect_file' => 'File to load',
		'url_type' => 'Type of redirect - 301, 302, etc',
		'url_create_time' => 'Time added'
	);

	function load() {
		parent::load();
		$this->data = SingleRowFetch('url_urls', 'url_url_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($this->data === NULL) {
			throw new UrlException(
				'This url does not exist');
		}
	}
	
	function get_type_text() {
		if($this->get('url_type') == 301){
			return 'HTTP/1.1 301 Moved Permanently';
		}
		else if($this->get('url_type') == 302){
			return 'HTTP/1.1 302 Found';
		}		
	}
	
	
	function authenticate_write($session, $other_data=NULL) {
		$current_user = $session->get_user_id();
		if ($this->get('url_usr_user_id') != $current_user) {
			// If the user's ID doesn't match , we have to make
			// sure they have admin access, otherwise denied.
			if ($session->get_permission() < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this url.');
			}
		}
	}

	function save() {
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('url_url_id' => $this->key);
			// Editing an existing 
		} else {
			$p_keys = NULL;
			// Creating a new 
			unset($rowdata['url_url_id']);
			$rowdata['url_create_time'] = 'now()';
		}

		$dbhelper = DbConnector::get_instance();
		$dburl = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dburl, 'url_urls', $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['url_url_id'];
	}
		
	

	function permanent_delete() {
		
		$dbhelper = DbConnector::get_instance(); 
		$dburl = $dbhelper->get_db_link();
		//$dburl->beginTransaction();

		$sql = 'DELETE FROM url_urls WHERE url_url_id=:url_url_id';

		try{
			$q = $dburl->prepare($sql);
			$q->bindParam(':url_url_id', $this->key, PDO::PARAM_INT);
			$count = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}	
		
		//$dburl->commit();
		
		$this->key = NULL;

		return TRUE;
		
	}	

	static function InitDB($mode='structure'){
	
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS url_urls_url_url_id_seq
				INCREMENT BY 1
				NO MAXVALUE
				NO MINVALUE
				CACHE 1;';
			$q = $dburl->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}			
		
		$sql = '
			CREATE TABLE IF NOT EXISTS "public"."url_urls" (
			  "url_url_id" int4 NOT NULL DEFAULT nextval(\'url_urls_url_url_id_seq\'::regclass),
			  "url_incoming" varchar(255) COLLATE "pg_catalog"."default",
			  "url_redirect_url" varchar(255) COLLATE "pg_catalog"."default",
			  "url_redirect_file" varchar(255) COLLATE "pg_catalog"."default",
			  "url_type" int2,
			  "url_create_time" timestamp(6) DEFAULT now()
			)
			;';
		$q = $dburl->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."url_urls" ADD CONSTRAINT "url_urls_pkey" PRIMARY KEY ("url_url_id");';
			$q = $dburl->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}
		
		try{		
			$sql = 'CREATE INDEX CONCURRENTLY url_url_url_incoming ON url_urls USING HASH (url_incoming);';
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

class MultiUrl extends SystemMultiBase {

	private function _get_results($only_count=FALSE) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('incoming', $this->options)) {
			$where_clauses[] = 'url_incoming = ?';
			$bind_params[] = array($this->options['incoming'], PDO::PARAM_INT);
		}
	
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}


		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM url_urls ' . $where_clause;
		} else {
			$sql = 'SELECT * FROM url_urls
				' . $where_clause . '
				ORDER BY ';
			
			if (!$this->order_by) {
				$sql .= " url_url_id ASC ";
			}
			else {
				if (array_key_exists('url_id', $this->order_by)) {
					$sql .= ' url_url_id ' . $this->order_by['url_id'];
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
			$child = new Url($row->url_url_id);
			$child->load_from_data($row, array_keys(Url::$fields));
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
