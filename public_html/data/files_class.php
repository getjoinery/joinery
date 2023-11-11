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

require_once($siteDir . '/data/event_sessions_class.php');

class FileException extends SystemClassException {}

class File extends SystemBase {
	public static $prefix = 'fil';
	public static $tablename = 'fil_files';
	public static $pkey_column = 'fil_file_id';
	public static $permanent_delete_actions = array(
		'fil_file_id' => 'delete',	
		'esf_fil_file_id' => 'prevent',
		'evt_fil_file_id' => 'prevent',
		'mlt_fil_file_id' => 'null'
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'fil_file_id' => 'ID of the file',
		'fil_name' => 'Name',
		'fil_title' => 'Human readable title',
		'fil_description' => 'Description',
		'fil_type' => 'Type',
		'fil_usr_user_id' => 'User who uploaded',
		'fil_create_time' => 'Created',
		'fil_delete_time' => 'Time of file deletion',
		'fil_gal_gallery_id' => 'Gallery this file is part of TODO',
		'fil_min_permission' => 'Permission level required to view file',
		'fil_grp_group_id' => 'Group with permission to see file',
		'fil_evt_event_id' => 'Event registrants with permission to see file',
	);

	public static $field_specifications = array(
		'fil_file_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'fil_name' => array('type'=>'varchar(255)'),
		'fil_title' => array('type'=>'varchar(255)'),
		'fil_description' => array('type'=>'text'),
		'fil_type' => array('type'=>'varchar(128)'),
		'fil_usr_user_id' => array('type'=>'int4'),
		'fil_create_time' => array('type'=>'timestamp(6)'),
		'fil_delete_time' => array('type'=>'timestamp(6)'),
		'fil_gal_gallery_id' => array('type'=>'int4'),
		'fil_min_permission' => array('type'=>'int2'),
		'fil_grp_group_id' => array('type'=>'int4'),
		'fil_evt_event_id' => array('type'=>'int4'),
	);
			  
	public static $required_fields = array();
	
	public static $field_constraints = array();
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
	'fil_create_time'=> 'now()','fil_min_permission' => null);
	
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

		return new File($r->fil_file_id, TRUE);
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
	
	
	//TAKES A SIZE ARGUMENT, AND ALSO EITHER 'SHORT' OR 'FULL'
	function get_url($size='standard', $format='short') {
		
		
		$settings = Globalvars::get_instance();
		$upload_web_dir = $settings->get_setting('upload_web_dir');
		$url_append = '';
		if($format == 'full'){
			$url_append =$settings->get_setting('webDir');
		}
		
		if($size == 'thumbnail'){
			$file_path = $upload_web_dir.'/thumbnail/'.$this->get('fil_name');
		}
		if($size == 'lthumbnail'){
			$file_path = $upload_web_dir.'/lthumbnail/'.$this->get('fil_name');
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
		//IF NO LEADING SLASH, ADD A SLASH
		if($file_path[0] == '/'){
			$file_path = $file_path;
		}
		else{
			$file_path = '/'.$file_path;
		}
		
		return $url_append . $file_path;
		
	}	

	function permanent_delete($debug=false){
		$settings = Globalvars::get_instance();
		$upload_dir = $settings->get_setting('upload_dir');
		
		$file_path = $upload_dir.'/'.$this->get('fil_name');
		if (!unlink($file_path)) { 		
			//echo ("$file_path cannot be deleted due to an error");
			//return false;
		}  
		
		$this->delete_resized();
		
		parent::permanent_delete($debug);
		return true;		
	}
	
	//SIZE CAN BE thumbnail, lthumbnail, small, medium, large
	function delete_resized($size = 'all'){
		if (!$this->is_image()) {
			return false;
		}
		
		$settings = Globalvars::get_instance();
		$upload_dir = $settings->get_setting('upload_dir');
		
		if($size == 'all' || $size == 'small'){
			$file_path = $upload_dir.'/small/'.$this->get('fil_name');
			if (!unlink($file_path)) { 		
				//echo ("$file_path cannot be deleted due to an error");  
				//return false;
			}  
		}
		
		if($size == 'all' || $size == 'medium'){
			$file_path = $upload_dir.'/medium/'.$this->get('fil_name');
			if (!unlink($file_path)) { 		
				//echo ("$file_path cannot be deleted due to an error");
				//return false;			
			}  
 		}
		
		if($size == 'all' || $size == 'large'){
			$file_path = $upload_dir.'/large/'.$this->get('fil_name');
			if (!unlink($file_path)) { 		
				//echo ("$file_path cannot be deleted due to an error");
				//return false;			
			}  
		}
		if($size == 'all' || $size == 'thumbnail'){
			$file_path = $upload_dir.'/thumbnail/'.$this->get('fil_name');
			if (!unlink($file_path)) { 		
				//echo ("$file_path cannot be deleted due to an error");
				//return false;			
			} 		
		}	

		if($size == 'all' || $size == 'lthumbnail'){
			$file_path = $upload_dir.'/lthumbnail/'.$this->get('fil_name');
			if (!unlink($file_path)) { 		
				//echo ("$file_path cannot be deleted due to an error");
				//return false;			
			} 		
		}		
	}
	
	//SIZE CAN BE thumbnail, lthumbnail, small, medium, large
	function resize($size='all'){
		$settings = Globalvars::get_instance();
		$upload_dir = $settings->get_setting('upload_dir');

		if ($this->is_image()) {
		
			//RESIZE THE PICTURE
			$old_path = $upload_dir.'/'.$this->get('fil_name');
		

			//THUMBNAIL SIZE
			if($size == 'all' || $size == 'thumbnail'){
				try
				{
					$width = 80;
					$height = 80;
					$img = new Imagick($old_path);
					$new_path = $upload_dir.'/thumbnail/'.$this->get('fil_name');
					
					// get the current image dimensions
					$geo = $img->getImageGeometry();

					// crop the image
					if(($geo['width']/$width) < ($geo['height']/$height))
					{
						$img->cropImage($geo['width'], floor($height*$geo['width']/$width), 0, (($geo['height']-($height*$geo['width']/$width))/2));
					}
					else
					{
						$img->cropImage(ceil($width*$geo['height']/$height), $geo['height'], (($geo['width']-($width*$geo['height']/$height))/2), 0);
					}				
					
					$img->thumbnailImage($width , $height , TRUE);
					$img->writeImage($new_path);
					
				}
				catch(Exception $e)
				{
					echo 'Caught exception: ',  $e->getMessage(), '\n';
				}	
			}

			//LARGE THUMBNAIL SIZE
			if($size == 'all' || $size == 'lthumbnail'){
				try
				{
					$width = 256;
					$height = 256;
					$img = new Imagick($old_path);
					$new_path = $upload_dir.'/lthumbnail/'.$this->get('fil_name');
					
					// get the current image dimensions
					$geo = $img->getImageGeometry();

					// crop the image
					if(($geo['width']/$width) < ($geo['height']/$height))
					{
						$img->cropImage($geo['width'], floor($height*$geo['width']/$width), 0, (($geo['height']-($height*$geo['width']/$width))/2));
					}
					else
					{
						$img->cropImage(ceil($width*$geo['height']/$height), $geo['height'], (($geo['width']-($width*$geo['height']/$height))/2), 0);
					}				
					
					$img->thumbnailImage($width , $height , TRUE);
					$img->writeImage($new_path);
					
				}
				catch(Exception $e)
				{
					echo 'Caught exception: ',  $e->getMessage(), '\n';
				}	
			}
			
			
			//SMALL SIZE
			if($size == 'all' || $size == 'small'){
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
			}				
			
			//MEDIUM SIZE
			if($size == 'all' || $size == 'medium'){
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
			}				

			//LARGE SIZE
			if($size == 'all' || $size == 'large'){
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
	
	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('user_id', $this->options)) {
		 	$where_clauses[] = 'fil_usr_user_id = ?';
		 	$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		} 
		if (array_key_exists('deleted', $this->options)) {
			$where_clauses[] = 'fil_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
		}	

		if (array_key_exists('source', $this->options)) {
		 	$where_clauses[] = 'fil_source = ?';
		 	$bind_params[] = array($this->options['source'], PDO::PARAM_INT);
		} 	
		
		if (array_key_exists('picture', $this->options)) {
			if($this->options['picture']){
				$where_clauses[] = '(fil_type LIKE \'image/%\')';
			}
			else{
				$where_clauses[] = '(fil_type NOT LIKE \'image%\')';
			}
		} 	

		if (array_key_exists('filename_like', $this->options)) {
			$where_clauses[] = 'fil_name ILIKE ?';
			$bind_params[] = array('%'.$this->options['filename_like'].'%', PDO::PARAM_STR);
		}		 

		if (array_key_exists('in_gallery', $this->options)) {
			$where_clauses[] = 'fil_gal_gallery_id IS ' . ($this->options['deleted'] ? 'NULL' : 'NOT NULL');
		}				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM fil_files ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM fil_files
				' . $where_clause . '
				ORDER BY ';

			if (empty($this->order_by)) {
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
			$child = new File($row->fil_file_id);
			$child->load_from_data($row, array_keys(File::$fields));
			$this->add($child);
		}
	}

}


?>
