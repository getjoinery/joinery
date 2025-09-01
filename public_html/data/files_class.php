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

PathHelper::requireOnce('data/event_sessions_class.php');

class FileException extends SystemClassException {}

class File extends SystemBase {	public static $prefix = 'fil';
	public static $tablename = 'fil_files';
	public static $pkey_column = 'fil_file_id';
	public static $permanent_delete_actions = array(		'esf_fil_file_id' => 'prevent',
		'evt_fil_file_id' => 'prevent',
		'mlt_fil_file_id' => 'null'
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

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
	    'fil_file_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'fil_name' => array('type'=>'varchar(255)'),
	    'fil_title' => array('type'=>'varchar(255)'),
	    'fil_description' => array('type'=>'text'),
	    'fil_type' => array('type'=>'varchar(128)'),
	    'fil_usr_user_id' => array('type'=>'int4'),
	    'fil_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'fil_delete_time' => array('type'=>'timestamp(6)'),
	    'fil_gal_gallery_id' => array('type'=>'int4'),
	    'fil_min_permission' => array('type'=>'int2'),
	    'fil_grp_group_id' => array('type'=>'int4'),
	    'fil_evt_event_id' => array('type'=>'int4'),
	);

	public static $field_constraints = array();

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
		
		if($format == 'full'){
			return LibraryFunctions::get_absolute_url($file_path);
		} else {
			return $file_path;
		}
		
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

	function authenticate_read($data=NULL){
		if(isset($data['session'])){
			$session = $data['session'];
		}
		else{
			SystemDisplayablePermanentError("Session is not present to authenticate.");
		}		
		
		if($this->get('fil_delete_time')){
			return false;
		}

		if($this->get('fil_min_permission')){
			if (!$session->get_permission()) {
				return false;
			}
			if ($session->get_permission() < $this->get('fil_min_permission')){
				return false;
			}
	
		}	
	
		if ($group_id = $this->get('fil_grp_group_id')){
			PathHelper::requireOnce('data/groups_class.php');
			//CHECK TO SEE IF USER IS IN AUTHORIZED GROUP
			$group = new Group($group_id, TRUE);
			if(!$group->is_member_in_group($session->get_user_id())){
				return false;
			}
		}
		
		if ($event_id = $this->get('fil_evt_event_id')){
			PathHelper::requireOnce('data/event_registrants_class.php');
			//CHECK TO SEE IF USER IS IN AUTHORIZED EVENT
			$searches['user_id'] = $session->get_user_id();
			$searches['event_id'] = $event_id;
			$searches['expired'] = false;
			$event_registrations = new MultiEventRegistrant(
				$searches,
				NULL, //array('event_id'=>'DESC'),
				NULL,
				NULL);
			$numeventsregistrations = $event_registrations->count_all();	

			if(!$numeventsregistrations){
				return false;
			}
		}

		return true;
	}
		
}

class MultiFile extends SystemMultiBase {
	protected static $model_class = 'File';

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
	
	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['user_id'])) {
			$filters['fil_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['group_id'])) {
			$filters['fil_grp_group_id'] = [$this->options['group_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['event_id'])) {
			$filters['fil_evt_event_id'] = [$this->options['event_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['deleted'])) {
			$filters['fil_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		if (isset($this->options['source'])) {
			$filters['fil_source'] = [$this->options['source'], PDO::PARAM_INT];
		}

		if (isset($this->options['picture'])) {
			if ($this->options['picture']) {
				$filters['fil_type'] = "LIKE 'image/%'";
			} else {
				$filters['fil_type'] = "NOT LIKE 'image%'";
			}
		}

		if (isset($this->options['filename_like'])) {
			$filters['fil_name'] = 'ILIKE \'%'.$this->options['filename_like'].'%\'';
		}

		if (isset($this->options['in_gallery'])) {
			$filters['fil_gal_gallery_id'] = $this->options['in_gallery'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('fil_files', $filters, $this->order_by, $only_count, $debug);
	}

}

?>
