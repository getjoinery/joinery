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
	 * Get the fast-serve directory path, derived from existing upload_dir setting.
	 * Public files (no permission restrictions) are served from this directory.
	 *
	 * @return string Filesystem path to static_files/uploads directory
	 */
	private static function get_fast_serve_dir() {
		$settings = Globalvars::get_instance();
		return dirname($settings->get_setting('upload_dir')) . '/static_files/uploads';
	}

	/**
	 * Determine whether this file should be in the public (fast-serve) directory.
	 * A file is public when it has no permission restrictions and is not deleted.
	 *
	 * @return bool
	 */
	function is_public() {
		if ($this->get('fil_delete_time')) return false;
		if ($this->get('fil_min_permission')) return false;
		if ($this->get('fil_grp_group_id')) return false;
		if ($this->get('fil_evt_event_id')) return false;
		return true;
	}

	/**
	 * Get the actual filesystem path for this file, checking both directories.
	 * Checks fast-serve directory first since most files are public.
	 *
	 * @param string $size_key Size key from ImageSizeRegistry, or 'original'
	 * @return string Filesystem path (may not exist if file is missing from disk)
	 */
	function get_filesystem_path($size_key = 'original') {
		$settings = Globalvars::get_instance();
		$filename = $this->get('fil_name');

		$dirs = [
			self::get_fast_serve_dir(),
			$settings->get_setting('upload_dir')
		];

		foreach ($dirs as $dir) {
			if ($size_key === 'original') {
				$path = $dir . '/' . $filename;
			} else {
				$path = $dir . '/' . $size_key . '/' . $filename;
			}
			if (file_exists($path)) {
				return $path;
			}
		}

		// Fallback: return expected path in normal upload_dir
		$fallback_dir = $settings->get_setting('upload_dir');
		if ($size_key === 'original') {
			return $fallback_dir . '/' . $filename;
		}
		return $fallback_dir . '/' . $size_key . '/' . $filename;
	}

	/**
	 * Move the file (and all resized versions) to the correct directory based
	 * on current permissions. Public files go to static_files/uploads/,
	 * restricted files go to uploads/.
	 *
	 * @throws FileException on duplicate filenames or move failures
	 */
	function move_to_correct_directory() {
		$settings = Globalvars::get_instance();
		$filename = $this->get('fil_name');

		$fast_dir = self::get_fast_serve_dir();
		$normal_dir = $settings->get_setting('upload_dir');

		$in_fast = file_exists($fast_dir . '/' . $filename);
		$in_normal = file_exists($normal_dir . '/' . $filename);

		// Safety check: if file exists in BOTH directories, there are duplicate
		// filenames across different records. Do not move — this would cause data loss.
		if ($in_fast && $in_normal) {
			throw new FileException("Cannot move file '$filename': duplicate filename exists in both upload directories.");
		}

		// Determine target based on permissions
		$target_dir = $this->is_public() ? $fast_dir : $normal_dir;

		// Determine source directory (where file actually is)
		$source_dir = null;
		if ($in_fast) {
			$source_dir = $fast_dir;
		} elseif ($in_normal) {
			$source_dir = $normal_dir;
		}

		if (!$source_dir || $source_dir === $target_dir) {
			return; // Already in correct location or file not found
		}

		// Ensure .htaccess exists in fast-serve directory for Tier 1 fallback
		if ($target_dir === $fast_dir) {
			$htaccess_path = $fast_dir . '/.htaccess';
			if (!file_exists($htaccess_path)) {
				if (!is_dir($fast_dir)) {
					mkdir($fast_dir, 0777, true);
				}
				file_put_contents($htaccess_path, "RewriteEngine On\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteRule ^(.*)$ /uploads/\$1 [R=302,L]\n");
			}
		}

		// Move original file
		if (!$this->move_single_file($source_dir, $target_dir, $filename)) {
			return; // Original failed to move, don't move resized versions
		}

		// Move all resized versions
		if ($this->is_image()) {
			require_once(PathHelper::getIncludePath('includes/ImageSizeRegistry.php'));
			$sizes = ImageSizeRegistry::get_sizes();
			foreach ($sizes as $key => $config) {
				$this->move_single_file(
					$source_dir . '/' . $key,
					$target_dir . '/' . $key,
					$filename
				);
			}
		}
	}

	/**
	 * Move a single file from source to target directory.
	 *
	 * @param string $source_dir Source directory
	 * @param string $target_dir Target directory
	 * @param string $filename File name
	 * @return bool True on success or if nothing to move
	 * @throws FileException on move failure or if target already exists
	 */
	private function move_single_file($source_dir, $target_dir, $filename) {
		$source = $source_dir . '/' . $filename;
		$target = $target_dir . '/' . $filename;

		if (!file_exists($source)) return true; // Nothing to move, not an error

		// Don't overwrite an existing file at the target
		if (file_exists($target)) {
			throw new FileException("Cannot move file '$filename': file already exists at target '$target'.");
		}

		// Ensure target directory exists
		if (!is_dir($target_dir)) {
			mkdir($target_dir, 0777, true);
		}

		if (!rename($source, $target)) {
			throw new FileException("Failed to move file '$filename' from '$source' to '$target'.");
		}

		return true;
	}

	/**
	 * Save the file record and move to the correct directory based on permissions.
	 */
	function save($debug = false) {
		$result = parent::save($debug);
		$this->move_to_correct_directory();
		return $result;
	}

	/**
	 * Soft delete and move to restricted directory since deleted files
	 * should not be publicly accessible.
	 */
	function soft_delete() {
		$result = parent::soft_delete();
		$this->move_to_correct_directory();
		return $result;
	}

	/**
	 * Undelete and re-evaluate which directory the file belongs in.
	 */
	function undelete() {
		$result = parent::undelete();
		$this->move_to_correct_directory();
		return $result;
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
		$file_path = $this->get_filesystem_path('original');
		if (file_exists($file_path)) {
			@unlink($file_path);
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

		require_once(PathHelper::getIncludePath('includes/ImageSizeRegistry.php'));
		$sizes = ImageSizeRegistry::get_sizes();

		foreach ($sizes as $key => $config) {
			if ($size_key !== 'all' && $size_key !== $key) {
				continue;
			}
			$file_path = $this->get_filesystem_path($key);
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

		$old_path = $this->get_filesystem_path('original');
		if (!file_exists($old_path)) {
			return false;
		}

		// Derive the base directory from where the original actually lives
		$upload_dir = dirname($old_path);

		require_once(PathHelper::getIncludePath('includes/ImageSizeRegistry.php'));
		$sizes = ImageSizeRegistry::get_sizes();

		// Ensure all resize subdirectories exist
		foreach ($sizes as $key => $config) {
			if ($size_key !== 'all' && $size_key !== $key) {
				continue;
			}
			$dir_path = $upload_dir . '/' . $key;
			if (!is_dir($dir_path)) {
				if (mkdir($dir_path, 0777, true)) {
					chmod($dir_path, 0777);
				} else {
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

			$geo = $img->getImageGeometry();
			$src_w = $geo['width'];
			$src_h = $geo['height'];

			if ($crop && $width > 0 && $height > 0) {
				// Center crop to target aspect ratio
				if (($src_w / $width) < ($src_h / $height)) {
					$img->cropImage(
						$src_w,
						floor($height * $src_w / $width),
						0,
						(($src_h - ($height * $src_w / $width)) / 2)
					);
				} else {
					$img->cropImage(
						ceil($width * $src_h / $height),
						$src_h,
						(($src_w - ($width * $src_h / $height)) / 2),
						0
					);
				}

				// Only downscale after crop, never upscale
				$cropped = $img->getImageGeometry();
				if ($cropped['width'] > $width || $cropped['height'] > $height) {
					$img->thumbnailImage($width, $height, true);
				}
			} else {
				// Aspect-fit resize — only downscale, never upscale
				if ($width > 0 && $height > 0) {
					if ($src_w > $width || $src_h > $height) {
						$img->thumbnailImage($width, $height, true);
					}
				} elseif ($width > 0) {
					if ($src_w > $width) {
						$img->thumbnailImage($width, 0, false);
					}
				} elseif ($height > 0) {
					if ($src_h > $height) {
						$img->thumbnailImage(0, $height, false);
					}
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
