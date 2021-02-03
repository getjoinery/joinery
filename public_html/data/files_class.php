<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FieldConstraints.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SingleRowAccessor.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Validator.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_sessions_class.php');

class FileException extends SystemClassException {}

class File extends SystemBase {

	public static $fields = array(
		'fil_file_id' => 'ID of the file',
		'fil_name' => 'Name',
		'fil_title' => 'Human readable title',
		'fil_description' => 'Description',
		'fil_type' => 'Type',
		'fil_usr_user_id' => 'User who uploaded',
		'fil_create_time' => 'Created',
		'fil_is_deleted' => 'Is this file deleted?',
	);
	
	public static function get_by_name($name) {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		//SET ALL DEFAULT FOR THIS USER TO ZERO
		$sql = "SELECT fil_file_id FROM fil_files
			WHERE fil_name = :fil_name";

		try{
			$q = $dblink->prepare($sql);
			$q->bindValue(':fil_name', $name, PDO::PARAM_STR);
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}

		if (!$q->rowCount()) {
			//throw new AddressException('This user doesn\'t have a default address.');
			return FALSE;
		}

		$r = $q->fetch();

		return $r->fil_file_id;
	}	
	
	function get_name() {
		if($this->get('fil_title')){
			return $this->get('fil_title');
		}
		else{
			return $this->get('fil_name');
		}
	}	
	
	function is_image(){
		if (strpos($this->get('fil_type'), 'image/') !== false) {
			return true;
		}
		else{
			return false;
		}
	}
	
	function get_url($size='standard') {
		$settings = Globalvars::get_instance();
		$upload_web_dir = $settings->get_setting('upload_web_dir');
		
		if($size == 'thumbnail'){
			$file_path = $upload_web_dir.'/thumbnail/'.$this->get('fil_name');
		}
		if($size == 'small'){
			$file_path = $upload_web_dir.'/small/'.$this->get('fil_name');
		}		
		if($size == 'medium'){
			$file_path = $upload_web_dir.'/medium/'.$this->get('fil_name');
		}	
		if($size == 'large'){
			$file_path = $upload_web_dir.'/large/'.$this->get('fil_name');
		}
		if($size == 'standard'){
			$file_path = $upload_web_dir.'/'.$this->get('fil_name');
		}		
		return $file_path;
	}	

	function permanent_delete(){
		$settings = Globalvars::get_instance();
		$upload_dir = $settings->get_setting('upload_dir');
		
		$file_path = $upload_dir.'/'.$this->get('fil_name');
		if (!unlink($file_path)) { 		
			//echo ("$file_path cannot be deleted due to an error");
			//return false;
		}  
		
		$this->delete_resized();
		
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		
		$q = $dblink->prepare('DELETE FROM fil_files WHERE fil_file_id=?');
		$q->bindValue(1, $this->key, PDO::PARAM_INT);
		$q->execute();	
		
		$q = $dblink->prepare('DELETE FROM esf_event_session_files WHERE esf_fil_file_id=?');
		$q->bindValue(1, $this->key, PDO::PARAM_INT);
		$q->execute();			
		
		$this->key = NULL;
		return true;
					
	}
	
	function delete_resized(){
		if (!$this->is_image()) {
			return false;
		}
		
		$settings = Globalvars::get_instance();
		$upload_dir = $settings->get_setting('upload_dir');
		
		$file_path = $upload_dir.'/small/'.$this->get('fil_name');
		if (!unlink($file_path)) { 		
			//echo ("$file_path cannot be deleted due to an error");  
			//return false;
		}  

		
		$file_path = $upload_dir.'/medium/'.$this->get('fil_name');
		if (!unlink($file_path)) { 		
			//echo ("$file_path cannot be deleted due to an error");
			//return false;			
		}  
 

		$file_path = $upload_dir.'/large/'.$this->get('fil_name');
		if (!unlink($file_path)) { 		
			//echo ("$file_path cannot be deleted due to an error");
			//return false;			
		}  

		$file_path = $upload_dir.'/thumbnail/'.$this->get('fil_name');
		if (!unlink($file_path)) { 		
			//echo ("$file_path cannot be deleted due to an error");
			//return false;			
		} 		
		
	}
	
	function resize(){
		$settings = Globalvars::get_instance();
		$upload_dir = $settings->get_setting('upload_dir');

		if ($this->is_image()) {
		
			//RESIZE THE PICTURE
			$old_path = $upload_dir.'/'.$this->get('fil_name');
		

			//THUMBNAIL SIZE
			
			try
			{
				$img = new Imagick($old_path);
				$new_path = '/var/www/html/uploads/thumbnail/'.$this->get('fil_name');
				$img->thumbnailImage(80 , 80 , TRUE);
				$img->writeImage($new_path);
				
			}
			catch(Exception $e)
			{
				echo 'Caught exception: ',  $e->getMessage(), '\n';
			}	
			
			
			//SMALL SIZE
			try
			{
				$img = new Imagick($old_path);
				$new_path = $upload_dir.'/small/'.$this->get('fil_name');
				$img->thumbnailImage(500 , 300 , TRUE);
				$img->writeImage($new_path);
				
			}
			catch(Exception $e)
			{
				echo 'Caught exception: ',  $e->getMessage(), '\n';
			}		
			
			//MEDIUM SIZE
			try
			{
				$img = new Imagick($old_path);
				$new_path = $upload_dir.'/medium/'.$this->get('fil_name');
				$img->thumbnailImage(800 , 600 , TRUE);
				$img->writeImage($new_path);
				
			}
			catch(Exception $e)
			{
				echo 'Caught exception: ',  $e->getMessage(), '\n';
			}			

			//LARGE SIZE
			try
			{
				$img = new Imagick($old_path);
				$new_path = $upload_dir.'/large/'.$this->get('fil_name');
				$img->thumbnailImage(1200 , 1000 , TRUE);
				$img->writeImage($new_path);
				
			}
			catch(Exception $e)
			{
				echo 'Caught exception: ',  $e->getMessage(), '\n';
			}		
		}
		else{
			return false;
		}
	}
	
	function get_event_sessions(){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		
		$q = $dblink->prepare('SELECT esf_evs_event_session_id FROM esf_event_session_files WHERE esf_fil_file_id=?');
		$q->bindValue(1, $this->key, PDO::PARAM_INT);
		$q->execute();
		
		$results = $q->fetchAll();
		
		//CONVERT INTO A MULTI OBJECT
		$event_sessions = new MultiEventSessions();
		foreach ($results as $result){
			$event_session = new EventSession($result['esf_evs_event_session_id'], TRUE);	
			$event_sessions->add($event_session);
		}
		
		return $event_sessions;
	}		


	function load() {
		parent::load();
		$this->data = SingleRowFetch('fil_files', 'fil_file_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($this->data === NULL) {
			throw new FileException(
				'This file does not exist');
		}
	}
	
	
	function authenticate_write($session, $other_data=NULL) {
		$current_user = $session->get_user_id();
		if ($this->get('fil_usr_user_id') != $current_user) {
			// If the user's ID doesn't match, we have to make
			// sure they have admin access, otherwise denied.
			if ($session->get_permission() < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this file.');
			}
		}
	}

	function save() {
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('fil_file_id' => $this->key);
			// Editing an existing record
		} else {
			$p_keys = NULL;
			// Creating a new record
			unset($rowdata['fil_file_id']);
			$rowdata['fil_create_time'] = 'now()';
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, 'fil_files', $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['fil_file_id'];
	}
	
	static function InitDB($mode='structure'){
	
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS fil_files_fil_file_id_seq
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
			CREATE TABLE IF NOT EXISTS "public"."fil_files" (
			  "fil_file_id" int4 NOT NULL DEFAULT nextval(\'fil_files_fil_file_id_seq\'::regclass),
			  "fil_name" varchar(255) COLLATE "pg_catalog"."default",
			  "fil_type" varchar(128) COLLATE "pg_catalog"."default",
			  "fil_usr_user_id" int4,
			  "fil_create_time" timestamp(6) DEFAULT now(),
			  "fil_is_deleted" bool NOT NULL DEFAULT false,
			  "fil_title" varchar(255) COLLATE "pg_catalog"."default",
			  "fil_description" text COLLATE "pg_catalog"."default"
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."fil_files" ADD CONSTRAINT "fil_files_pkey" PRIMARY KEY ("fil_file_id");';
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

class MultiFile extends SystemMultiBase {

	function get_file_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $file) {
			$items['('.$file->key.') '.$file->get('fil_title')] = $file->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;
	}	

	function get_image_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $file) {
			$items['<span class="dropimagewidth"><img src="'.$file->get_url('thumbnail').'"></span>('.$file->key.') '.$file->get('fil_title')] = $file->key;
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
		 	$where_clauses[] = 'fil_usr_user_id = ?';
		 	$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		} 
		if (array_key_exists('deleted', $this->options)) {
		 	$where_clauses[] = 'fil_is_deleted = ' . ($this->options['deleted'] ? 'TRUE' : 'FALSE');
		} 

		if (array_key_exists('source', $this->options)) {
		 	$where_clauses[] = 'fil_source = ?';
		 	$bind_params[] = array($this->options['source'], PDO::PARAM_INT);
		} 	
		
		if (array_key_exists('picture', $this->options)) {
		 	$where_clauses[] = '(fil_type = \'image/jpeg\' OR fil_type = \'image/jpg\' OR fil_type = \'image/png\' OR fil_type = \'image/gif\')';
		} 	

		if (array_key_exists('filename_like', $this->options)) {
			$where_clauses[] = 'fil_name ILIKE ?';
			$bind_params[] = array('%'.$this->options['filename_like'].'%', PDO::PARAM_STR);
		}		 
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM fil_files ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM fil_files
				' . $where_clause . '
				ORDER BY ';

			if (!$this->order_by) {
				$sql .= " fil_file_id ASC ";
			}
			else {
				if (array_key_exists('file_id', $this->order_by)) {
					$sql .= ' fil_file_id ' . $this->order_by['file_id'];
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
			$child = new File($row->fil_file_id);
			$child->load_from_data($row, array_keys(File::$fields));
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
