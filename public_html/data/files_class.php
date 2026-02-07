<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
$settings = Globalvars::get_instance();
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

require_once(PathHelper::getIncludePath('data/event_sessions_class.php'));

class FileException extends SystemBaseException {}

class File extends SystemBase {	public static $prefix = 'fil';
	public static $tablename = 'fil_files';
	public static $pkey_column = 'fil_file_id';

	protected static $foreign_key_actions = [
		'fil_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
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
	    'fil_file_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'fil_name' => array('type'=>'varchar(255)'),
	    'fil_title' => array('type'=>'varchar(255)'),
	    'fil_description' => array('type'=>'text'),
	    'fil_type' => array('type'=>'varchar(128)'),
	    'fil_usr_user_id' => array('type'=>'int4'),
	    'fil_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'fil_delete_time' => array('type'=>'timestamp(6)'),
	    'fil_min_permission' => array('type'=>'int2'),
	    'fil_grp_group_id' => array('type'=>'int4'),
	    'fil_evt_event_id' => array('type'=>'int4'),
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

	/**
	 * Get URL for a specific image size
	 *
	 * @param string $size_key Size key from ImageSizeRegistry, or 'original' for full size
	 * @param string $format 'short' for relative URL, 'full' for absolute URL
	 * @return string
	 */
	function get_url($size_key='original', $format='short') {

		$settings = Globalvars::get_instance();
		$upload_web_dir = $settings->get_setting('upload_web_dir');

		if ($size_key === 'original') {
			$file_path = $upload_web_dir . '/' . $this->get('fil_name');
		} else {
			$file_path = $upload_web_dir . '/' . $size_key . '/' . $this->get('fil_name');
		}

		// Ensure leading slash
		if ($file_path[0] !== '/') {
			$file_path = '/' . $file_path;
		}

		if ($format == 'full') {
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

		// Clean up all entity_photos rows referencing this file
		$dbconnector = DbConnector::get_instance();
		$dblink = $dbconnector->get_db_link();
		$sql = "DELETE FROM eph_entity_photos WHERE eph_fil_file_id = ?";
		try {
			$q = $dblink->prepare($sql);
			$q->execute([$this->key]);
		} catch (PDOException $e) {
			// Table may not exist yet during initial setup
			error_log('EntityPhoto cleanup on file delete: ' . $e->getMessage());
		}

		parent::permanent_delete($debug);
		return true;
	}
	
	/**
	 * Delete resized versions of this image
	 *
	 * @param string $size_key Specific size key to delete, or 'all' for all sizes
	 */
	function delete_resized($size_key = 'all'){
		if (!$this->is_image()) {
			return false;
		}

		$settings = Globalvars::get_instance();
		$upload_dir = $settings->get_setting('upload_dir');

		require_once(PathHelper::getIncludePath('includes/ImageSizeRegistry.php'));
		$sizes = ImageSizeRegistry::get_sizes();

		foreach ($sizes as $key => $config) {
			if ($size_key !== 'all' && $size_key !== $key) {
				continue;
			}
			$file_path = $upload_dir . '/' . $key . '/' . $this->get('fil_name');
			if (file_exists($file_path)) {
				@unlink($file_path);
			}
		}
	}
	
	/**
	 * Generate resized versions using ImageSizeRegistry
	 *
	 * @param string $size_key Specific size key to generate, or 'all' for all registered sizes
	 */
	function resize($size_key='all'){
		if (!$this->is_image()) {
			return false;
		}

		$settings = Globalvars::get_instance();
		$upload_dir = $settings->get_setting('upload_dir');
		$old_path = $upload_dir . '/' . $this->get('fil_name');

		require_once(PathHelper::getIncludePath('includes/ImageSizeRegistry.php'));
		$sizes = ImageSizeRegistry::get_sizes();

		// Ensure all resize subdirectories exist
		foreach ($sizes as $key => $config) {
			if ($size_key !== 'all' && $size_key !== $key) {
				continue;
			}
			$dir_path = $upload_dir . '/' . $key;
			if (!is_dir($dir_path)) {
				if (!mkdir($dir_path, 0777, true)) {
					error_log("Failed to create resize directory: $dir_path");
				}
			}
		}

		foreach ($sizes as $key => $config) {
			if ($size_key !== 'all' && $size_key !== $key) {
				continue;
			}
			$new_path = $upload_dir . '/' . $key . '/' . $this->get('fil_name');
			$this->generate_resized($old_path, $new_path, $config['width'], $config['height'], $config['crop'], $config['quality']);
		}
	}

	/**
	 * Generate a single resized version of an image
	 *
	 * @param string $old_path Source image path
	 * @param string $new_path Destination path
	 * @param int $width Target width (0 = auto from height)
	 * @param int $height Target height (0 = auto from width)
	 * @param bool $crop Whether to center-crop to exact dimensions
	 * @param int $quality JPEG quality (1-100)
	 */
	private function generate_resized($old_path, $new_path, $width, $height, $crop, $quality = 85) {
		try {
			$img = new Imagick($old_path);
			$img->setImageCompressionQuality($quality);

			if ($crop && $width > 0 && $height > 0) {
				// Center crop then resize to exact dimensions
				$geo = $img->getImageGeometry();

				if (($geo['width'] / $width) < ($geo['height'] / $height)) {
					$img->cropImage(
						$geo['width'],
						floor($height * $geo['width'] / $width),
						0,
						(($geo['height'] - ($height * $geo['width'] / $width)) / 2)
					);
				} else {
					$img->cropImage(
						ceil($width * $geo['height'] / $height),
						$geo['height'],
						(($geo['width'] - ($width * $geo['height'] / $height)) / 2),
						0
					);
				}

				$img->thumbnailImage($width, $height, true);
			} else {
				// Aspect-fit resize within bounds
				if ($width > 0 && $height > 0) {
					$img->thumbnailImage($width, $height, true);
				} elseif ($width > 0) {
					// Width only - auto-calculate height
					$img->thumbnailImage($width, 0, false);
				} elseif ($height > 0) {
					// Height only - auto-calculate width
					$img->thumbnailImage(0, $height, false);
				}
			}

			$img->writeImage($new_path);
		} catch (Exception $e) {
			error_log('File resize generation failed for ' . basename($new_path) . ': ' . $e->getMessage());
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
			require_once(PathHelper::getIncludePath('data/groups_class.php'));
			//CHECK TO SEE IF USER IS IN AUTHORIZED GROUP
			$group = new Group($group_id, TRUE);
			if(!$group->is_member_in_group($session->get_user_id())){
				return false;
			}
		}
		
		if ($event_id = $this->get('fil_evt_event_id')){
			require_once(PathHelper::getIncludePath('data/event_registrants_class.php'));
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
			$items[$file->key] = '('.$file->key.') '.$file->get('fil_title');
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;
	}

	function get_image_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $file) {
			$items[$file->key] = '<span class="dropimagewidth"><img loading="lazy" src="'.$file->get_url('avatar').'"></span>('.$file->key.') '.$file->get('fil_title');
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

		return $this->_get_resultsv2('fil_files', $filters, $this->order_by, $only_count, $debug);
	}

}

?>
